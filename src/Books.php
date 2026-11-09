<?php
declare(strict_types=1);

/** Ответ API книг не содержит ожидаемых данных. */
class BookApiError extends RuntimeException
{
}

/** Клиент API книг: список книг, параграф, пересказ/ответы/тезисы, случайный факт. */
class Books
{
    /** Каждая функция обслуживается отдельным эндпоинтом; ответ: {"<endpoint>": текст | [строки]}. */
    public const FEATURES = [
        'summary' => [
            'endpoint' => 'summary',
            'list' => false,
            'missing' => 'Для этого параграфа пока нет пересказа.',
        ],
        'answers' => [
            'endpoint' => 'answers',
            'list' => true,
            'missing' => 'Для этого параграфа пока нет ответов на вопросы.',
        ],
        'theses' => [
            'endpoint' => 'main_points',
            'list' => true,
            'missing' => 'Для этого параграфа пока нет тезисов.',
        ],
    ];

    private const BOOKS_CACHE_TTL = 3600;

    public function __construct(
        private readonly string $bookApiUrl,
        private readonly string $booksApiUrl,
        private readonly string $randomFactApiUrl
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            rtrim(env('BOOK_API_URL', 'https://counters.goweb.kz/book-parser/api.php'), '/'),
            env('BOOKS_API_URL', 'https://counters.goweb.kz/book-parser/api/books'),
            env('RANDOM_FACT_API_URL', 'https://counters.goweb.kz/book-parser/api/random-fact')
        );
    }

    /**
     * Список книг [bookId => название]; кэшируется в файле, при сбое отдаётся устаревший кэш.
     *
     * @return array<int,string>
     * @throws HttpException|BookApiError
     */
    public function fetchBooks(): array
    {
        $cacheFile = sys_get_temp_dir() . '/maxbot_books_' . md5($this->booksApiUrl) . '.json';
        $cached = null;
        if (is_readable($cacheFile)) {
            $decoded = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($decoded) && isset($decoded['time'], $decoded['books']) && is_array($decoded['books'])) {
                $cached = $decoded;
            }
        }
        if ($cached !== null && time() - (int)$cached['time'] < self::BOOKS_CACHE_TTL) {
            return self::intKeys($cached['books']);
        }

        try {
            $books = self::parseBooks(httpGetJson($this->booksApiUrl));
        } catch (HttpException | BookApiError $e) {
            if ($cached === null) {
                throw $e;
            }
            logException('Не удалось обновить список книг, используется устаревший кэш', $e);
            return self::intKeys($cached['books']);
        }

        @file_put_contents($cacheFile, json_encode(['time' => time(), 'books' => $books]), LOCK_EX);
        return $books;
    }

    /**
     * Название и объединённый текст всех частей параграфа.
     *
     * @return array{0:string,1:string}
     * @throws HttpException|BookApiError
     */
    public function fetchParagraph(int $bookId, int $paragraphId): array
    {
        $data = httpGetJson($this->bookApiUrl, ['bookId' => $bookId, 'paragraphId' => $paragraphId]);
        return self::parseParagraph($data);
    }

    /**
     * Пересказ, ответы или тезисы; если данных нет — пояснение.
     *
     * @throws HttpException|BookApiError
     */
    public function fetchFeatureText(int $bookId, int $paragraphId, string $feature): string
    {
        $spec = self::FEATURES[$feature];
        try {
            $data = httpGetJson(
                $this->bookApiUrl . '/' . $spec['endpoint'],
                ['bookId' => $bookId, 'paragraphId' => $paragraphId]
            );
        } catch (HttpException $e) {
            if ($e->status === 404) {
                return $spec['missing'];
            }
            throw $e;
        }
        return self::parseFeatureText($data, $spec['endpoint'], $spec['list']) ?? $spec['missing'];
    }

    /** @throws HttpException|BookApiError */
    public function fetchRandomFact(): string
    {
        [$name, $fact] = self::parseRandomFact(httpGetJson($this->randomFactApiUrl));
        return "Случайный факт о $name:\n$fact";
    }

    /** @return array<int,string> */
    private static function intKeys(array $books): array
    {
        $result = [];
        foreach ($books as $id => $title) {
            $result[(int)$id] = (string)$title;
        }
        return $result;
    }

    /** @return array{0:string,1:string} */
    public static function parseParagraph(mixed $data): array
    {
        if (!is_array($data)) {
            throw new BookApiError('API вернуло ответ не в формате JSON-объекта');
        }
        $title = $data['title'] ?? null;
        $parts = $data['parts'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            throw new BookApiError('В ответе API отсутствует название параграфа');
        }
        if (!is_array($parts)) {
            throw new BookApiError('В ответе API отсутствует список частей параграфа');
        }

        $texts = [];
        foreach ($parts as $part) {
            if (!is_array($part) || !isset($part['text']) || !is_string($part['text'])) {
                throw new BookApiError('Одна из частей параграфа имеет неверный формат');
            }
            if (trim($part['text']) !== '') {
                $texts[] = trim($part['text']);
            }
        }
        $paragraphText = implode("\n\n", $texts);
        if ($paragraphText === '') {
            throw new BookApiError('В ответе API нет текста параграфа');
        }
        return [trim($title), $paragraphText];
    }

    /**
     * Разбирает список книг API: [{"bookId": 3, "title": "..."}].
     *
     * @return array<int,string>
     */
    public static function parseBooks(mixed $data): array
    {
        if (!is_array($data) || !array_is_list($data)) {
            throw new BookApiError('Каталог API вернул не список');
        }
        $books = [];
        foreach ($data as $item) {
            if (
                is_array($item)
                && isset($item['bookId'], $item['title'])
                && is_int($item['bookId'])
                && is_string($item['title'])
                && trim($item['title']) !== ''
            ) {
                $books[$item['bookId']] = trim($item['title']);
            }
        }
        if (!$books) {
            throw new BookApiError('В каталоге API нет книг');
        }
        return $books;
    }

    /** Достаёт текст (summary) или нумерованный список (main_points, answers); пусто -> null. */
    public static function parseFeatureText(mixed $data, string $field, bool $isList): ?string
    {
        if (!is_array($data)) {
            throw new BookApiError('API вернуло ответ не в формате JSON-объекта');
        }
        $value = $data[$field] ?? null;
        if ($value === null) {
            return null;
        }

        if (!$isList) {
            if (!is_string($value)) {
                throw new BookApiError("Поле $field должно быть строкой");
            }
            return trim($value) === '' ? null : trim($value);
        }

        if (!is_array($value) || !array_is_list($value) || array_filter($value, fn($item) => !is_string($item))) {
            throw new BookApiError("Поле $field должно быть массивом строк");
        }
        $items = array_values(array_filter(array_map('trim', $value), fn($item) => $item !== ''));
        if (!$items) {
            return null;
        }
        $lines = [];
        foreach ($items as $index => $item) {
            $lines[] = ($index + 1) . ". $item";
        }
        return implode("\n\n", $lines);
    }

    /** @return array{0:string,1:string} */
    public static function parseRandomFact(mixed $data): array
    {
        if (!is_array($data)) {
            throw new BookApiError('API вернуло ответ не в формате JSON-объекта');
        }
        $name = $data['name'] ?? null;
        $fact = $data['fact'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new BookApiError('В ответе API отсутствует имя');
        }
        if (!is_string($fact) || trim($fact) === '') {
            throw new BookApiError('В ответе API отсутствует факт');
        }
        return [trim($name), trim($fact)];
    }
}
