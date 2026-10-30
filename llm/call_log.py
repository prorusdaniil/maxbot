import time
from datetime import datetime
from pathlib import Path

from .base import LLMClient

_WIDE = "=" * 80
_THIN = "-" * 80


class LoggingLLMClient:
    """Обёртка над любым LLMClient: пишет каждый запрос и ответ в файл в читаемом виде."""

    def __init__(self, inner: LLMClient, log_path: str | Path, name: str | None = None) -> None:
        self._inner = inner
        self._path = Path(log_path)
        self._name = name or type(inner).__name__
        self._counter = 0

    async def complete(self, system_prompt: str, user_content: str) -> str:
        self._counter += 1
        number = self._counter
        started = time.monotonic()
        try:
            result = await self._inner.complete(system_prompt, user_content)
        except Exception as error:
            self._write(number, system_prompt, user_content, time.monotonic() - started,
                        f"ОШИБКА: {type(error).__name__}: {error}")
            raise
        self._write(number, system_prompt, user_content, time.monotonic() - started,
                    f"ОТВЕТ:\n{result}")
        return result

    def _write(self, number: int, system_prompt: str, user_content: str, elapsed: float,
               outcome: str) -> None:
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        entry = (
            f"{_WIDE}\n"
            f"[{now}] Запрос к LLM #{number} ({self._name}), {elapsed:.2f} с\n"
            f"{_THIN}\n"
            f"СИСТЕМНЫЙ ПРОМПТ:\n{system_prompt}\n"
            f"{_THIN}\n"
            f"ТЕКСТ ПОЛЬЗОВАТЕЛЯ:\n{user_content}\n"
            f"{_THIN}\n"
            f"{outcome}\n\n"
        )
        self._path.parent.mkdir(parents=True, exist_ok=True)
        with self._path.open("a", encoding="utf-8") as file:
            file.write(entry)
