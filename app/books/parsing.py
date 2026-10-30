class ParagraphAPIError(ValueError):
    """Ответ API книги не содержит ожидаемых данных."""


def parse_paragraph(data: object) -> tuple[str, str]:
    """Проверяет JSON API и объединяет тексты всех частей параграфа."""
    if not isinstance(data, dict):
        raise ParagraphAPIError("API вернуло ответ не в формате JSON-объекта")

    title = data.get("title")
    parts = data.get("parts")
    if not isinstance(title, str) or not title.strip():
        raise ParagraphAPIError("В ответе API отсутствует название параграфа")
    if not isinstance(parts, list):
        raise ParagraphAPIError("В ответе API отсутствует список частей параграфа")

    texts: list[str] = []
    for part in parts:
        if not isinstance(part, dict) or not isinstance(part.get("text"), str):
            raise ParagraphAPIError("Одна из частей параграфа имеет неверный формат")
        if part["text"].strip():
            texts.append(part["text"].strip())

    paragraph_text = "\n\n".join(texts)
    if not paragraph_text:
        raise ParagraphAPIError("В ответе API нет текста параграфа")

    return title.strip(), paragraph_text


def parse_books(data: object) -> dict[int, str]:
    """Разбирает список книг API: [{"bookId": 3, "title": "..."}]."""
    if not isinstance(data, list):
        raise ParagraphAPIError("Каталог API вернул не список")
    books = {
        item["bookId"]: item["title"].strip()
        for item in data
        if isinstance(item, dict)
        and isinstance(item.get("bookId"), int)
        and isinstance(item.get("title"), str)
        and item["title"].strip()
    }
    if not books:
        raise ParagraphAPIError("В каталоге API нет книг")
    return books


def parse_feature_text(data: object, field: str, is_list: bool) -> str | None:
    """Достаёт из ответа API текст (summary) или список строк (main_points, answers); пусто -> None."""
    if not isinstance(data, dict):
        raise ParagraphAPIError("API вернуло ответ не в формате JSON-объекта")
    value = data.get(field)
    if value is None:
        return None

    if not is_list:
        if not isinstance(value, str):
            raise ParagraphAPIError(f"Поле {field} должно быть строкой")
        return value.strip() or None

    if not isinstance(value, list) or not all(isinstance(item, str) for item in value):
        raise ParagraphAPIError(f"Поле {field} должно быть массивом строк")
    items = [item.strip() for item in value if item.strip()]
    if not items:
        return None
    return "\n\n".join(f"{number}. {item}" for number, item in enumerate(items, 1))
