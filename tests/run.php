<?php
declare(strict_types=1);

/**
 * Проверка логики бота без сети и MySQL: php tests/run.php
 * MAX API, API книг и LLM подменены, состояние хранится в SQLite в памяти.
 */

require __DIR__ . '/../src/bootstrap.php';

putenv('BOT_LOG_FILE=' . sys_get_temp_dir() . '/maxbot_test.log');

$failures = 0;
function check(string $name, bool $condition, string $details = ''): void
{
    global $failures;
    echo ($condition ? '  ok   ' : '  FAIL ') . $name . ($condition || $details === '' ? '' : " — $details") . "\n";
    if (!$condition) {
        $failures++;
    }
}

class FakeApi extends MaxApi
{
    /** @var list<array{string,string,array,?array}> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct('test-token');
    }

    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $this->calls[] = [$method, $path, $query, $body];
        return [];
    }

    public function reset(): void
    {
        $this->calls = [];
    }

    /** @return list<array> тела отправленных сообщений */
    public function sent(): array
    {
        return array_values(array_map(fn($c) => $c[3], array_filter($this->calls, fn($c) => $c[1] === '/messages')));
    }

    public function lastText(): string
    {
        $sent = $this->sent();
        return $sent ? end($sent)['text'] : '';
    }
}

class FakeBooks extends Books
{
    public bool $fail = false;
    public string $featureText = '';

    public function __construct()
    {
        parent::__construct('', '', '');
    }

    public function fetchBooks(): array
    {
        if ($this->fail) {
            throw new HttpException('HTTP 500', 500);
        }
        return [3 => 'История', 5 => 'Биология'];
    }

    public function fetchParagraph(int $bookId, int $paragraphId): array
    {
        if ($paragraphId === 404) {
            throw new HttpException('HTTP 404', 404);
        }
        return ["Параграф $paragraphId", 'Текст параграфа.'];
    }

    public function fetchFeatureText(int $bookId, int $paragraphId, string $feature): string
    {
        return $this->featureText !== '' ? $this->featureText : "feature $feature $bookId/$paragraphId";
    }

    public function fetchRandomFact(): string
    {
        return 'Случайный факт о Ком-то:\nфакт';
    }
}

class FakeLlm implements LlmClient
{
    public string $reply = '';
    public array $requests = [];

    public function usage(): array
    {
        return ['input' => 0, 'cached' => 0, 'output' => 0];
    }

    public function complete(string $systemPrompt, string $userContent, ?int $maxTokens = null): string
    {
        $this->requests[] = [$systemPrompt, $userContent];
        return $this->reply;
    }
}

function message(int $user, string $text): array
{
    return [
        'update_type' => 'message_created',
        'message' => [
            'sender' => ['user_id' => $user, 'is_bot' => false],
            'recipient' => ['chat_id' => $user + 1000, 'chat_type' => 'dialog'],
            'body' => ['text' => $text],
        ],
    ];
}

function callback(int $user, string $payload): array
{
    return [
        'update_type' => 'message_callback',
        'callback' => ['callback_id' => "cb-$payload", 'payload' => $payload, 'user' => ['user_id' => $user]],
        'message' => ['recipient' => ['chat_id' => $user + 1000, 'chat_type' => 'dialog']],
    ];
}

