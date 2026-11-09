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
        public ?Quiz $quiz = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'book_id' => $this->bookId,
            'paragraph_id' => $this->paragraphId,
            'quiz' => $this->quiz?->toArray(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['stage'] ?? 'choose_book'),
            isset($data['book_id']) ? (int)$data['book_id'] : null,
            isset($data['paragraph_id']) ? (int)$data['paragraph_id'] : null,
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
        $pdo = db();
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
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS stats (
                created_at DATETIME NOT NULL,
                user_id BIGINT NOT NULL,
                user_name VARCHAR(255) NULL,
                book_id INT NULL,
                paragraph_id INT NULL,
                feature VARCHAR(32) NOT NULL,
                input_tokens INT NOT NULL DEFAULT 0,
                cached_tokens INT NOT NULL DEFAULT 0,
                output_tokens INT NOT NULL DEFAULT 0
            )' . $suffix
        );
        foreach (['input_tokens', 'cached_tokens', 'output_tokens'] as $column) {
            try {
                $this->pdo->exec("ALTER TABLE stats ADD COLUMN $column INT NOT NULL DEFAULT 0");
            } catch (PDOException $e) {
                // колонка уже есть
            }
        }
        try {
            // Старые строки хранили только сумму: переносим её во входящие, затем убираем колонку.
            $this->pdo->exec('UPDATE stats SET input_tokens = tokens_used WHERE input_tokens = 0 AND cached_tokens = 0 AND output_tokens = 0');
            $this->pdo->exec('ALTER TABLE stats DROP COLUMN tokens_used');
        } catch (PDOException $e) {
            // колонки уже нет
        }
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
