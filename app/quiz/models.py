from dataclasses import dataclass, field


class QuizGenerationError(ValueError):
    """LLM вернула тест в неверном формате."""


@dataclass
class QuizState:
    question: str
    options: list[str]
    correct: set[int]
    selected: set[int] = field(default_factory=set)
    finished: bool = False
