from dataclasses import dataclass


@dataclass(frozen=True)
class Feature:
    key: str
    button_title: str
    stub_title: str


# Функции, которые обслуживает API книги через параметр action. Эндпоинты пока не созданы.
API_FEATURES: dict[str, Feature] = {
    feature.key: feature
    for feature in (
        Feature("summary", "Пересказ простыми словами", "Пересказ параграфа"),
        Feature("answers", "Ответы на вопросы учебника", "Ответы на вопросы по учебнику"),
        Feature("theses", "Основные тезисы", "Основные тезисы параграфа"),
    )
}