function buttons(array $sentMessage): array
{
    $payloads = [];
    foreach ($sentMessage['attachments'][0]['payload']['buttons'] ?? [] as $row) {
        foreach ($row as $button) {
            $payloads[] = $button['payload'];
        }
    }
    return $payloads;
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$sessions = new Sessions($pdo);
$sessions->ensureSchema();
$api = new FakeApi();
$books = new FakeBooks();
$llm = new FakeLlm();

// Каждое обновление обрабатывается новым объектом Bot и Sessions: состояние обязано жить в БД.
function run(array $update): void
{
    global $pdo, $api, $books, $llm;
    $bot = new Bot($api, $books, new Sessions($pdo), fn() => $llm);
    $bot->handleUpdate($update);
}

echo "Диалог\n";
$u = 42;
run(message($u, 'что-то'));
check('без /start просит выбрать книгу', str_contains($api->lastText(), '/start'));

$api->reset();
run(message($u, '/start@my_bot'));
$sent = $api->sent()[0];
check('/start@bot показывает книги', $sent['text'] === 'Привет! Выбери книгу:' && buttons($sent) === ['random_fact', 'book:3', 'book:5'], json_encode($sent));
check('сообщение уходит в нужный чат', $api->calls[0][2] === ['chat_id' => 1042]);

$api->reset();
run(['update_type' => 'bot_started', 'chat_id' => 1042, 'user' => ['user_id' => $u]]);
check('bot_started показывает книги', $api->lastText() === 'Привет! Выбери книгу:');

$api->reset();
run(callback($u, 'random_fact'));
check('random_fact: ack + факт', $api->calls[0][1] === '/answers' && str_starts_with($api->lastText(), 'Случайный факт'));

$api->reset();
run(callback($u, 'menu'));
check('меню без параграфа', str_contains($api->lastText(), 'Сначала загрузи параграф'));

$api->reset();
run(callback($u, 'book:999'));
check('неизвестная книга', str_contains($api->lastText(), 'недоступна'));
run(callback($u, 'book:abc'));
check('неверный id книги', str_contains($api->lastText(), 'Не удалось определить книгу'));

$api->reset();
run(callback($u, 'book:3'));
check('выбор книги', $api->lastText() === 'Выбрана книга «История». Введи номер параграфа:');

foreach (['abc', '0', '-5', '12345678901234'] as $bad) {
    $api->reset();
    run(message($u, $bad));
    check("некорректный номер '$bad'", str_contains($api->lastText(), 'положительное целое'));
}

$api->reset();
run(message($u, '404'));
check('параграф не найден', str_contains($api->lastText(), 'Не удалось загрузить параграф'));

$api->reset();
run(message($u, '7'));
$sent = $api->sent()[0];
check('параграф загружен, меню', str_contains($sent['text'], 'Параграф 7') && buttons($sent) === ['feature:summary', 'feature:answers', 'feature:theses', 'quiz', 'discuss'], json_encode($sent));

$api->reset();
run(callback($u, 'feature:theses'));
$texts = array_column($api->sent(), 'text');
check('функция: текст и меню', $texts === ['feature theses 3/7', 'Что дальше?'], json_encode($texts));

echo "Обсуждение\n";
$llm->reply = 'Ответ учителя';
$api->reset();
run(callback($u, 'discuss'));
check('режим обсуждения', str_contains($api->lastText(), 'Напиши свой вопрос'));
$api->reset();
run(message($u, 'Почему это происходит именно так?'));
$texts = array_column($api->sent(), 'text');
check('ответ ИИ', $texts === ['Думаю…', 'Ответ учителя', 'Задай ещё вопрос или вернись в меню.'], json_encode($texts));
check('в ИИ ушёл текст и вопрос', str_contains($llm->requests[0][1], 'Текст параграфа.') && str_contains($llm->requests[0][1], 'Вопрос ученика: Почему это происходит именно так?'));

$api->reset();
run(message($u, 'Почему?'));
$texts = array_column($api->sent(), 'text');
check('короткий вопрос не уходит в ИИ', count($llm->requests) === 1 && str_starts_with($texts[0], 'Вопрос не по теме'), json_encode($texts));

run(message($u, 'Почему?'));
run(message($u, 'Почему?'));
$api->reset();
run(message($u, 'Почему?'));
run(message($u, 'Почему это происходит именно так?'));
$texts = array_column($api->sent(), 'text');
check('после 4 коротких подряд лимит обнуляется', count($llm->requests) === 1 && str_starts_with($texts[1], 'Лимит вопросов'), json_encode($texts));

$api->reset();
run(callback($u, 'menu'));
check('возврат в меню', count($api->sent()) === 1);

$u2 = 43;
foreach ([message($u2, '/start'), callback($u2, 'book:3'), message($u2, '7'), callback($u2, 'discuss')] as $step) {
    run($step);
}
$llm->reply = 'Вопрос не касается темы параграфа.';
$before = count($llm->requests);
for ($i = 0; $i < 4; $i++) {
    run(message($u2, 'Расскажи про погоду в Африке?'));
}
$api->reset();
run(message($u2, 'Расскажи про погоду в Африке?'));
check('4 ответа «не по теме» обнуляют лимит', count($llm->requests) === $before + 4 && str_starts_with($api->sent()[0]['text'], 'Лимит вопросов'), json_encode($api->sent()));

echo "Тест\n";
$llm->reply = "Вот тест:\n```json\n" . json_encode([
    'question' => 'Что верно?',
    'options' => ['A', 'B', 'C', 'D'],
    'correct' => [1, 3],
], JSON_UNESCAPED_UNICODE) . "\n```";
$api->reset();
run(callback($u, 'quiz'));
$sent = $api->sent();
check('тест отправлен', $sent[0]['text'] === 'Составляю тест…' && str_contains($sent[1]['text'], 'Правильных вариантов: 2'), json_encode($sent));
check('кнопки теста', buttons($sent[1]) === ['quiz:toggle:0', 'quiz:toggle:1', 'quiz:toggle:2', 'quiz:toggle:3', 'quiz:check']);

$api->reset();
run(callback($u, 'quiz:check'));
check('проверка без выбора', $api->calls[0][3] === ['notification' => 'Сначала выбери хотя бы один вариант.']);

$api->reset();
run(callback($u, 'quiz:toggle:1'));
$edit = $api->calls[0][3]['message'] ?? null;
check('отметка варианта правит сообщение', $edit !== null && $api->calls[0][2] === ['callback_id' => 'cb-quiz:toggle:1']
    && $edit['attachments'][0]['payload']['buttons'][0][1]['text'] === '☑ 2', json_encode($api->calls[0][3]));

run(callback($u, 'quiz:toggle:0'));
run(callback($u, 'quiz:toggle:0'));
$api->reset();
run(callback($u, 'quiz:toggle:9'));
check('неверный индекс игнорируется', $api->calls[0][3] === ['notification' => 'Секунду…']);

$api->reset();
run(callback($u, 'quiz:check'));
$edit = $api->calls[0][3]['message'];
check('результат: ошибки', str_contains($edit['text'], '✅ 2. B') && str_contains($edit['text'], 'Есть ошибки. Правильные ответы: 2, 4.') && $edit['attachments'] === [], $edit['text']);
check('после проверки меню', $api->lastText() === 'Что дальше?');

$api->reset();
run(callback($u, 'quiz:toggle:2'));
check('завершённый тест', $api->calls[0][3] === ['notification' => 'Этот тест уже завершён.']);

$llm->reply = 'не json';
$api->reset();
run(callback($u, 'quiz'));
check('невалидный тест', str_contains($api->lastText(), 'Не удалось составить тест'));

echo "Прочее\n";
$books->featureText = str_repeat('я', 3900 * 2 + 5);
$api->reset();
run(callback($u, 'feature:summary'));
$lengths = array_map(fn($m) => mb_strlen($m['text']), $api->sent());
check('длинный текст режется по 3900 символов', $lengths === [3900, 3900, 5, mb_strlen('Что дальше?')], json_encode($lengths));
$books->featureText = '';

$books->fail = true;
$api->reset();
run(message($u, '/start'));
check('сбой каталога', str_contains($api->lastText(), 'Не удалось загрузить список книг'));

$api->reset();
run(['update_type' => 'message_created', 'message' => ['sender' => ['user_id' => 1, 'is_bot' => true], 'recipient' => ['chat_id' => 2], 'body' => ['text' => 'x']]]);
run(['update_type' => 'user_added', 'chat_id' => 1]);
check('ботов и чужие обновления игнорируем', $api->calls === []);

echo "Разбор данных\n";
check('parseBooks', Books::parseBooks([['bookId' => 3, 'title' => ' A '], ['bookId' => '4', 'title' => 'x']]) === [3 => 'A']);
check('parseFeatureText список', Books::parseFeatureText(['answers' => ['a', ' ', 'b']], 'answers', true) === "1. a\n\n2. b");
check('parseFeatureText пусто', Books::parseFeatureText(['summary' => '  '], 'summary', false) === null);
check('parseParagraph', Books::parseParagraph(['title' => 'T', 'parts' => [['text' => 'a'], ['text' => ' '], ['text' => 'b']]]) === ['T', "a\n\nb"]);
$threw = false;
try {
    Quiz::parse('{"question":"q","options":["a","b"],"correct":[0,1]}');
} catch (QuizGenerationError) {
    $threw = true;
}
check('тест: все варианты верные — ошибка', $threw);

$partA = str_repeat('А', 200);
$partB = str_repeat('Б', 200);
$picked = [];
for ($i = 0; $i < 50; $i++) {
    $picked[Quiz::randomPart("$partA\n\nкороткий\n\n$partB")] = true;
}
check('randomPart: берёт один длинный подпункт', !isset($picked['короткий']) && !isset($picked["$partA\n\n$partB"]) && $picked !== [], json_encode(array_keys($picked)));
check('randomPart: без длинных подпунктов — весь текст', Quiz::randomPart("a\n\nb") === "a\n\nb");

// LLM-клиент
// Лог: время, уровень, id пользователя, сообщение; по умолчанию только ERROR
$testLog = sys_get_temp_dir() . '/maxbot_format_test.log';
@unlink($testLog);
putenv("BOT_LOG_FILE=$testLog");
putenv('LOG_LEVEL');
botLog('info', 'не пишется', 42);
botLog('error', 'сбой', 42);
$logLine = (string)@file_get_contents($testLog);
check('лог: формат и уровень по умолчанию', !str_contains($logLine, 'не пишется')
    && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d ERROR user=42 username=- сбой$/', trim($logLine)) === 1, $logLine);
putenv('LOG_LEVEL=info');
botLog('info', 'пишется', 7);
check('лог: LOG_LEVEL=info', str_contains((string)file_get_contents($testLog), 'INFO user=7 username=- пишется'));
putenv('LOG_LEVEL');
putenv('BOT_LOG_FILE=' . sys_get_temp_dir() . '/maxbot_test.log');
@unlink($testLog);
check('промпты из файлов', Prompts::get('summary') !== '' && Prompts::get('quiz') !== '' && Prompts::get('discuss') !== '');

putenv('DEEPSEEK_API_KEY');
$threw = false;
try {
    createLlmClient();
} catch (LlmConfigurationError) {
    $threw = true;
}
check('нет ключей — LlmConfigurationError', $threw);
putenv('DEEPSEEK_API_KEY=k');
check('DeepSeek по ключу', createLlmClient() instanceof DeepSeekClient);
$statsDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
(new Sessions($statsDb))->ensureSchema();
$GLOBALS['__db'] = $statsDb;
setLogUser(9, 'ivan');
statsLog('quiz', 3, 12, ['input' => 5, 'cached' => 2, 'output' => 1]);
$row = $statsDb->query('SELECT user_id, user_name, book_id, paragraph_id, feature, input_tokens, cached_tokens, output_tokens FROM stats')->fetch(PDO::FETCH_NUM);
check('статистика пишется в БД', $row === ['9', 'ivan', '3', '12', 'quiz', '5', '2', '1'] || $row === [9, 'ivan', 3, 12, 'quiz', 5, 2, 1], json_encode($row));
putenv('STATS_ENABLED=false');
statsLog('quiz');
check('статистику можно отключить', (int)$statsDb->query('SELECT COUNT(*) FROM stats')->fetchColumn() === 1);
putenv('STATS_ENABLED');
setLogUser(null);
unset($GLOBALS['__db']);
putenv('DEEPSEEK_API_KEY');

echo $failures === 0 ? "\nВсе проверки пройдены\n" : "\nПровалено: $failures\n";
exit($failures === 0 ? 0 : 1);
