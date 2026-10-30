from dataclasses import dataclass


@dataclass(frozen=True)
class Feature:
    key: str
    button_title: str
    endpoint: str
    is_list: bool
    missing_text: str


# Каждая функция обслуживается отдельным эндпоинтом API; ответ: {"<endpoint>": текст | [строки]}.
API_FEATURES: dict[str, Feature] = {
    feature.key: feature
    for feature in (
        Feature("summary", "Пересказ простыми словами", "summary", False,
                "Для этого параграфа пока нет пересказа."),
        Feature("answers", "Ответы на вопросы учебника", "answers", True,
                "Для этого параграфа пока нет ответов на вопросы."),
        Feature("theses", "Основные тезисы", "main_points", True,
                "Для этого параграфа пока нет тезисов."),
    )
}
