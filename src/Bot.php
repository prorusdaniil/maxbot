<?php
declare(strict_types=1);

/** Обработчик обновлений MAX: сообщения, нажатия кнопок и «Начать». */
final class Bot
{
    // Лимит MAX на текст сообщения — 4000 символов, берём с запасом.
    private const MAX_MESSAGE_LENGTH = 3900;

    // MAX отклоняет подтверждение callback без message и без notification (ошибка 400).
    private const DEFAULT_ACK = 'Секунду…';

    // Вопросы короче этого считаются не по теме и в нейросеть не отправляются.
    private const MIN_QUESTION_LENGTH = 20;

    // Сколько подряд вопросов не по теме допускается, дальше лимит на день становится нулевым.
    private const MAX_OFF_TOPIC_STREAK = 3;

    private ?LlmClient $llm = null;

    /** Дневной лимит вопросов в обсуждении на пользователя (DISCUSS_DAILY_LIMIT в .env). */
    private static function discussDailyLimit(): int
    {
        return max(0, (int)env('DISCUSS_DAILY_LIMIT', '10'));
    }

    /** @param callable():LlmClient $llmFactory может бросить LlmConfigurationError */
    public function __construct(
        private readonly MaxApi $api,
        private readonly Books $books,
        private readonly Sessions $sessions,
        private $llmFactory
    ) {
    }

    /** @return array{0:int,1:int}|null [chat_id, user_id] или null, если обновление нас не касается */
    public static function extractIds(array $update): ?array
    {
        switch ($update['update_type'] ?? '') {
            case 'message_created':
                $chatId = $update['message']['recipient']['chat_id'] ?? null;
                $userId = $update['message']['sender']['user_id'] ?? null;
                break;
            case 'message_callback':
                $chatId = $update['message']['recipient']['chat_id'] ?? $update['chat_id'] ?? null;
                $userId = $update['callback']['user']['user_id'] ?? null;
                break;
            case 'bot_started':
                $chatId = $update['chat_id'] ?? null;
                $userId = $update['user']['user_id'] ?? null;
                break;
            default:
                return null;
        }
        return is_int($chatId) && is_int($userId) ? [$chatId, $userId] : null;
    }

    /** Username (или имя) пользователя MAX из обновления. */
    public static function extractUserName(array $update): ?string
    {
        $user = match ($update['update_type'] ?? '') {
            'message_created' => $update['message']['sender'] ?? [],
            'message_callback' => $update['callback']['user'] ?? [],
            'bot_started' => $update['user'] ?? [],
            default => [],
        };
        $name = $user['username'] ?? $user['name'] ?? $user['first_name'] ?? null;
        return is_string($name) && $name !== '' ? $name : null;
    }

    public function handleUpdate(array $update): void
    {
        $ids = self::extractIds($update);
        if ($ids === null) {
            return;
        }
        [$chatId, $userId] = $ids;
        setLogUser($userId, self::extractUserName($update));

        // Сообщения не ждут блокировку: пока готовится ответ, новые вопросы не принимаются.
        $isMessage = $update['update_type'] === 'message_created';
        if (!$this->sessions->lock($userId, $isMessage ? 0 : 60)) {
            $this->sendText($chatId, 'Подожди, я ещё отвечаю на предыдущий вопрос.');
            return;
        }
        try {
            match ($update['update_type']) {
                'message_created' => $this->onMessage($update, $userId, $chatId),
                'message_callback' => $this->onCallback($update, $userId, $chatId),
                'bot_started' => $this->onBotStarted($userId, $chatId),
            };
        } finally {
            try {
                $this->sessions->flush();
            } finally {
                $this->sessions->unlock($userId);
            }
        }
    }

    // --- Сообщения ---

    private function onMessage(array $update, int $userId, int $chatId): void
    {
        if (($update['message']['sender']['is_bot'] ?? false) === true) {
            return;
        }
        $text = trim((string)($update['message']['body']['text'] ?? ''));
        if ($text === '') {
            return;
        }

        // Поддерживает /start и /start@имя_бота.
        $command = explode('@', preg_split('/\s+/u', $text, 2)[0], 2)[0];
        if ($command === '/start') {
            $this->sendStart($userId, $chatId);
            return;
        }

        $context = $this->sessions->get($userId);
        if ($context === null || $context->stage === 'choose_book') {
            $this->sendText($chatId, 'Сначала выбери книгу командой /start.');
        } elseif ($context->stage === 'discuss') {
            $this->handleDiscussion($context, $userId, $chatId, $text);
        } else {
            $this->handleParagraphNumber($context, $userId, $chatId, $text);
        }
    }

