import asyncio
import logging
import os
from dataclasses import dataclass
from typing import Literal

import httpx
from dotenv import load_dotenv
from gigachat import GigaChat
from gigachat.exceptions import GigaChatException
from gigachat.models import ChatCompletionRequest, ChatMessage
from maxapi import Bot, Dispatcher
from maxapi.exceptions import InvalidToken
from maxapi.types import CallbackButton, MessageCallback, MessageCreated
from maxapi.utils.inline_keyboard import InlineKeyboardBuilder


logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(name)s: %(message)s",
)
logger = logging.getLogger(__name__)
load_dotenv()

BOOK_API_URL = "https://counters.goweb.kz/book-parser/api.php"
MAX_MESSAGE_LENGTH = 3900

# Демонстрационные книги. Замените bookId на идентификаторы из своего каталога.
BOOKS: dict[int, str] = {
    3: "Средневековье",
    4: "История России",
    5: "Древний мир",
}


@dataclass
class UserContext:
    """Данные текущего диалога пользователя."""

    stage: Literal["choose_book", "enter_paragraph", "ready"] = "choose_book"
    book_id: int | None = None
    paragraph_title: str = ""
    paragraph_text: str = ""


user_contexts: dict[int, UserContext] = {}
bot: Bot | None = None
dp = Dispatcher()


class ParagraphAPIError(ValueError):
    """Ответ API книги не содержит ожидаемых данных."""


class GigaChatConfigurationError(ValueError):
    """Для вызова GigaChat не хватает переменных конфигурации."""


def parse_paragraph(data: object) -> tuple[str, str]:
    """Проверяет JSON API и объединяет тексты всех частей параграфа."""
    if not isinstance(data, dict):
        raise ParagraphAPIError("API вернуло ответ не в формате JSON-объекта")

    title = data.get("title")
    parts = data.get("parts")
    if not isinstance(title, str) or not title.strip():
        raise ParagraphAPIError("В ответе API отсутствует название параграфа")
    if not isinstance(parts, list):
        raise ParagraphAPIError("В ответе API отсутствует список частей параграфа")

    texts: list[str] = []
    for part in parts:
        if not isinstance(part, dict) or not isinstance(part.get("text"), str):
            raise ParagraphAPIError("Одна из частей параграфа имеет неверный формат")
        if part["text"].strip():
            texts.append(part["text"].strip())

    paragraph_text = "\n\n".join(texts)
    if not paragraph_text:
        raise ParagraphAPIError("В ответе API нет текста параграфа")

    return title.strip(), paragraph_text


def get_user_and_chat_ids(event: MessageCreated | MessageCallback) -> tuple[int, int]:
    chat_id, user_id = event.get_ids()
    if chat_id is None or user_id is None:
        raise ValueError("В событии MAX отсутствует идентификатор чата или пользователя")
    return user_id, chat_id


def get_bot() -> Bot:
    if bot is None:
        raise RuntimeError("MAX Bot API ещё не инициализирован")
    return bot


def make_books_keyboard():
    builder = InlineKeyboardBuilder()
    for book_id, title in BOOKS.items():
        builder.row(
            CallbackButton(
                text=title,
                payload=f"book:{book_id}",
            )
        )
    return builder.as_markup()


def make_summarize_keyboard():
    builder = InlineKeyboardBuilder()
    builder.row(
        CallbackButton(
            text="Пересказать простыми словами",
            payload="summarize",
        )
    )
    return builder.as_markup()


async def fetch_paragraph(book_id: int, paragraph_id: int) -> tuple[str, str]:
    """Получает параграф и объединяет тексты всех его частей."""
    async with httpx.AsyncClient(timeout=20.0) as client:
        response = await client.get(
            BOOK_API_URL,
            params={"bookId": book_id, "paragraphId": paragraph_id},
        )
        response.raise_for_status()
        data = response.json()

    return parse_paragraph(data)


async def simplify_text(text: str) -> str:
    """Запрашивает пересказ у GigaChat через официальный Python SDK."""
    credentials = os.getenv("GIGACHAT_CREDENTIALS")
    model = os.getenv("GIGACHAT_MODEL")
    if not credentials or not model:
        raise GigaChatConfigurationError(
            "Укажите GIGACHAT_CREDENTIALS и GIGACHAT_MODEL в файле .env"
        )

    request = ChatCompletionRequest(
        messages=[
            ChatMessage(
                role="system",
                content=(
                    "Ты — дружелюбный учитель. Перескажи следующий текст "
                    "простыми и понятными словами для школьника. "
                    "Сохрани главные факты и не добавляй выдуманных сведений."
                ),
            ),
            ChatMessage(role="user", content=text),
        ]
    )

    async with GigaChat(
        credentials=credentials,
        model=model,
    ) as client:
        response = await client.achat.create(request)

    if not response.messages or not response.messages[0].content:
        raise ValueError("GigaChat вернул пустой ответ")

    result = response.messages[0].content[0].text
    if not result or not result.strip():
        raise ValueError("GigaChat вернул пустой текст")
    return result.strip()


async def send_long_text(chat_id: int, text: str) -> None:
    """Отправляет большие ответы несколькими сообщениями в пределах лимита MAX."""
    chunks = [
        text[index : index + MAX_MESSAGE_LENGTH]
        for index in range(0, len(text), MAX_MESSAGE_LENGTH)
    ]
    for index, chunk in enumerate(chunks):
        if index:
            await asyncio.sleep(0.55)
        await get_bot().send_message(chat_id=chat_id, text=chunk)


