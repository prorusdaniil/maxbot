from types import SimpleNamespace

import pytest

from app import runtime
from app.books import reset_books_cache
from app.state import UserContext, sessions

USER_ID = 1
CHAT_ID = 10


def buttons(attachment) -> list[tuple[str, str]]:
    """Плоский список (текст, payload) кнопок inline-клавиатуры."""
    return [(b.text, b.payload) for row in attachment.payload.buttons for b in row]


class FakeLLM:
    def __init__(self, reply: str = "ответ", error: Exception | None = None) -> None:
        self.reply = reply
        self.error = error
        self.calls: list[tuple[str, str]] = []

    async def complete(self, system_prompt: str, user_content: str) -> str:
        self.calls.append((system_prompt, user_content))
        if self.error is not None:
            raise self.error
        return self.reply


class FakeBot:
    def __init__(self) -> None:
        self.sent: list[dict] = []

    async def send_message(self, chat_id, text=None, attachments=None, **kwargs):
        self.sent.append({"chat_id": chat_id, "text": text, "attachments": attachments})


class FakeCallbackEvent:
    def __init__(self, payload: str) -> None:
        self.callback = SimpleNamespace(payload=payload)
        self.acks: list[str | None] = []
        self.edits: list[dict] = []

    def get_ids(self):
        return CHAT_ID, USER_ID

    async def ack(self, notification=None):
        self.acks.append(notification)

    async def edit(self, text=None, attachments=None, **kwargs):
        self.edits.append({"text": text, "attachments": attachments})


class FakeTextEvent:
    def __init__(self, text: str) -> None:
        self.answers: list[dict] = []
        outer = self

        class _Message:
            body = SimpleNamespace(text=text)

            async def answer(self, text, attachments=None, **kwargs):
                outer.answers.append({"text": text, "attachments": attachments})

        self.message = _Message()

    def get_ids(self):
        return CHAT_ID, USER_ID


@pytest.fixture(autouse=True)
def fake_bot():
    sessions.clear()
    reset_books_cache()
    runtime.set_llm(None)
    bot = FakeBot()
    runtime.set_bot(bot)
    yield bot
    runtime.set_bot(None)
    runtime.set_llm(None)


@pytest.fixture
def llm() -> FakeLLM:
    fake = FakeLLM()
    runtime.set_llm(fake)
    return fake


@pytest.fixture
def ready_context() -> UserContext:
    """Пользователь с загруженным параграфом."""
    context = UserContext(
        stage="ready",
        book_id=3,
        paragraph_id=2,
        paragraph_title="Первобытная эпоха",
        paragraph_text="Первые люди жили в пещерах.",
    )
    sessions.set(USER_ID, context)
    return context
