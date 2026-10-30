from dataclasses import dataclass
from typing import Literal

from app.quiz import QuizState

Stage = Literal["choose_book", "enter_paragraph", "ready", "discuss"]


@dataclass
class UserContext:
    """Данные текущего диалога пользователя."""

    stage: Stage = "choose_book"
    book_id: int | None = None
    paragraph_id: int | None = None
    paragraph_title: str = ""
    paragraph_text: str = ""
    quiz: QuizState | None = None


class SessionStore:
    """Состояние диалогов в памяти процесса; сбрасывается при перезапуске."""

    def __init__(self) -> None:
        self._contexts: dict[int, UserContext] = {}

    def get(self, user_id: int) -> UserContext | None:
        return self._contexts.get(user_id)

    def set(self, user_id: int, context: UserContext) -> None:
        self._contexts[user_id] = context

    def clear(self) -> None:
        self._contexts.clear()


sessions = SessionStore()