    /** Кнопка «Начать» в MAX присылает bot_started, а не текст /start. */
    private function onBotStarted(int $userId, int $chatId): void
    {
        $this->sendStart($userId, $chatId);
    }

    /** Сбрасывает диалог и отправляет приветствие с клавиатурой выбора книги. */
    private function sendStart(int $userId, int $chatId): void
    {
        $this->sessions->set($userId, UserContext::restart($this->sessions->get($userId), 'choose_book'));
        try {
            $books = $this->books->fetchBooks();
        } catch (HttpException | BookApiError $e) {
            logException('Не удалось загрузить список книг', $e);
            $this->sendText($chatId, 'Не удалось загрузить список книг. Попробуй /start позже.');
            return;
        }
        $this->sendText($chatId, Keyboards::config()['start']['text'], Keyboards::books($books));
    }

    private function handleParagraphNumber(UserContext $context, int $userId, int $chatId, string $text): void
    {
        if (!ctype_digit($text) || strlen($text) > 9 || (int)$text <= 0) {
            $this->sendText($chatId, 'Введи номер параграфа — положительное целое число.');
            return;
        }
        $paragraphId = (int)$text;

        if ($context->bookId === null) {
            botLog('error', "У контекста пользователя $userId нет выбранной книги");
            $this->sessions->set($userId, UserContext::restart($this->sessions->get($userId), 'choose_book'));
            $this->sendText($chatId, 'Не удалось определить книгу. Начни заново командой /start.');
            return;
        }

        try {
            [$title] = $this->books->fetchParagraph($context->bookId, $paragraphId);
        } catch (HttpException | BookApiError $e) {
            logException("Не удалось загрузить параграф $paragraphId книги {$context->bookId}", $e);
            $this->sendText($chatId, 'Не удалось загрузить параграф. Проверь номер и попробуй ещё раз.');
            return;
        }

        $context->paragraphId = $paragraphId;
        $context->quiz = null;
        $context->stage = 'ready';
        $this->sendMenu(
            $chatId,
            strtr(Keyboards::config()['paragraph']['text'], ['{title}' => $title])
        );
    }

    private function handleDiscussion(UserContext $context, int $userId, int $chatId, string $question): void
    {
        $paragraph = $this->loadParagraph($context, $userId, $chatId);
        if ($paragraph === null) {
            return;
        }
        $context->rollDiscussDay();
        if ($context->discussCount >= self::discussDailyLimit() || $context->offTopicStreak > self::MAX_OFF_TOPIC_STREAK) {
            $this->sendBackToMenu($chatId, 'Лимит вопросов на сегодня исчерпан. Воспользуйся кнопками пересказа или возвращайся завтра.');
            return;
        }
        if (mb_strlen($question) < self::MIN_QUESTION_LENGTH) {
            $context->offTopicStreak++;
            $this->sendBackToMenu($chatId, 'Вопрос не по теме параграфа, либо слишком общий. Воспользуйтесь кнопкой полного пересказа');
            return;
        }
        $this->sendText($chatId, 'Думаю…');
        $usageBefore = $this->usage();
        try {
            $answer = answerQuestion($this->llm(), $paragraph[1], $question);
        } catch (LlmError $e) {
            $this->sendLlmError($chatId, $e, $userId);
            return;
        }
        $context->discussCount++;
        $isOffTopic = preg_match('/^\W*(вопрос не касается темы|слишком общий вопрос)/ui', $answer) === 1;
        $context->offTopicStreak = $isOffTopic ? $context->offTopicStreak + 1 : 0;
        statsLog('discuss', $context->bookId, $context->paragraphId, $this->usageSince($usageBefore));
        $this->sendLongText($chatId, $answer);
        $this->sendBackToMenu($chatId, 'Задай ещё вопрос или вернись в меню.');
    }

    // --- Кнопки ---

