import asyncio

import pytest

from llm import DeepSeekClient, LLMError, LoggingLLMClient, create_llm_client


class FakeLLM:
    def __init__(self, result="ответ", error=None):
        self.result = result
        self.error = error

    async def complete(self, system_prompt, user_content):
        if self.error:
            raise self.error
        return self.result


def test_logs_request_and_response(tmp_path):
    log = tmp_path / "logs" / "llm.log"
    client = LoggingLLMClient(FakeLLM("Привет!"), log, name="fake")

    assert asyncio.run(client.complete("системный", "вопрос")) == "Привет!"

    text = log.read_text(encoding="utf-8")
    assert "Запрос к LLM #1 (fake)" in text
    assert "СИСТЕМНЫЙ ПРОМПТ:\nсистемный" in text
    assert "ТЕКСТ ПОЛЬЗОВАТЕЛЯ:\nвопрос" in text
    assert "ОТВЕТ:\nПривет!" in text


def test_logs_error_and_reraises(tmp_path):
    log = tmp_path / "llm.log"
    client = LoggingLLMClient(FakeLLM(error=LLMError("сбой")), log)

    with pytest.raises(LLMError):
        asyncio.run(client.complete("s", "u"))

    assert "ОШИБКА: LLMError: сбой" in log.read_text(encoding="utf-8")


def test_factory_wraps_client_when_log_file_is_set(tmp_path):
    env = {"DEEPSEEK_API_KEY": "k", "LLM_LOG_FILE": str(tmp_path / "llm.log")}
    assert isinstance(create_llm_client(env), LoggingLLMClient)
    assert isinstance(create_llm_client({"DEEPSEEK_API_KEY": "k"}), DeepSeekClient)
