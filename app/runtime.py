"""Общие объекты, создаваемые при запуске: клиент MAX и клиент LLM."""

from maxapi import Bot

from llm import LLMClient, create_llm_client

_bot: Bot | None = None
_llm: LLMClient | None = None


def set_bot(bot: Bot | None) -> None:
    global _bot
    _bot = bot


def get_bot() -> Bot:
    if _bot is None:
        raise RuntimeError("MAX Bot API ещё не инициализирован")
    return _bot


def set_llm(llm: LLMClient | None) -> None:
    global _llm
    _llm = llm


def get_llm() -> LLMClient:
    """Создаёт клиент при первом обращении; LLMConfigurationError, если ключей нет."""
    global _llm
    if _llm is None:
        _llm = create_llm_client()
    return _llm