    private function onCallback(array $update, int $userId, int $chatId): void
    {
        $callbackId = (string)($update['callback']['callback_id'] ?? '');
        $payload = (string)($update['callback']['payload'] ?? '');
        $context = $this->sessions->get($userId);

        if (str_starts_with($payload, 'quiz:') && $context !== null && $context->stage === 'ready') {
            $this->handleQuizCallback($callbackId, $context, $userId, $chatId, $payload);
            return;
        }

        $this->ack($callbackId, self::DEFAULT_ACK);

        if (str_starts_with($payload, 'book:')) {
            $this->handleBook($userId, $chatId, $payload);
            return;
        }
        if ($payload === 'random_fact') {
            $this->handleRandomFact($chatId);
            return;
        }

        if ($context === null || !in_array($context->stage, ['ready', 'discuss'], true) || $context->paragraphId === null) {
            $this->sendText($chatId, 'Сначала загрузи параграф: введи /start и выбери книгу.');
            return;
        }

        if ($payload === 'menu') {
            $context->stage = 'ready';
            $this->sendMenu($chatId, 'Выбери действие или введи номер другого параграфа:');
        } elseif (str_starts_with($payload, 'feature:')) {
            $context->stage = 'ready';
            $this->handleFeature($context, $userId, $chatId, substr($payload, strlen('feature:')));
        } elseif ($payload === 'quiz') {
            $context->stage = 'ready';
            $this->startQuiz($context, $userId, $chatId);
        } elseif ($payload === 'discuss') {
            $paragraph = $this->loadParagraph($context, $userId, $chatId);
            if ($paragraph === null) {
                return;
            }
            $context->stage = 'discuss';
            $this->sendBackToMenu($chatId, "Параграф «{$paragraph[0]}». Напиши свой вопрос по нему:");
        } else {
            botLog('warning', 'Неизвестный payload кнопки: ' . json_encode($payload, JSON_UNESCAPED_UNICODE));
        }
    }

    private function handleBook(int $userId, int $chatId, string $payload): void
    {
        $rawId = substr($payload, strlen('book:'));
        if (!preg_match('/^-?\d+$/', $rawId)) {
            botLog('warning', 'Неверный payload книги: ' . $payload);
            $this->sendText($chatId, 'Не удалось определить книгу. Введи /start.');
            return;
        }
        $bookId = (int)$rawId;

        try {
            $books = $this->books->fetchBooks();
        } catch (HttpException | BookApiError $e) {
            logException('Не удалось загрузить список книг', $e);
            $this->sendText($chatId, 'Не удалось загрузить список книг. Попробуй /start позже.');
            return;
        }
        if (!isset($books[$bookId])) {
            botLog('warning', "Неизвестный id книги в callback: $bookId");
            $this->sendText($chatId, 'Эта книга недоступна. Введи /start.');
            return;
        }

        $this->sessions->set($userId, UserContext::restart($this->sessions->get($userId), 'enter_paragraph', $bookId));
        $this->sendText($chatId, strtr(Keyboards::config()['book']['text'], ['{book}' => $books[$bookId]]));
    }

    private function handleFeature(UserContext $context, int $userId, int $chatId, string $feature): void
    {
        if (!isset(Books::FEATURES[$feature]) || $context->bookId === null || $context->paragraphId === null) {
            botLog('warning', "Неизвестная функция '$feature' или неполный контекст пользователя $userId");
            return;
        }

        try {
            $text = $this->books->fetchFeatureText($context->bookId, $context->paragraphId, $feature);
        } catch (HttpException | BookApiError $e) {
            logException("Не удалось получить $feature для пользователя $userId", $e);
            $this->sendBackToMenu($chatId, 'Не удалось получить данные от API. Попробуй позже.');
            return;
        }

        statsLog($feature, $context->bookId, $context->paragraphId);
        $this->sendLongText($chatId, $text);
        $this->sendMenu($chatId, 'Что дальше?');
    }

    private function handleRandomFact(int $chatId): void
    {
        try {
            $text = $this->books->fetchRandomFact();
        } catch (HttpException | BookApiError $e) {
            logException('Не удалось получить случайный факт', $e);
            $this->sendText($chatId, 'Не удалось получить случайный факт. Попробуй позже.');
            return;
        }
        statsLog('random_fact');
        $this->sendText($chatId, $text);
    }

    // --- Тест ---

    private function startQuiz(UserContext $context, int $userId, int $chatId): void
    {
        $paragraph = $this->loadParagraph($context, $userId, $chatId);
        if ($paragraph === null) {
            return;
        }
        $this->sendText($chatId, 'Составляю тест…');
        $usageBefore = $this->usage();
        try {
            $quiz = Quiz::generate($this->llm(), $paragraph[1]);
        } catch (QuizGenerationError $e) {
            logException("ИИ вернул неверный тест для пользователя $userId", $e);
            $this->sendBackToMenu($chatId, 'Не удалось составить тест. Попробуй ещё раз.');
            return;
        } catch (LlmError $e) {
            $this->sendLlmError($chatId, $e, $userId);
            return;
        }

        statsLog('quiz', $context->bookId, $context->paragraphId, $this->usageSince($usageBefore));
        $context->quiz = $quiz;
        $this->sendText($chatId, $quiz->format($paragraph[0]), Keyboards::quiz($quiz));
    }

