import asyncio

import httpx
import pytest
from conftest import CHAT_ID, USER_ID, FakeCallbackEvent, FakeTextEvent, buttons

from app import keyboards
from app.books import ParagraphAPIError, parse_books
from app.books import api as books_api
from app.handlers.callbacks import callback_handler
from app.handlers.messages import text_handler
from app.state import UserContext, sessions

MENU_BUTTONS = [
    ("Пересказ простыми словами", "feature:summary"),
    ("Ответы на вопросы учебника", "feature:answers"),
    ("Основные тезисы", "feature:theses"),
    ("Тест по теме", "quiz"),
    ("Обсудить параграф", "discuss"),
]


def patch_books(monkeypatch, books=None, error=None):
    async def fake_fetch_books():
        if error is not None:
            raise error
        return books

    monkeypatch.setattr(books_api, "fetch_books", fake_fetch_books)


def test_menu_keyboard_has_all_features():
    assert buttons(keyboards.make_menu_keyboard()) == MENU_BUTTONS


def test_books_keyboard_uses_book_ids():
    keyboard = keyboards.make_books_keyboard({3: "История 6 класс", 4: "Другая"})
    assert buttons(keyboard) == [
        ("Случайный факт", "random_fact"),
        ("История 6 класс", "book:3"),
        ("Другая", "book:4"),
    ]


def test_parse_books_skips_malformed_items():
    data = [{"bookId": 3, "title": " История 6 класс "}, {"bookId": "x", "title": "?"}, {"title": "?"}]
    assert parse_books(data) == {3: "История 6 класс"}


@pytest.mark.parametrize("data", [{}, [], [{"bookId": 1}]])
def test_parse_books_rejects_empty_catalog(data):
    with pytest.raises(ParagraphAPIError):
        parse_books(data)


def test_start_shows_books_from_api(monkeypatch):
    patch_books(monkeypatch, {3: "История 6 класс"})
    event = FakeTextEvent("/start")

    asyncio.run(text_handler(event))

    assert sessions.get(USER_ID).stage == "choose_book"
    assert buttons(event.answers[0]["attachments"][0]) == [
        ("Случайный факт", "random_fact"),
        ("История 6 класс", "book:3"),
    ]


def test_start_with_bot_suffix_is_supported(monkeypatch):
    patch_books(monkeypatch, {3: "История 6 класс"})
    event = FakeTextEvent("/start@my_bot")

    asyncio.run(text_handler(event))

    assert event.answers[0]["text"].startswith("Привет")


def test_start_reports_books_api_failure(monkeypatch):
    patch_books(monkeypatch, error=httpx.ConnectError("down"))
    event = FakeTextEvent("/start")

    asyncio.run(text_handler(event))

    assert "список книг" in event.answers[0]["text"]
    assert event.answers[0]["attachments"] is None


def test_book_callback_asks_for_paragraph(monkeypatch, fake_bot):
    patch_books(monkeypatch, {3: "История 6 класс"})
    event = FakeCallbackEvent("book:3")

    asyncio.run(callback_handler(event))

    context = sessions.get(USER_ID)
    assert (context.stage, context.book_id) == ("enter_paragraph", 3)
    assert event.acks == [None]
    assert "История 6 класс" in fake_bot.sent[0]["text"]


def test_unknown_book_callback_is_rejected(monkeypatch, fake_bot):
    patch_books(monkeypatch, {3: "История 6 класс"})

    asyncio.run(callback_handler(FakeCallbackEvent("book:99")))

    assert sessions.get(USER_ID) is None
    assert "недоступна" in fake_bot.sent[0]["text"]


def test_paragraph_number_loads_paragraph_and_shows_menu(monkeypatch, fake_bot):
    sessions.set(USER_ID, UserContext(stage="enter_paragraph", book_id=3))
    requested = []

    async def fake_fetch_paragraph(book_id, paragraph_id):
        requested.append((book_id, paragraph_id))
        return "Первобытная эпоха", "Текст параграфа"

    monkeypatch.setattr(books_api, "fetch_paragraph", fake_fetch_paragraph)

    asyncio.run(text_handler(FakeTextEvent("2")))

    context = sessions.get(USER_ID)
    assert requested == [(3, 2)]
    assert (context.stage, context.paragraph_id, context.paragraph_text) == ("ready", 2, "Текст параграфа")
    sent = fake_bot.sent[0]
    assert sent["chat_id"] == CHAT_ID
    assert "Первобытная эпоха" in sent["text"]
    assert buttons(sent["attachments"][0]) == MENU_BUTTONS


