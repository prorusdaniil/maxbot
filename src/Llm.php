<?php
declare(strict_types=1);

class LlmError extends RuntimeException
{
}

/** ИИ не настроен (нет ключей). */
class LlmConfigurationError extends LlmError
{
}

interface LlmClient
{
    /** @throws LlmError */
    public function complete(string $systemPrompt, string $userContent): string;
}

/** DeepSeek через OpenAI-совместимый /chat/completions. */
final class DeepSeekClient implements LlmClient
{
    public const DEFAULT_MODEL = 'deepseek-flash';
    public const DEFAULT_BASE_URL = 'https://api.deepseek.com';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = 60.0
    ) {
    }

    public function complete(string $systemPrompt, string $userContent): string
    {
        $payload = json_encode([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE);

        $data = self::postJson(
            rtrim($this->baseUrl, '/') . '/chat/completions',
            ['Authorization: Bearer ' . $this->apiKey],
            $payload,
            $this->timeout,
            null,
            'DeepSeek'
        );

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new LlmError('DeepSeek: неожиданный формат ответа');
        }
        if (trim($content) === '') {
            throw new LlmError('DeepSeek: пустой ответ');
        }
        return trim($content);
    }

    /** Общий POST JSON для провайдеров; текст ошибки не содержит заголовков, ключ в лог не попадёт. */
    public static function postJson(
        string $url,
        array $headers,
        string $payload,
        float $timeout,
        ?string $caBundle,
        string $provider
    ): array {
        try {
            [$status, $body] = httpRequest(
                'POST',
                $url,
                array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
                $payload,
                $timeout,
                $caBundle
            );
        } catch (HttpException $e) {
            throw new LlmError("$provider: запрос не удался ({$e->getMessage()})", 0, $e);
        }
        $data = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            throw new LlmError("$provider: запрос не удался (HTTP $status: " . mb_substr($body, 0, 300) . ')');
        }
        if (!is_array($data)) {
            throw new LlmError("$provider: ответ не является корректным JSON");
        }
        return $data;
    }
}

/** GigaChat через REST: OAuth-токен (кэшируется в файле) и /chat/completions. */
final class GigaChatClient implements LlmClient
{
    private const OAUTH_URL = 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth';
    private const API_URL = 'https://gigachat.devices.sberbank.ru/api/v1';

    public function __construct(
        private readonly string $credentials,
        private readonly string $model,
        private readonly string $scope = 'GIGACHAT_API_PERS',
        private readonly ?string $caBundle = null,
        private readonly float $timeout = 60.0
    ) {
    }

    public function complete(string $systemPrompt, string $userContent): string
    {
        $payload = json_encode([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $data = DeepSeekClient::postJson(
            self::API_URL . '/chat/completions',
            ['Authorization: Bearer ' . $this->accessToken()],
            $payload,
            $this->timeout,
            $this->caBundle,
            'GigaChat'
        );

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new LlmError('GigaChat: пустой ответ');
        }
        return trim($content);
    }

    private function accessToken(): string
    {
        $cacheFile = sys_get_temp_dir() . '/maxbot_gigachat_token_' . md5($this->credentials . $this->scope) . '.json';
        if (is_readable($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            // expires_at — время в миллисекундах; обновляем за минуту до конца.
            if (is_array($cached) && isset($cached['token'], $cached['expires_at'])
                && (int)$cached['expires_at'] / 1000 - 60 > time()) {
                return (string)$cached['token'];
            }
        }

        $uuid = bin2hex(random_bytes(16));
        $rqUid = sprintf('%s-%s-4%s-%s-%s', substr($uuid, 0, 8), substr($uuid, 8, 4), substr($uuid, 13, 3),
            dechex(8 | (hexdec($uuid[16]) & 3)) . substr($uuid, 17, 3), substr($uuid, 20, 12));
        try {
            [$status, $body] = httpRequest(
                'POST',
                self::OAUTH_URL,
                [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                    'RqUID: ' . $rqUid,
                    'Authorization: Basic ' . $this->credentials,
                ],
                http_build_query(['scope' => $this->scope]),
                $this->timeout,
                $this->caBundle
            );
        } catch (HttpException $e) {
            throw new LlmError('GigaChat: не удалось получить токен (' . $e->getMessage() . ')', 0, $e);
        }
        $data = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['access_token'])) {
            throw new LlmError("GigaChat: не удалось получить токен (HTTP $status)");
        }

        $expiresAt = (int)($data['expires_at'] ?? (time() + 1800) * 1000);
        @file_put_contents($cacheFile, json_encode(['token' => $data['access_token'], 'expires_at' => $expiresAt]), LOCK_EX);
        @chmod($cacheFile, 0600);
        return (string)$data['access_token'];
    }
}

