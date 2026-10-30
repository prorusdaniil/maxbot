from typing import Protocol


class LLMError(Exception):
    """Запрос к LLM не удался или ответ неверного формата."""


class LLMConfigurationError(LLMError):
    """Для выбранного провайдера не хватает настроек."""


class LLMClient(Protocol):
    """Единый интерфейс провайдеров: системный промпт + пользовательский текст -> ответ."""

    async def complete(self, system_prompt: str, user_content: str) -> str: ...
