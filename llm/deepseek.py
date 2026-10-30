import httpx

from .base import LLMError

DEFAULT_MODEL = "deepseek-flash"
DEFAULT_BASE_URL = "https://api.deepseek.com"


class DeepSeekClient:
    """DeepSeek через OpenAI-совместимый /chat/completions."""

    def __init__(
        self,
        api_key: str,
        model: str = DEFAULT_MODEL,
        base_url: str = DEFAULT_BASE_URL,
        *,
        timeout: float = 60.0,
        transport: httpx.AsyncBaseTransport | None = None,
    ) -> None:
        self._api_key = api_key
        self._model = model
        self._base_url = base_url.rstrip("/")
        self._timeout = timeout
        self._transport = transport

    async def complete(self, system_prompt: str, user_content: str) -> str:
        payload = {
            "model": self._model,
            "messages": [
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_content},
            ],
            "stream": False,
        }
        data = await self._post(payload)
        return self._extract_text(data)

    async def _post(self, payload: dict) -> object:
        try:
            async with httpx.AsyncClient(timeout=self._timeout, transport=self._transport) as client:
                response = await client.post(
                    f"{self._base_url}/chat/completions",
                    json=payload,
                    headers={"Authorization": f"Bearer {self._api_key}"},
                )
                response.raise_for_status()
                return response.json()
        except (httpx.HTTPError, ValueError) as error:
            # Текст исключения httpx не содержит заголовков, ключ в лог не попадёт.
            raise LLMError(f"DeepSeek: запрос не удался ({error})") from error

    @staticmethod
    def _extract_text(data: object) -> str:
        try:
            content = data["choices"][0]["message"]["content"]  # type: ignore[index]
        except (KeyError, IndexError, TypeError) as error:
            raise LLMError("DeepSeek: неожиданный формат ответа") from error
        if not isinstance(content, str) or not content.strip():
            raise LLMError("DeepSeek: пустой ответ")
        return content.strip()
