<?php
declare(strict_types=1);

class LlmError extends RuntimeException
{
}

/** ИИ не настроен (нет ключа). */
class LlmConfigurationError extends LlmError
{
}

/** Интерфейс нейросети: приложение работает только с ним, а API каждой нейросети — в своём файле. */
interface LlmClient
{
    /**
     * $maxTokens — жёсткий лимит токенов ответа (null — без лимита).
     * @throws LlmError
     */
    public function complete(string $systemPrompt, string $userContent, ?int $maxTokens = null): string;

    /**
     * Суммарная разбивка токенов: input — без кэша, cached — из кэша промпта, output — ответ.
     * @return array{input:int,cached:int,output:int}
     */
    public function usage(): array;
}
