import asyncio

from conftest import FakeLLM, FakeTextEvent, buttons

from app import messaging
from app.config import MAX_MESSAGE_LENGTH
from app.discussion import DISCUSS_SYSTEM_PROMPT, answer_question
from app.handlers.messages import text_handler
from llm import LLMError


def test_answer_question_sends_paragraph_and_question():
    llm = FakeLLM(reply="Потому что холодно")

    answer = asyncio.run(answer_question(llm, "Текст про пещеры", "Почему жили в пещерах?"))

    system_prompt, user_content = llm.calls[0]
    assert system_prompt == DISCUSS_SYSTEM_PROMPT
    assert "Текст про пещеры" in user_content
    assert "Почему жили в пещерах?" in user_content
    assert answer == "Потому что холодно"


def test_discuss_question_goes_to_llm_with_paragraph(llm, ready_context, fake_bot):
    ready_context.stage = "discuss"
    llm.reply = "Потому что было холодно."
    event = FakeTextEvent("Почему люди жили в пещерах?")

    asyncio.run(text_handler(event))

    system_prompt, user_content = llm.calls[0]
    assert system_prompt == DISCUSS_SYSTEM_PROMPT
    assert ready_context.paragraph_text in user_content
    assert "Почему люди жили в пещерах?" in user_content
    assert event.answers[0]["text"] == "Думаю…"
    assert fake_bot.sent[0]["text"] == "Потому что было холодно."
    assert buttons(fake_bot.sent[1]["attachments"][0]) == [("В меню", "menu")]
    assert ready_context.stage == "discuss"


def test_discuss_llm_failure_is_reported(llm, ready_context, fake_bot):
    ready_context.stage = "discuss"
    llm.error = LLMError("boom")

    asyncio.run(text_handler(FakeTextEvent("Вопрос")))

    assert "Не удалось получить ответ от ИИ" in fake_bot.sent[0]["text"]
    assert buttons(fake_bot.sent[0]["attachments"][0]) == [("В меню", "menu")]


def test_long_llm_answer_is_split_into_chunks(monkeypatch, llm, ready_context, fake_bot):
    async def no_sleep(_):
        return None

    monkeypatch.setattr(messaging.asyncio, "sleep", no_sleep)
    ready_context.stage = "discuss"
    llm.reply = "а" * (MAX_MESSAGE_LENGTH + 10)

    asyncio.run(text_handler(FakeTextEvent("Вопрос")))

    assert [len(m["text"]) for m in fake_bot.sent[:2]] == [MAX_MESSAGE_LENGTH, 10]
