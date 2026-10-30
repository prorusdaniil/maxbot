from .models import QuizState


def format_quiz_text(title: str, quiz: QuizState, *, show_result: bool = False) -> str:
    lines = [f"Тест по теме «{title}»", "", quiz.question, ""]
    for index, option in enumerate(quiz.options):
        lines.append(f"{_result_mark(quiz, index) if show_result else ''}{index + 1}. {option}")

    lines.append("")
    lines.append(_result_summary(quiz) if show_result else _instruction(quiz))
    return "\n".join(lines)


def _result_mark(quiz: QuizState, index: int) -> str:
    if index in quiz.correct:
        return "✅ "
    if index in quiz.selected:
        return "❌ "
    return ""


def _result_summary(quiz: QuizState) -> str:
    if quiz.selected == quiz.correct:
        return "Верно!"
    correct = ", ".join(str(index + 1) for index in sorted(quiz.correct))
    return f"Есть ошибки. Правильные ответы: {correct}."


def _instruction(quiz: QuizState) -> str:
    return f"Правильных вариантов: {len(quiz.correct)}. Отметь их и нажми «Проверить»."
