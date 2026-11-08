<?php
/**
 * Генерация пересказа, ответов на вопросы и тезисов для параграфов без деталей.
 *
 * Запуск:
 *   php generate_details.php              # обработать один параграф
 *   php generate_details.php --count=10   # обработать до 10 параграфов подряд
 *   php generate_details.php --dry-run    # показать результат, ничего не записывая в БД
 *
 * Настройки читаются из переменных окружения или файла .env (см. .env.example).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

// --- Настройки ---

function loadEnvFile(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\"'");
        if (getenv($name) === false) {
            putenv("$name=$value");
        }
    }
}

function env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

// --- 1. Следующий параграф без деталей ---

function connectDb(): PDO
{
    $host = env('DB_HOST', 'localhost');
    $name = env('DB_NAME', 'books');
    $user = env('DB_USER');
    $pass = env('DB_PASS', '');
    if ($user === null) {
        throw new RuntimeException('Не задан DB_USER');
    }

    return new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/**
 * Связь с деталями такая же, как в api.php: пара (book_id, paragraph_id),
 * где paragraph_id — номер параграфа в книге.
 */
function getNextParagraph(PDO $pdo): ?array
{
    $stmt = $pdo->query(
        'SELECT p.id, p.book_id, p.paragraph_id, p.title, p.parts, p.questions
         FROM paragraphs p
         WHERE NOT EXISTS (
             SELECT 1 FROM paragraph_details pd
             WHERE pd.book_id = p.book_id AND pd.paragraph_id = p.paragraph_id
         )
         ORDER BY p.book_id, p.paragraph_id
         LIMIT 1'
    );
    $row = $stmt->fetch();
    return $row ?: null;
}

// --- 2. Промпты ---

/** parts и questions хранятся в БД как JSON; приводим их к читаемому тексту. */
function jsonToText(?string $json, bool $numbered = false): string
{
    $data = json_decode((string)$json, true);
    if (!is_array($data)) {
        return trim((string)$json);
    }
    $items = [];
    foreach ($data as $item) {
        $items[] = is_string($item) ? trim($item) : json_encode($item, JSON_UNESCAPED_UNICODE);
    }
    $items = array_values(array_filter($items, fn($s) => $s !== ''));
    if ($numbered) {
        foreach ($items as $i => $item) {
            $items[$i] = ($i + 1) . '. ' . $item;
        }
        return implode("\n", $items);
    }
    return implode("\n\n", $items);
}

const JSON_LIST_HINT = 'Верни результат строго в виде JSON-массива строк без нумерации, пояснений и markdown.';

function buildPrompts(array $paragraph): array
{
    $text = jsonToText($paragraph['parts']);
    $questions = jsonToText($paragraph['questions'], true);

    $textBlock = "\n\nТекст параграфа:\n" . $text;
    $questionsBlock = "\n\nВопросы к параграфу:\n" . $questions;

    return [
        'summary' => 'Перескажи кратко текст параграфа простыми словами для 6 класса средней школы. '
            . 'Не искажай факты и дай ответы на все вопросы к параграфу в пересказе'
            . $textBlock . $questionsBlock,
        'answers' => 'Ответь простыми словами на вопросы параграфа, используя факты из текста. '
            . 'Дай ровно один ответ на каждый вопрос в порядке вопросов. ' . JSON_LIST_HINT
            . $textBlock . $questionsBlock,
        'main_points' => 'Кратко сформулируй основные тезисы и даты из параграфа. ' . JSON_LIST_HINT
            . $textBlock,
    ];
}

// --- 3. Запросы к нейросети ---

function httpPostJson(string $url, array $headers, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("Ошибка запроса к LLM: $error");
    }
    $data = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        throw new RuntimeException("LLM вернула HTTP $status: " . mb_substr($body, 0, 500));
    }
    return $data;
}

