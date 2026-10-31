from maxapi.types import CallbackButton
from maxapi.utils.inline_keyboard import InlineKeyboardBuilder

from app.books import API_FEATURES
from app.quiz import QuizState


def make_books_keyboard(books: dict[int, str]):
    builder = InlineKeyboardBuilder()
    builder.row(CallbackButton(text="Случайный факт", payload="random_fact"))
    for book_id, title in books.items():
        builder.row(CallbackButton(text=title, payload=f"book:{book_id}"))
    return builder.as_markup()


def make_menu_keyboard():
    builder = InlineKeyboardBuilder()
    for feature in API_FEATURES.values():
        builder.row(CallbackButton(text=feature.button_title, payload=f"feature:{feature.key}"))
    builder.row(CallbackButton(text="Тест по теме", payload="quiz"))
    builder.row(CallbackButton(text="Обсудить параграф", payload="discuss"))
    return builder.as_markup()


def make_back_to_menu_keyboard():
    builder = InlineKeyboardBuilder()
    builder.row(CallbackButton(text="В меню", payload="menu"))
    return builder.as_markup()


def make_quiz_keyboard(quiz: QuizState):
    builder = InlineKeyboardBuilder()
    builder.row(
        *(
            CallbackButton(
                text=f"{'☑' if index in quiz.selected else '☐'} {index + 1}",
                payload=f"quiz:toggle:{index}",
            )
            for index in range(len(quiz.options))
        )
    )
    builder.row(CallbackButton(text="Проверить", payload="quiz:check"))
    return builder.as_markup()