@pytest.mark.parametrize("text", ["abc", "0", "-3", "1.5"])
def test_invalid_paragraph_number_is_rejected(text):
    sessions.set(USER_ID, UserContext(stage="enter_paragraph", book_id=3))
    event = FakeTextEvent(text)

    asyncio.run(text_handler(event))

    assert "положительное целое" in event.answers[0]["text"]


def test_paragraph_load_failure_keeps_stage(monkeypatch):
    sessions.set(USER_ID, UserContext(stage="enter_paragraph", book_id=3))

    async def failing_fetch_paragraph(book_id, paragraph_id):
        raise ParagraphAPIError("нет")

    monkeypatch.setattr(books_api, "fetch_paragraph", failing_fetch_paragraph)
    event = FakeTextEvent("5")

    asyncio.run(text_handler(event))

    assert sessions.get(USER_ID).stage == "enter_paragraph"
    assert "Не удалось загрузить" in event.answers[0]["text"]


def test_text_before_choosing_book_prompts_start():
    event = FakeTextEvent("2")

    asyncio.run(text_handler(event))

    assert "/start" in event.answers[0]["text"]


def test_menu_callback_returns_to_menu(ready_context, fake_bot):
    ready_context.stage = "discuss"

    asyncio.run(callback_handler(FakeCallbackEvent("menu")))

    assert ready_context.stage == "ready"
    assert buttons(fake_bot.sent[0]["attachments"][0]) == MENU_BUTTONS


@pytest.mark.parametrize("feature", ["summary", "answers", "theses"])
def test_feature_buttons_request_api_and_return_menu(monkeypatch, ready_context, fake_bot, feature):
    requested = []

    async def fake_fetch_feature_text(book_id, paragraph_id, name):
        requested.append((book_id, paragraph_id, name))
        return f"результат {name}"

    monkeypatch.setattr(books_api, "fetch_feature_text", fake_fetch_feature_text)

    asyncio.run(callback_handler(FakeCallbackEvent(f"feature:{feature}")))

    assert requested == [(3, 2, feature)]
    assert fake_bot.sent[0]["text"] == f"результат {feature}"
    assert buttons(fake_bot.sent[1]["attachments"][0]) == MENU_BUTTONS


def test_feature_api_failure_offers_menu(monkeypatch, ready_context, fake_bot):
    async def failing(book_id, paragraph_id, name):
        raise httpx.ConnectError("down")

    monkeypatch.setattr(books_api, "fetch_feature_text", failing)

    asyncio.run(callback_handler(FakeCallbackEvent("feature:summary")))

    assert "Не удалось получить данные" in fake_bot.sent[0]["text"]
    assert buttons(fake_bot.sent[0]["attachments"][0]) == [("В меню", "menu")]


def test_random_fact_callback_does_not_require_loaded_paragraph(monkeypatch, fake_bot):
    requested = []

    async def fake_fetch_random_fact():
        requested.append(True)
        return "Случайный факт о Н. П. Будашкине:\nНаписал музыку к сказке «Морозко»."

    monkeypatch.setattr(books_api, "fetch_random_fact", fake_fetch_random_fact)

    asyncio.run(callback_handler(FakeCallbackEvent("random_fact")))

    assert requested == [True]
    assert fake_bot.sent[0]["text"].startswith("Случайный факт о Н. П. Будашкине:")
    assert len(fake_bot.sent) == 1


def test_random_fact_callback_reports_api_failure(monkeypatch, ready_context, fake_bot):
    async def failing():
        raise httpx.ConnectError("down")

    monkeypatch.setattr(books_api, "fetch_random_fact", failing)

    asyncio.run(callback_handler(FakeCallbackEvent("random_fact")))

    assert "Не удалось получить случайный факт" in fake_bot.sent[0]["text"]
    assert fake_bot.sent[0]["attachments"] is None


def test_discuss_button_switches_mode(ready_context, fake_bot):
    asyncio.run(callback_handler(FakeCallbackEvent("discuss")))

    assert ready_context.stage == "discuss"
    assert buttons(fake_bot.sent[0]["attachments"][0]) == [("В меню", "menu")]


def test_callbacks_without_paragraph_ask_to_start(fake_bot):
    asyncio.run(callback_handler(FakeCallbackEvent("quiz")))

    assert "/start" in fake_bot.sent[0]["text"]


def test_unknown_callback_is_ignored(ready_context, fake_bot):
    event = FakeCallbackEvent("something")

    asyncio.run(callback_handler(event))

    assert event.acks == [None]
    assert fake_bot.sent == []
