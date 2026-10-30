import asyncio
import logging

from llm import LLMConfigurationError, LLMError

from . import keyboards, runtime
from .config import MAX_MESSAGE_LENGTH

logger = logging.getLogger(__name__)


async def send_text(chat_id: int, text: str, keyboard=None) -> None:
    await runtime.get_bot().send_message(
        chat_id=chat_id,
        text=text,
        attachments=[keyboard] if keyboard is not None else None,
    )


async def send_menu(chat_id: int, text: str) -> None:
    await send_text(chat_id, text, keyboards.make_menu_keyboard())


async def send_back_to_menu(chat_id: int, text: str) -> None:
    await send_text(chat_id, text, keyboards.make_back_to_menu_keyboard())


async def send_long_text(chat_id: int, text: str) -> None:
    """Отправляет большие ответы несколькими сообщениями в пределах лимита MAX."""
    chunks = [
        text[index : index + MAX_MESSAGE_LENGTH]
        for index in range(0, len(text), MAX_MESSAGE_LENGTH)
    ]
    for index, chunk in enumerate(chunks):
        if index:
            await asyncio.sleep(0.55)
        await send_text(chat_id, chunk)


async def send_llm_error(chat_id: int, error: LLMError, user_id: int) -> None:
    if isinstance(error, LLMConfigurationError):
        logger.error("%s", error)
        text = "Для этой функции настрой LLM (DEEPSEEK_API_KEY или GigaChat) в файле .env."
    else:
        logger.error("LLM request failed for user %s", user_id, exc_info=error)
        text = "Не удалось получить ответ от ИИ. Попробуй ещё раз позже."
    await send_back_to_menu(chat_id, text)
