<?php
declare(strict_types=1);

/**
 * Единственная нейросеть бота и пересказов. Чтобы сменить её, добавьте файл с реализацией LlmClient
 * и верните её здесь.
 *
 * @throws LlmConfigurationError
 */
function createLlmClient(): LlmClient
{
    $apiKey = env('DEEPSEEK_API_KEY');
    if ($apiKey === null) {
        throw new LlmConfigurationError('Укажите DEEPSEEK_API_KEY в .env');
    }
    return new DeepSeekClient(
        $apiKey,
        env('DEEPSEEK_MODEL', DeepSeekClient::DEFAULT_MODEL),
        env('DEEPSEEK_BASE_URL', DeepSeekClient::DEFAULT_BASE_URL)
    );
}

/** Обсуждение параграфа: вопрос пользователя вместе с текстом параграфа уходит в ИИ. */
function answerQuestion(LlmClient $llm, string $paragraphText, string $question): string
{
    return $llm->complete(
        Prompts::get('discuss'),
        "Текст параграфа:\n$paragraphText\n\nВопрос ученика: $question",
        800
    );
}
