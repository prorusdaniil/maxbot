import logging

import httpx
from maxapi import Router
from maxapi.types import MessageCallback

from app import messaging
from app.books import API_FEATURES
from app.books import api as books_api
from app.state import UserContext, sessions

from . import quiz_flow
from .common import get_user_and_chat_ids

logger = logging.getLogger(__name__)
router = Router()


@router.message_callback()
async def callback_handler(event: MessageCallback) -> None:
    user_id, chat_id = get_user_and_chat_ids(event)
    payload = event.callback.payload or ""
    context = sessions.get(user_id)

    if payload.startswith("quiz:") and context is not None and context.stage == "ready":
        await quiz_flow.handle_quiz_callback(event, context, chat_id, payload)
        return

    await event.ack()

    if payload.startswith("book:"):
        await handle_book(user_id, chat_id, payload)
        return

    if payload == "random_fact":
        await handle_random_fact(chat_id)
        return

    if context is None or context.stage not in ("ready", "discuss") or not context.paragraph_text:
        await messaging.send_text(chat_id, "Сначала загрузи параграф: введи /start и выбери книгу.")
        return

    if payload == "menu":
        context.stage = "ready"
        await messaging.send_menu(chat_id, "Выбери действие или введи номер другого параграфа:")
    elif payload.startswith("feature:"):
        context.stage = "ready"
        await handle_feature(context, user_id, chat_id, payload.removeprefix("feature:"))
    elif payload == "quiz":
        context.stage = "ready"
        await quiz_flow.start_quiz(context, user_id, chat_id)
    elif payload == "discuss":
        context.stage = "discuss"
        await messaging.send_back_to_menu(
            chat_id, f"Параграф «{context.paragraph_title}». Напиши свой вопрос по нему:"
        )
    else:
        logger.warning("Received unknown callback payload: %r", payload)


async def handle_book(user_id: int, chat_id: int, payload: str) -> None:
    try:
        book_id = int(payload.removeprefix("book:"))
    except ValueError:
        logger.warning("Received invalid book callback payload: %r", payload)
        await messaging.send_text(chat_id, "Не удалось определить книгу. Введи /start.")
        return

    try:
        books = await books_api.fetch_books()
    except (httpx.HTTPError, ValueError):
        logger.exception("Failed to load book list")
        await messaging.send_text(chat_id, "Не удалось загрузить список книг. Попробуй /start позже.")
        return
    if book_id not in books:
        logger.warning("Received unknown book id in callback: %s", book_id)
        await messaging.send_text(chat_id, "Эта книга недоступна. Введи /start.")
        return

    sessions.set(user_id, UserContext(stage="enter_paragraph", book_id=book_id))
    await messaging.send_text(chat_id, f"Выбрана книга «{books[book_id]}». Введи номер параграфа:")


async def handle_feature(context: UserContext, user_id: int, chat_id: int, feature: str) -> None:
    if feature not in API_FEATURES or context.book_id is None or context.paragraph_id is None:
        logger.warning("Unknown feature %r or incomplete context for user %s", feature, user_id)
        return

    try:
        text = await books_api.fetch_feature_text(context.book_id, context.paragraph_id, feature)
    except (httpx.HTTPError, ValueError):
        logger.exception("Failed to load feature %s for user %s", feature, user_id)
        await messaging.send_back_to_menu(chat_id, "Не удалось получить данные от API. Попробуй позже.")
        return

    await messaging.send_long_text(chat_id, text)
    await messaging.send_menu(chat_id, "Что дальше?")


async def handle_random_fact(chat_id: int) -> None:
    try:
        text = await books_api.fetch_random_fact()
    except (httpx.HTTPError, ValueError):
        logger.exception("Failed to load random fact")
        await messaging.send_text(chat_id, "Не удалось получить случайный факт. Попробуй позже.")
        return

    await messaging.send_text(chat_id, text)