function askDeepSeek(string $prompt): string
{
    $apiKey = env('DEEPSEEK_API_KEY');
    if ($apiKey === null) {
        throw new RuntimeException('Не задан DEEPSEEK_API_KEY');
    }
    $baseUrl = rtrim(env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'), '/');

    $data = httpPostJson("$baseUrl/chat/completions", ["Authorization: Bearer $apiKey"], [
        'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'stream' => false,
    ]);
    return $data['choices'][0]['message']['content'] ?? '';
}

function askGemini(string $prompt): string
{
    $apiKey = env('GEMINI_API_KEY');
    if ($apiKey === null) {
        throw new RuntimeException('Не задан GEMINI_API_KEY');
    }
    $model = env('GEMINI_MODEL', 'gemini-2.5-flash');

    $data = httpPostJson(
        "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent",
        ["x-goog-api-key: $apiKey"],
        ['contents' => [['parts' => [['text' => $prompt]]]]]
    );
    $text = '';
    foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
        $text .= $part['text'] ?? '';
    }
    return $text;
}

function askLlm(string $prompt): string
{
    $provider = strtolower(env('PARAGRAPH_LLM_PROVIDER', 'deepseek'));
    $answer = match ($provider) {
        'deepseek' => askDeepSeek($prompt),
        'gemini' => askGemini($prompt),
        default => throw new RuntimeException("Неизвестный PARAGRAPH_LLM_PROVIDER: $provider"),
    };
    $answer = trim($answer);
    if ($answer === '') {
        throw new RuntimeException('LLM вернула пустой ответ');
    }
    return $answer;
}

/** Приводит ответ модели к списку строк: сначала как JSON, иначе построчно. */
function parseList(string $answer): array
{
    $clean = trim(preg_replace('/^```(?:json)?\s*|\s*```$/u', '', trim($answer)));
    $data = json_decode($clean, true);
    if (is_array($data)) {
        $list = array_map(fn($item) => is_string($item) ? trim($item) : json_encode($item, JSON_UNESCAPED_UNICODE), $data);
    } else {
        $list = array_map(
            fn($line) => trim(preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $line)),
            preg_split('/\R/u', $clean)
        );
    }
    $list = array_values(array_filter($list, fn($s) => $s !== ''));
    if (!$list) {
        throw new RuntimeException('Не удалось получить список из ответа LLM');
    }
    return $list;
}

function generateDetails(array $paragraph): array
{
    $prompts = buildPrompts($paragraph);
    return [
        'summary' => askLlm($prompts['summary']),
        'answers' => parseList(askLlm($prompts['answers'])),
        'main_points' => parseList(askLlm($prompts['main_points'])),
    ];
}

// --- 4. Сохранение ---

function saveDetails(PDO $pdo, array $paragraph, array $details): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO paragraph_details (book_id, paragraph_id, summary, answers, main_points)
         VALUES (:book_id, :paragraph_id, :summary, :answers, :main_points)'
    );
    $stmt->execute([
        'book_id' => $paragraph['book_id'],
        'paragraph_id' => $paragraph['paragraph_id'],
        'summary' => $details['summary'],
        'answers' => json_encode($details['answers'], JSON_UNESCAPED_UNICODE),
        'main_points' => json_encode($details['main_points'], JSON_UNESCAPED_UNICODE),
    ]);
}

// --- Точка входа ---

function main(array $argv): int
{
    loadEnvFile(__DIR__ . '/.env');

    $options = getopt('', ['count::', 'dry-run']);
    $count = max(1, (int)($options['count'] ?? 1));
    $dryRun = isset($options['dry-run']);

    $pdo = connectDb();

    for ($i = 0; $i < $count; $i++) {
        $paragraph = getNextParagraph($pdo);
        if ($paragraph === null) {
            echo "Все параграфы уже обработаны\n";
            return 0;
        }

        $label = "книга {$paragraph['book_id']}, параграф {$paragraph['paragraph_id']} ({$paragraph['title']})";
        echo "Обработка: $label\n";

        try {
            $details = generateDetails($paragraph);
        } catch (Throwable $e) {
            fwrite(STDERR, "Ошибка для $label: {$e->getMessage()}\n");
            return 1;
        }

        if ($dryRun) {
            echo json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            return 0; // без записи следующий параграф был бы тем же самым
        }

        saveDetails($pdo, $paragraph, $details);
        echo "Сохранено\n";
    }
    return 0;
}

exit(main($argv));
