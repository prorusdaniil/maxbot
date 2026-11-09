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

require __DIR__ . '/src/bootstrap.php';

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

// --- 2. Запросы к нейросети ---

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

/** Системный промпт берётся из prompts/<имя>.txt, а в ИИ уходят текст параграфа и вопросы. */
function askLlm(LlmClient $llm, string $promptName, array $paragraph, bool $withQuestions): string
{
    $content = "Текст параграфа:\n" . jsonToText($paragraph['parts']);
    if ($withQuestions) {
        $content .= "\n\nВопросы к параграфу:\n" . jsonToText($paragraph['questions'], true);
    }
    return $llm->complete(Prompts::get($promptName), $content);
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

function generateDetails(LlmClient $llm, array $paragraph): array
{
    return [
        'summary' => askLlm($llm, 'summary', $paragraph, true),
        'answers' => parseList(askLlm($llm, 'answers', $paragraph, true)),
        'main_points' => parseList(askLlm($llm, 'main_points', $paragraph, false)),
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
    $options = getopt('', ['count::', 'dry-run']);
    $count = max(1, (int)($options['count'] ?? 1));
    $dryRun = isset($options['dry-run']);

    $pdo = connectDb();
    $llm = createLlmClient();

    for ($i = 0; $i < $count; $i++) {
        $paragraph = getNextParagraph($pdo);
        if ($paragraph === null) {
            echo "Все параграфы уже обработаны\n";
            return 0;
        }

        $label = "книга {$paragraph['book_id']}, параграф {$paragraph['paragraph_id']} ({$paragraph['title']})";
        echo "Обработка: $label\n";

        try {
            $details = generateDetails($llm, $paragraph);
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