@dp.message_created()
async def text_handler(event: MessageCreated) -> None:
    message_text = event.message.body.text
    if not message_text:
        return

    # Поддерживает /start и /start@имя_бота.
    message_text = message_text.strip()
    if not message_text:
        return
    command = message_text.split(maxsplit=1)[0].split("@", maxsplit=1)[0]
    if command == "/start":
        user_id, _ = get_user_and_chat_ids(event)
        user_contexts[user_id] = UserContext(stage="choose_book")
        await event.message.answer(
            "Привет! Выбери книгу:",
            attachments=[make_books_keyboard()],
        )
        return

    user_id, chat_id = get_user_and_chat_ids(event)
    context = user_contexts.get(user_id)
    if context is None or context.stage == "choose_book":
        await event.message.answer("Сначала выбери книгу командой /start.")
        return

    if context.stage != "enter_paragraph":
        await event.message.answer("Параграф уже загружен. Нажми кнопку пересказа или введи /start.")
        return

    try:
        paragraph_id = int(message_text.strip())
        if paragraph_id <= 0:
            raise ValueError
    except ValueError:
        await event.message.answer("Введи номер параграфа — положительное целое число.")
        return

    if context.book_id is None:
        logger.error("User context %s has no selected book", user_id)
        user_contexts[user_id] = UserContext(stage="choose_book")
        await event.message.answer("Не удалось определить книгу. Начни заново командой /start.")
        return

    try:
        title, paragraph_text = await fetch_paragraph(context.book_id, paragraph_id)
    except (httpx.HTTPError, ValueError):
        logger.exception(
            "Failed to load paragraph %s for book %s",
            paragraph_id,
            context.book_id,
        )
        context.stage = "enter_paragraph"
        await event.message.answer(
            "Не удалось загрузить параграф. Проверь номер и попробуй ещё раз."
        )
        return

    context.paragraph_title = title
    context.paragraph_text = paragraph_text
    context.stage = "ready"
    await get_bot().send_message(
        chat_id=chat_id,
        text=f"Параграф {title} успешно загружен!",
        attachments=[make_summarize_keyboard()],
    )


@dp.message_callback()
async def callback_handler(event: MessageCallback) -> None:
    await event.ack()
    user_id, chat_id = get_user_and_chat_ids(event)
    payload = event.callback.payload or ""

    if payload.startswith("book:"):
        try:
            book_id = int(payload.removeprefix("book:"))
        except ValueError:
            logger.warning("Received invalid book callback payload: %r", payload)
            await get_bot().send_message(
                chat_id=chat_id,
                text="Не удалось определить книгу. Введи /start.",
            )
            return

        if book_id not in BOOKS:
            logger.warning("Received unknown book id in callback: %s", book_id)
            await get_bot().send_message(
                chat_id=chat_id,
                text="Эта книга недоступна. Введи /start.",
            )
            return

        user_contexts[user_id] = UserContext(stage="enter_paragraph", book_id=book_id)
        await get_bot().send_message(
            chat_id=chat_id,
            text=f"Выбрана книга «{BOOKS[book_id]}». Введи номер параграфа:",
        )
        return

    if payload == "summarize":
        context = user_contexts.get(user_id)
        if context is None or context.stage != "ready" or not context.paragraph_text:
            await get_bot().send_message(
                chat_id=chat_id,
                text="Сначала загрузи параграф: введи /start и выбери книгу.",
            )
            return

        await get_bot().send_message(
            chat_id=chat_id,
            text="Готовлю простой пересказ…",
        )
        try:
            summary = await simplify_text(context.paragraph_text)
        except GigaChatConfigurationError as error:
            logger.error("%s", error)
            await get_bot().send_message(
                chat_id=chat_id,
                text="Для пересказа настрой GIGACHAT_CREDENTIALS и GIGACHAT_MODEL в файле .env.",
            )
            return
        except (GigaChatException, ValueError):
            logger.exception("GigaChat failed to summarize paragraph for user %s", user_id)
            await get_bot().send_message(
                chat_id=chat_id,
                text="Не удалось получить пересказ от GigaChat. Попробуй нажать кнопку позже.",
            )
            return

        await send_long_text(chat_id, summary)
        return

    logger.warning("Received unknown callback payload: %r", payload)


async def main() -> None:
    """Проверяет конфигурацию и запускает polling для локальной разработки."""
    global bot

    token = os.getenv("MAX_BOT_TOKEN", "").strip()
    if not token:
        raise SystemExit(
            "Не задан MAX_BOT_TOKEN. Создайте .env по образцу .env.example "
            "и укажите токен бота MAX."
        )
    if not os.getenv("GIGACHAT_CREDENTIALS") or not os.getenv("GIGACHAT_MODEL"):
        logger.warning(
            "GigaChat не настроен: добавьте GIGACHAT_CREDENTIALS и "
            "GIGACHAT_MODEL в .env, чтобы включить пересказ."
        )

    bot = Bot(token=token)
    try:
        # Polling подходит для локального тестирования; для production настройте webhook.
        await dp.start_polling(bot)
    except InvalidToken:
        raise SystemExit(
            "MAX отклонил MAX_BOT_TOKEN. Проверьте, что в .env указан актуальный "
            "токен доступа именно этого бота MAX (а не его ID или ключ GigaChat). "
            "При необходимости выпустите новый токен в настройках бота и замените "
            "значение MAX_BOT_TOKEN."
        ) from None
    finally:
        await bot.close_session()


if __name__ == "__main__":
    asyncio.run(main())
