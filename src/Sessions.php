<?php
declare(strict_types=1);

/** Данные текущего диалога пользователя. */
final class UserContext
{
    /** @param 'choose_book'|'enter_paragraph'|'ready'|'discuss' $stage */
    public function __construct(
        public string $stage = 'choose_book',
        public ?int $bookId = null,
        public ?int $paragraphId = null,
        public string $paragraphTitle = '',
        public string $paragraphText = '',
        public ?Quiz $quiz = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'book_id' => $this->bookId,
            'paragraph_id' => $this->paragraphId,
            'paragraph_title' => $this->paragraphTitle,
            'paragraph_text' => $this->paragraphText,
            'quiz' => $this->quiz?->toArray(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['stage'] ?? 'choose_book'),
            isset($data['book_id']) ? (int)$data['book_id'] : null,
            isset($data['paragraph_id']) ? (int)$data['paragraph_id'] : null,
            (string)($data['paragraph_title'] ?? ''),
            (string)($data['paragraph_text'] ?? ''),
            isset($data['quiz']) && is_array($data['quiz']) ? Quiz::fromArray($data['quiz']) : null
        );
    }
}

/**
 * Состояние диалогов в таблице bot_sessions (PHP не хранит память между запросами).
 * Контекст загружается один раз за обновление, а flush() записывает изменения.
 */
final class Sessions
{
    /** @var array<int,?UserContext> */
    private array $loaded = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromEnv(): self
    {
        $host = env('DB_HOST', 'localhost');
        $name = env('DB_NAME', 'books');
        $user = env('DB_USER');
        if ($user === null) {
            throw new RuntimeException('Не задан DB_USER');
        }
        $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return new self($pdo);
    }

    /** Создаёт таблицу, если её нет (то же самое делает schema.sql). */
    public function ensureSchema(): void
    {
        $suffix = $this->isMysql() ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS bot_sessions (
                user_id BIGINT NOT NULL PRIMARY KEY,
                data LONGTEXT NOT NULL,
                updated_at INT NOT NULL
            )' . $suffix
        );
    }

    private function isMysql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /** Не даёт двум обновлениям одного пользователя менять состояние одновременно. */
    public function lock(int $userId, int $timeoutSeconds = 60): void
    {
        if ($this->isMysql()) {
            $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
            $stmt->execute(["maxbot_user_$userId", $timeoutSeconds]);
        }
    }

    public function unlock(int $userId): void
    {
        if ($this->isMysql()) {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(["maxbot_user_$userId"]);
        }
    }

    public function get(int $userId): ?UserContext
    {
        if (array_key_exists($userId, $this->loaded)) {
            return $this->loaded[$userId];
        }
        $stmt = $this->pdo->prepare('SELECT data FROM bot_sessions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $json = $stmt->fetchColumn();
        $data = $json === false ? null : json_decode((string)$json, true);
        return $this->loaded[$userId] = is_array($data) ? UserContext::fromArray($data) : null;
    }

    public function set(int $userId, UserContext $context): void
    {
        $this->loaded[$userId] = $context;
    }

    public function flush(): void
    {
        foreach ($this->loaded as $userId => $context) {
            if ($context === null) {
                continue;
            }
            $this->pdo->prepare('REPLACE INTO bot_sessions (user_id, data, updated_at) VALUES (?, ?, ?)')
                ->execute([$userId, json_encode($context->toArray(), JSON_UNESCAPED_UNICODE), time()]);
        }
    }
}
