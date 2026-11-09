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
    /** @throws LlmError */
    public function complete(string $systemPrompt, string $userContent): string;

    /** Суммарный расход токенов всех запросов этого клиента. */
    public function tokensUsed(): int;
}
