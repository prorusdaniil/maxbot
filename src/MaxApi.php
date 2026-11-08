<?php
declare(strict_types=1);

class MaxApiException extends RuntimeException
{
}

/** Тонкий клиент MAX Bot API (https://platform-api2.max.ru). */
class MaxApi
{
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl = 'https://platform-api2.max.ru',
        private readonly ?string $caBundle = null
    ) {
    }

    public static function fromEnv(): self
    {
        $token = env('MAX_BOT_TOKEN');
        if ($token === null) {
            throw new RuntimeException('Не задан MAX_BOT_TOKEN');
        }
        return new self($token, rtrim(env('MAX_API_URL', 'https://platform-api2.max.ru'), '/'), russianCaBundle());
    }

    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = ['Authorization: ' . $this->token, 'Content-Type: application/json'];
        $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            [$status, $response] = httpRequest($method, $url, $headers, $json, 20.0, $this->caBundle);
        } catch (HttpException $e) {
            throw new MaxApiException("MAX API $method $path: " . $e->getMessage(), 0, $e);
        }
        if ($status < 200 || $status >= 300) {
            throw new MaxApiException("MAX API $method $path вернул HTTP $status: " . mb_substr($response, 0, 300));
        }
        $data = json_decode($response, true);
        return is_array($data) ? $data : [];
    }

    public function sendMessage(int $chatId, string $text, ?array $keyboard = null): void
    {
        $body = ['text' => $text];
        if ($keyboard !== null) {
            $body['attachments'] = [$keyboard];
        }
        $this->request('POST', '/messages', ['chat_id' => $chatId], $body);
    }

    /** Подтверждает нажатие кнопки всплывающим уведомлением. */
    public function answerCallback(string $callbackId, string $notification): void
    {
        $this->request('POST', '/answers', ['callback_id' => $callbackId], ['notification' => $notification]);
    }

    /** Меняет сообщение с кнопкой и заодно подтверждает callback; пустой $attachments убирает клавиатуру. */
    public function editCallbackMessage(string $callbackId, string $text, array $attachments): void
    {
        $this->request('POST', '/answers', ['callback_id' => $callbackId], [
            'message' => ['text' => $text, 'attachments' => $attachments],
        ]);
    }
}

/** Inline-клавиатуры бота. */
final class Keyboards
{
    private static function button(string $text, string $payload): array
    {
        return ['type' => 'callback', 'text' => $text, 'payload' => $payload];
    }

    /** @param list<list<array>> $rows */
    private static function keyboard(array $rows): array
    {
        return ['type' => 'inline_keyboard', 'payload' => ['buttons' => $rows]];
    }

    /** @param array<int,string> $books */
    public static function books(array $books): array
    {
        $rows = [[self::button('Случайный факт', 'random_fact')]];
        foreach ($books as $bookId => $title) {
            $rows[] = [self::button($title, "book:$bookId")];
        }
        return self::keyboard($rows);
    }

    public static function menu(): array
    {
        $rows = [];
        foreach (Books::FEATURES as $key => $feature) {
            $rows[] = [self::button($feature['button'], "feature:$key")];
        }
        $rows[] = [self::button('Тест по теме', 'quiz')];
        $rows[] = [self::button('Обсудить параграф', 'discuss')];
        return self::keyboard($rows);
    }

    public static function backToMenu(): array
    {
        return self::keyboard([[self::button('В меню', 'menu')]]);
    }

    public static function quiz(Quiz $quiz): array
    {
        $row = [];
        foreach (array_keys($quiz->options) as $index) {
            $mark = in_array($index, $quiz->selected, true) ? '☑' : '☐';
            $row[] = self::button($mark . ' ' . ($index + 1), "quiz:toggle:$index");
        }
        return self::keyboard([$row, [self::button('Проверить', 'quiz:check')]]);
    }
}