/** Обёртка над любым LlmClient: пишет каждый запрос и ответ в файл в читаемом виде. */
final class LoggingLlmClient implements LlmClient
{
    private const WIDE = '================================================================================';
    private const THIN = '--------------------------------------------------------------------------------';

    public function __construct(
        private readonly LlmClient $inner,
        private readonly string $logPath,
        private readonly ?string $name = null
    ) {
    }

    public function complete(string $systemPrompt, string $userContent): string
    {
        $started = microtime(true);
        try {
            $result = $this->inner->complete($systemPrompt, $userContent);
        } catch (Throwable $e) {
            $this->write($systemPrompt, $userContent, microtime(true) - $started,
                'ОШИБКА: ' . get_class($e) . ': ' . $e->getMessage());
            throw $e;
        }
        $this->write($systemPrompt, $userContent, microtime(true) - $started, "ОТВЕТ:\n$result");
        return $result;
    }

    private function write(string $systemPrompt, string $userContent, float $elapsed, string $outcome): void
    {
        $name = $this->name ?? get_class($this->inner);
        $entry = self::WIDE . "\n"
            . sprintf("[%s] Запрос к LLM (%s), %.2f с\n", date('Y-m-d H:i:s'), $name, $elapsed)
            . self::THIN . "\nСИСТЕМНЫЙ ПРОМПТ:\n$systemPrompt\n"
            . self::THIN . "\nТЕКСТ ПОЛЬЗОВАТЕЛЯ:\n$userContent\n"
            . self::THIN . "\n$outcome\n\n";

        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Выбирает провайдера по LLM_PROVIDER; без него — DeepSeek, затем GigaChat по наличию ключа.
 * Если задан LLM_LOG_FILE, все запросы и ответы дополнительно пишутся в этот файл.
 *
 * @throws LlmConfigurationError
 */
function createLlmClient(): LlmClient
{
    $provider = strtolower(env('LLM_PROVIDER', '') ?? '');
    if ($provider === '') {
        if (env('DEEPSEEK_API_KEY') !== null) {
            $provider = 'deepseek';
        } elseif (env('GIGACHAT_CREDENTIALS') !== null) {
            $provider = 'gigachat';
        } else {
            throw new LlmConfigurationError('Укажите DEEPSEEK_API_KEY (или GIGACHAT_CREDENTIALS и GIGACHAT_MODEL) в .env');
        }
    }

    if ($provider === 'deepseek') {
        $apiKey = env('DEEPSEEK_API_KEY');
        if ($apiKey === null) {
            throw new LlmConfigurationError('Укажите DEEPSEEK_API_KEY в .env');
        }
        $client = new DeepSeekClient(
            $apiKey,
            env('DEEPSEEK_MODEL', DeepSeekClient::DEFAULT_MODEL),
            env('DEEPSEEK_BASE_URL', DeepSeekClient::DEFAULT_BASE_URL)
        );
    } elseif ($provider === 'gigachat') {
        $credentials = env('GIGACHAT_CREDENTIALS');
        $model = env('GIGACHAT_MODEL');
        if ($credentials === null || $model === null) {
            throw new LlmConfigurationError('Укажите GIGACHAT_CREDENTIALS и GIGACHAT_MODEL в .env');
        }
        $client = new GigaChatClient(
            $credentials,
            $model,
            env('GIGACHAT_SCOPE', 'GIGACHAT_API_PERS'),
            russianCaBundle()
        );
    } else {
        throw new LlmConfigurationError("Неизвестный LLM_PROVIDER: '$provider'");
    }

    $logFile = env('LLM_LOG_FILE');
    return $logFile !== null ? new LoggingLlmClient($client, resolvePath($logFile)) : $client;
}

const DISCUSS_SYSTEM_PROMPT = 'Ты — дружелюбный учитель. Ответь на вопрос ученика простыми и понятными словами, '
    . 'опираясь на текст параграфа. Не выдумывай фактов; если в тексте нет ответа, '
    . 'честно скажи об этом.';

/** Обсуждение параграфа: вопрос пользователя вместе с текстом параграфа уходит в ИИ. */
function answerQuestion(LlmClient $llm, string $paragraphText, string $question): string
{
    return $llm->complete(
        DISCUSS_SYSTEM_PROMPT,
        "Текст параграфа:\n$paragraphText\n\nВопрос ученика: $question"
    );
}
