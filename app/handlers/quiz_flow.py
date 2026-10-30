import logging

from maxapi.types import MessageCallback

from app import keyboards, messaging, runtime
from app.quiz import QuizGenerationError, format_quiz_text, generate_quiz
from app.state import UserContext
from llm import LLMError

logger = logging.getLogger(__name__)


async def start_quiz(context: UserContext, user_id: int, chat_id: int) -> None:
    await messaging.send_text(chat_id, "Составляю тест…")
    try:
        quiz = await generate_quiz(runtime.get_llm(), context.paragraph_text)
    except QuizGenerationError:
        logger.exception("LLM returned an invalid quiz for user %s", user_id)
        await messaging.send_back_to_menu(chat_id, "Не удалось составить тест. Попробуй ещё раз.")
        return
    except LLMError as error:
        await messaging.send_llm_error(chat_id, error, user_id)
        return

    context.quiz = quiz
    await messaging.send_text(
        chat_id,
        format_quiz_text(context.paragraph_title, quiz),
        keyboards.make_quiz_keyboard(quiz),
    )


async def handle_quiz_callback(
    event: MessageCallback, context: UserContext, chat_id: int, payload: str
) -> None:
    quiz = context.quiz
    if quiz is None or quiz.finished:
        await event.ack("Этот тест уже завершён.")
        return

    # edit сам подтверждает callback, поэтому ack вызывается только в ветках без edit.
    if payload.startswith("quiz:toggle:"):
        try:
            index = int(payload.removeprefix("quiz:toggle:"))
        except ValueError:
            index = -1
        if not 0 <= index < len(quiz.options):
            await event.ack()
            return
        quiz.selected.symmetric_difference_update({index})
        await event.edit(
            text=format_quiz_text(context.paragraph_title, quiz),
            attachments=[keyboards.make_quiz_keyboard(quiz)],
        )
        return

    if not quiz.selected:
        await event.ack("Сначала выбери хотя бы один вариант.")
        return

    quiz.finished = True
    await event.edit(
        text=format_quiz_text(context.paragraph_title, quiz, show_result=True),
        attachments=[],
    )
    await messaging.send_menu(chat_id, "Что дальше?")
