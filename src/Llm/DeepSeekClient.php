<?php
declare(strict_types=1);

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

    private int $tokens = 0;

    public function tokensUsed(): int
    {
        return $this->tokens;
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

        botLog('debug', "Запрос к DeepSeek ({$this->model}): " . $userContent);
        $data = $this->postJson($payload);

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new LlmError('DeepSeek: неожиданный формат ответа');
        }
        if (trim($content) === '') {
            throw new LlmError('DeepSeek: пустой ответ');
        }
        $this->tokens += (int)($data['usage']['total_tokens'] ?? 0);
        botLog('debug', 'Ответ DeepSeek: ' . trim($content));
        return trim($content);
    }

    /** Текст ошибки не содержит заголовков, ключ в лог не попадёт. */
    private function postJson(string $payload): array
    {
        try {
            [$status, $body] = httpRequest(
                'POST',
                rtrim($this->baseUrl, '/') . '/chat/completions',
                ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $this->apiKey],
                $payload,
                $this->timeout,
                null
            );
        } catch (HttpException $e) {
            throw new LlmError("DeepSeek: запрос не удался ({$e->getMessage()})", 0, $e);
        }
        if ($status < 200 || $status >= 300) {
            throw new LlmError("DeepSeek: запрос не удался (HTTP $status: " . mb_substr($body, 0, 300) . ')');
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new LlmError('DeepSeek: ответ не является корректным JSON');
        }
        return $data;
    }
}
