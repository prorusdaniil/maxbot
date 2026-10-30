from .base import LLMError


class GigaChatClient:
    """GigaChat через официальный Python SDK."""

    def __init__(self, credentials: str, model: str) -> None:
        self._credentials = credentials
        self._model = model

    async def complete(self, system_prompt: str, user_content: str) -> str:
        # SDK подключается лениво, чтобы DeepSeek работал и без него.
        from gigachat import GigaChat
        from gigachat.exceptions import GigaChatException
        from gigachat.models import ChatCompletionRequest, ChatMessage

        request = ChatCompletionRequest(
            messages=[
                ChatMessage(role="system", content=system_prompt),
                ChatMessage(role="user", content=user_content),
            ]
        )
        try:
            async with GigaChat(credentials=self._credentials, model=self._model) as client:
                response = await client.achat.create(request)
        except GigaChatException as error:
            raise LLMError(f"GigaChat: запрос не удался ({error})") from error

        if not response.messages or not response.messages[0].content:
            raise LLMError("GigaChat: пустой ответ")
        result = response.messages[0].content[0].text
        if not result or not result.strip():
            raise LLMError("GigaChat: пустой текст")
        return result.strip()
