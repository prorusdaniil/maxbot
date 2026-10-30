import logging

import httpx
from maxapi import Router
from maxapi.types import MessageCreated

from app import discussion, keyboards, messaging, runtime
from app.books import api as books_api
from app.state import UserContext, sessions
from llm import LLMError

from .common import get_user_and_chat_ids

logger = logging.getLogger(__name__)
router = Router()


@router.message_created()
async def text_handler(event: MessageCreated) -> None:
    message_text = (event.message.body.text or "").strip()
    if not message_text:
        return

    # Поддерживает /start и /start@имя_бота.
    command = message_text.split(maxsplit=1)[0].split("@", maxsplit=1)[0]
    if command == "/start":
        await handle_start(event)
        return

    user_id, chat_id = get_user_and_chat_ids(event)
    context = sessions.get(user_id)
    if context is None or context.stage == "choose_book":
        await event.message.answer("Сначала выбери книгу командой /start.")
    elif context.stage == "discuss":
        await handle_discussion(event, context, user_id, chat_id, message_text)
    else:
        await handle_paragraph_number(event, context, user_id, chat_id, message_text)


async def handle_start(event: MessageCreated) -> None:
    user_id, _ = get_user_and_chat_ids(event)
    sessions.set(user_id, UserContext(stage="choose_book"))
    try:
        books = await books_api.fetch_books()
    except (httpx.HTTPError, ValueError):
        logger.exception("Failed to load book list")
        await event.message.answer("Не удалось загрузить список книг. Попробуй /start позже.")
        return
    await event.message.answer(
        "Привет! Выбери книгу:",
        attachments=[keyboards.make_books_keyboard(books)],
    )


async def handle_paragraph_number(
    event: MessageCreated, context: UserContext, user_id: int, chat_id: int, text: str
) -> None:
    try:
        paragraph_id = int(text)
        if paragraph_id <= 0:
            raise ValueError
    except ValueError:
        await event.message.answer("Введи номер параграфа — положительное целое число.")
        return

    if context.book_id is None:
        logger.error("User context %s has no selected book", user_id)
        sessions.set(user_id, UserContext(stage="choose_book"))
        await event.message.answer("Не удалось определить книгу. Начни заново командой /start.")
        return

    try:
        title, paragraph_text = await books_api.fetch_paragraph(context.book_id, paragraph_id)
    except (httpx.HTTPError, ValueError):
        logger.exception(
            "Failed to load paragraph %s for book %s", paragraph_id, context.book_id
        )
        await event.message.answer("Не удалось загрузить параграф. Проверь номер и попробуй ещё раз.")
        return

    context.paragraph_id = paragraph_id
    context.paragraph_title = title
    context.paragraph_text = paragraph_text
    context.quiz = None
    context.stage = "ready"
    await messaging.send_menu(
        chat_id,
        f"Параграф «{title}» загружен. Выбери действие или введи номер другого параграфа:",
    )


async def handle_discussion(
    event: MessageCreated, context: UserContext, user_id: int, chat_id: int, question: str
) -> None:
    await event.message.answer("Думаю…")
    try:
        answer = await discussion.answer_question(runtime.get_llm(), context.paragraph_text, question)
    except LLMError as error:
        await messaging.send_llm_error(chat_id, error, user_id)
        return

    await messaging.send_long_text(chat_id, answer)
    await messaging.send_back_to_menu(chat_id, "Задай ещё вопрос или вернись в меню.")
