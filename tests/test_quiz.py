import asyncio
import json

import pytest
from conftest import FakeCallbackEvent, FakeLLM, buttons

from app.handlers.callbacks import callback_handler
from app.quiz import QUIZ_SYSTEM_PROMPT, QuizGenerationError, generate_quiz
from llm import LLMConfigurationError, LLMError

QUIZ_JSON = json.dumps(
    {
        "question": "Где жили первые люди?",
        "options": ["В пещерах", "В замках", "В землянках", "В небоскрёбах"],
        "correct": [0, 2],
    },
    ensure_ascii=False,
)


def test_generate_quiz_sends_system_prompt_and_paragraph():
    llm = FakeLLM(reply=QUIZ_JSON)

    quiz = asyncio.run(generate_quiz(llm, "Текст параграфа"))

    assert llm.calls == [(QUIZ_SYSTEM_PROMPT, "Текст параграфа")]
    assert quiz.question == "Где жили первые люди?"
    assert quiz.correct == {0, 2}
    assert len(quiz.options) == 4


def test_generate_quiz_accepts_json_in_markdown_fence():
    llm = FakeLLM(reply=f"```json\n{QUIZ_JSON}\n```")

    assert asyncio.run(generate_quiz(llm, "Текст")).correct == {0, 2}


@pytest.mark.parametrize(
    "reply",
    [
        "просто текст",
        "{не json}",
        "[1, 2]",
        '{"question": "?", "options": ["a"], "correct": [0]}',
        '{"question": "?", "options": ["a", "b"], "correct": []}',
        '{"question": "?", "options": ["a", "b"], "correct": [5]}',
        '{"question": "?", "options": ["a", "b"], "correct": [0, 1]}',
        '{"question": "", "options": ["a", "b"], "correct": [0]}',
    ],
)
def test_generate_quiz_rejects_malformed_reply(reply):
    with pytest.raises(QuizGenerationError):
        asyncio.run(generate_quiz(FakeLLM(reply=reply), "Текст"))


def test_quiz_button_generates_quiz_and_sends_keyboard(llm, ready_context, fake_bot):
    llm.reply = QUIZ_JSON

    asyncio.run(callback_handler(FakeCallbackEvent("quiz")))

    assert llm.calls == [(QUIZ_SYSTEM_PROMPT, ready_context.paragraph_text)]
    quiz_message = fake_bot.sent[-1]
    assert "Где жили первые люди?" in quiz_message["text"]
    assert "Правильных вариантов: 2" in quiz_message["text"]
    assert buttons(quiz_message["attachments"][0]) == [
        ("☐ 1", "quiz:toggle:0"),
        ("☐ 2", "quiz:toggle:1"),
        ("☐ 3", "quiz:toggle:2"),
        ("☐ 4", "quiz:toggle:3"),
        ("Проверить", "quiz:check"),
    ]
    assert ready_context.quiz is not None


def test_quiz_with_invalid_llm_reply_offers_menu(llm, ready_context, fake_bot):
    llm.reply = "не JSON"

    asyncio.run(callback_handler(FakeCallbackEvent("quiz")))

    assert "Не удалось составить тест" in fake_bot.sent[-1]["text"]
    assert buttons(fake_bot.sent[-1]["attachments"][0]) == [("В меню", "menu")]
    assert ready_context.quiz is None


def test_quiz_llm_failure_is_reported(llm, ready_context, fake_bot):
    llm.error = LLMError("boom")

    asyncio.run(callback_handler(FakeCallbackEvent("quiz")))

    assert "Не удалось получить ответ от ИИ" in fake_bot.sent[-1]["text"]


def test_missing_llm_configuration_is_reported(monkeypatch, ready_context, fake_bot):
    def raise_config_error():
        raise LLMConfigurationError("нет ключа")

    monkeypatch.setattr("app.runtime.create_llm_client", raise_config_error)

    asyncio.run(callback_handler(FakeCallbackEvent("quiz")))

    assert ".env" in fake_bot.sent[-1]["text"]


def test_quiz_selection_and_check(llm, ready_context, fake_bot):
    llm.reply = QUIZ_JSON
    asyncio.run(callback_handler(FakeCallbackEvent("quiz")))

    toggle = FakeCallbackEvent("quiz:toggle:0")
    asyncio.run(callback_handler(toggle))
    assert buttons(toggle.edits[0]["attachments"][0])[0] == ("☑ 1", "quiz:toggle:0")
    assert toggle.acks == []

    toggle_again = FakeCallbackEvent("quiz:toggle:0")
    asyncio.run(callback_handler(toggle_again))
    assert buttons(toggle_again.edits[0]["attachments"][0])[0] == ("☐ 1", "quiz:toggle:0")

    for index in (0, 2):
        asyncio.run(callback_handler(FakeCallbackEvent(f"quiz:toggle:{index}")))
    check = FakeCallbackEvent("quiz:check")
    asyncio.run(callback_handler(check))

    assert "Верно!" in check.edits[0]["text"]
    assert check.edits[0]["attachments"] == []
    assert ready_context.quiz.finished
    assert len(buttons(fake_bot.sent[-1]["attachments"][0])) == 5

    late = FakeCallbackEvent("quiz:toggle:1")
    asyncio.run(callback_handler(late))
    assert late.edits == []
    assert late.acks == ["Этот тест уже завершён."]


def test_quiz_wrong_answer_shows_correct_options(llm, ready_context):
    llm.reply = QUIZ_JSON
    asyncio.run(callback_handler(FakeCallbackEvent("quiz")))
    asyncio.run(callback_handler(FakeCallbackEvent("quiz:toggle:1")))
    check = FakeCallbackEvent("quiz:check")

    asyncio.run(callback_handler(check))

    text = check.edits[0]["text"]
    assert "Есть ошибки. Правильные ответы: 1, 3." in text
    assert "❌ 2. В замках" in text
    assert "✅ 1. В пещерах" in text


def test_quiz_check_without_selection_asks_to_choose(llm, ready_context):
    llm.reply = QUIZ_JSON
    asyncio.run(callback_handler(FakeCallbackEvent("quiz")))
    check = FakeCallbackEvent("quiz:check")

    asyncio.run(callback_handler(check))

    assert check.edits == []
    assert check.acks == ["Сначала выбери хотя бы один вариант."]
    assert not ready_context.quiz.finished