    private function handleQuizCallback(string $callbackId, UserContext $context, int $userId, int $chatId, string $payload): void
    {
        $quiz = $context->quiz;
        if ($quiz === null || $quiz->finished) {
            $this->ack($callbackId, 'Этот тест уже завершён.');
            return;
        }

        $paragraph = $this->loadParagraph($context, $userId, $chatId);
        if ($paragraph === null) {
            $this->ack($callbackId, 'Не удалось загрузить параграф. Попробуй позже.');
            return;
        }
        $title = $paragraph[0];

        // Правка сообщения сама подтверждает callback, поэтому ack вызывается только в ветках без неё.
        if (str_starts_with($payload, 'quiz:toggle:')) {
            $rawIndex = substr($payload, strlen('quiz:toggle:'));
            $index = preg_match('/^\d+$/', $rawIndex) ? (int)$rawIndex : -1;
            if ($index < 0 || $index >= count($quiz->options)) {
                $this->ack($callbackId, self::DEFAULT_ACK);
                return;
            }
            $quiz->toggle($index);
            $this->editMessage(
                $callbackId,
                $quiz->format($title),
                [Keyboards::quiz($quiz)]
            );
            return;
        }

        if (!$quiz->selected) {
            $this->ack($callbackId, 'Сначала выбери хотя бы один вариант.');
            return;
        }

        $quiz->finished = true;
        $this->editMessage($callbackId, $quiz->format($title, true), []);
        $this->sendMenu($chatId, 'Что дальше?');
    }

    // --- Отправка ---

    /** Заголовок и текст параграфа всегда берутся из API книг, в сессии они не хранятся. @return array{0:string,1:string}|null */
    private function loadParagraph(UserContext $context, int $userId, int $chatId): ?array
    {
        if ($context->bookId === null || $context->paragraphId === null) {
            return null;
        }
        try {
            return $this->books->fetchParagraph($context->bookId, $context->paragraphId);
        } catch (HttpException | BookApiError $e) {
            logException("Не удалось загрузить параграф {$context->paragraphId} книги {$context->bookId}", $e);
            $this->sendBackToMenu($chatId, 'Не удалось загрузить параграф. Попробуй позже.');
            return null;
        }
    }

    private function usage(): array
    {
        return $this->llm !== null ? $this->llm->usage() : ['input' => 0, 'cached' => 0, 'output' => 0];
    }

    private function usageSince(array $before): array
    {
        $now = $this->usage();
        return array_map(fn($k) => $now[$k] - $before[$k], array_combine(array_keys($now), array_keys($now)));
    }

    private function llm(): LlmClient
    {
        return $this->llm ??= ($this->llmFactory)();
    }

    private function sendText(int $chatId, string $text, ?array $keyboard = null): void
    {
        $this->api->sendMessage($chatId, $text, $keyboard);
    }

    private function sendMenu(int $chatId, string $text): void
    {
        $this->sendText($chatId, $text, Keyboards::menu());
    }

    private function sendBackToMenu(int $chatId, string $text): void
    {
        $this->sendText($chatId, $text, Keyboards::backToMenu());
    }

    /** Отправляет большие ответы несколькими сообщениями в пределах лимита MAX. */
    private function sendLongText(int $chatId, string $text): void
    {
        foreach (mb_str_split($text, self::MAX_MESSAGE_LENGTH, 'UTF-8') as $index => $chunk) {
            if ($index > 0) {
                usleep(550000);
            }
            $this->sendText($chatId, $chunk);
        }
    }

    private function sendLlmError(int $chatId, LlmError $error, int $userId): void
    {
        if ($error instanceof LlmConfigurationError) {
            botLog('error', $error->getMessage());
            $text = 'Для этой функции настрой LLM (DEEPSEEK_API_KEY) в файле .env.';
        } else {
            logException("Запрос к ИИ не удался для пользователя $userId", $error);
            $text = 'Не удалось получить ответ от ИИ. Попробуй ещё раз позже.';
        }
        $this->sendBackToMenu($chatId, $text);
    }

    // Сбой подтверждения (например, повторный callback) не должен прерывать обработку.
    private function ack(string $callbackId, string $notification): void
    {
        try {
            $this->api->answerCallback($callbackId, $notification);
        } catch (MaxApiException $e) {
            logException('Не удалось подтвердить callback', $e);
        }
    }

    private function editMessage(string $callbackId, string $text, array $attachments): void
    {
        try {
            $this->api->editCallbackMessage($callbackId, $text, $attachments);
        } catch (MaxApiException $e) {
            logException('Не удалось изменить сообщение с тестом', $e);
        }
    }
}
