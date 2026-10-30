import pytest

from llm import DeepSeekClient, GigaChatClient, LLMConfigurationError, create_llm_client

GIGACHAT_ENV = {"GIGACHAT_CREDENTIALS": "c", "GIGACHAT_MODEL": "m"}


def test_prefers_deepseek_when_key_is_set():
    assert isinstance(create_llm_client({"DEEPSEEK_API_KEY": "k", **GIGACHAT_ENV}), DeepSeekClient)


def test_falls_back_to_gigachat():
    assert isinstance(create_llm_client(GIGACHAT_ENV), GigaChatClient)


def test_respects_explicit_provider():
    env = {"LLM_PROVIDER": "gigachat", "DEEPSEEK_API_KEY": "k", **GIGACHAT_ENV}
    assert isinstance(create_llm_client(env), GigaChatClient)


@pytest.mark.parametrize(
    "env",
    [
        {},
        {"LLM_PROVIDER": "deepseek"},
        {"LLM_PROVIDER": "gigachat", "GIGACHAT_CREDENTIALS": "c"},
        {"LLM_PROVIDER": "unknown"},
    ],
)
def test_reports_missing_configuration(env):
    with pytest.raises(LLMConfigurationError):
        create_llm_client(env)
