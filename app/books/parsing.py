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


def parse_feature_text(data: object) -> str | None:
    """Контракт будущих эндпоинтов: {"text": "..."}; иначе None (эндпоинт ещё не реализован)."""
    if isinstance(data, dict) and isinstance(data.get("text"), str) and data["text"].strip():
        return data["text"].strip()
    return None
