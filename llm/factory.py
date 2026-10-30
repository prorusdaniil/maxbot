import os
from collections.abc import Mapping

from .base import LLMClient, LLMConfigurationError
from .deepseek import DEFAULT_BASE_URL, DEFAULT_MODEL, DeepSeekClient
from .gigachat import GigaChatClient


def create_llm_client(env: Mapping[str, str] | None = None) -> LLMClient:
    """Выбирает провайдера по LLM_PROVIDER; без него — DeepSeek, затем GigaChat по наличию ключа."""
    env = os.environ if env is None else env
    provider = env.get("LLM_PROVIDER", "").strip().lower() or _detect_provider(env)

    if provider == "deepseek":
        return _create_deepseek(env)
    if provider == "gigachat":
        return _create_gigachat(env)
    raise LLMConfigurationError(f"Неизвестный LLM_PROVIDER: {provider!r}")


def _detect_provider(env: Mapping[str, str]) -> str:
    if env.get("DEEPSEEK_API_KEY"):
        return "deepseek"
    if env.get("GIGACHAT_CREDENTIALS"):
        return "gigachat"
    raise LLMConfigurationError(
        "Укажите DEEPSEEK_API_KEY (или GIGACHAT_CREDENTIALS и GIGACHAT_MODEL) в .env"
    )


def _create_deepseek(env: Mapping[str, str]) -> DeepSeekClient:
    api_key = env.get("DEEPSEEK_API_KEY", "").strip()
    if not api_key:
        raise LLMConfigurationError("Укажите DEEPSEEK_API_KEY в .env")
    return DeepSeekClient(
        api_key,
        model=env.get("DEEPSEEK_MODEL") or DEFAULT_MODEL,
        base_url=env.get("DEEPSEEK_BASE_URL") or DEFAULT_BASE_URL,
    )


def _create_gigachat(env: Mapping[str, str]) -> GigaChatClient:
    credentials = env.get("GIGACHAT_CREDENTIALS", "").strip()
    model = env.get("GIGACHAT_MODEL", "").strip()
    if not credentials or not model:
        raise LLMConfigurationError("Укажите GIGACHAT_CREDENTIALS и GIGACHAT_MODEL в .env")
    return GigaChatClient(credentials, model)
