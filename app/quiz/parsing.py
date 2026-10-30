import json

from .models import QuizGenerationError, QuizState

MIN_OPTIONS = 2
MAX_OPTIONS = 6


def parse_quiz(raw: str) -> QuizState:
    """Разбирает JSON-ответ модели: {"question", "options", "correct"} (индексы с нуля)."""
    data = _load_json_object(raw)

    question = data.get("question")
    options = data.get("options")
    correct = data.get("correct")
    if not isinstance(question, str) or not question.strip():
        raise QuizGenerationError("В тесте нет вопроса")
    if (
        not isinstance(options, list)
        or not MIN_OPTIONS <= len(options) <= MAX_OPTIONS
        or not all(isinstance(option, str) and option.strip() for option in options)
    ):
        raise QuizGenerationError("Неверный список вариантов ответа")
    if (
        not isinstance(correct, list)
        or not correct
        or not all(isinstance(index, int) and 0 <= index < len(options) for index in correct)
        or len(set(correct)) >= len(options)
    ):
        raise QuizGenerationError("Неверно указаны правильные ответы")

    return QuizState(
        question=question.strip(),
        options=[option.strip() for option in options],
        correct=set(correct),
    )


def _load_json_object(raw: str) -> dict:
    # Модели часто оборачивают JSON в ```-блок или пояснения, поэтому берём срез по скобкам.
    start, end = raw.find("{"), raw.rfind("}")
    if start == -1 or end <= start:
        raise QuizGenerationError("В ответе модели нет JSON")
    try:
        data = json.loads(raw[start : end + 1])
    except json.JSONDecodeError as error:
        raise QuizGenerationError("Ответ модели не является корректным JSON") from error
    if not isinstance(data, dict):
        raise QuizGenerationError("Ответ модели не является JSON-объектом")
    return data
