import logging
import time

import httpx

from app.config import BOOK_API_URL, BOOKS_API_URL, BOOKS_CACHE_TTL, HTTP_TIMEOUT

from .features import API_FEATURES
from .parsing import parse_books, parse_feature_text, parse_paragraph

logger = logging.getLogger(__name__)

_books_cache: tuple[float, dict[int, str]] | None = None


def reset_books_cache() -> None:
    global _books_cache
    _books_cache = None


async def fetch_books() -> dict[int, str]:
    """Получает список книг из API; при сбое отдаёт устаревший кэш, если он есть."""
    global _books_cache

    now = time.monotonic()
    if _books_cache is not None and now - _books_cache[0] < BOOKS_CACHE_TTL:
        return _books_cache[1]

    try:
        async with httpx.AsyncClient(timeout=HTTP_TIMEOUT) as client:
            response = await client.get(BOOKS_API_URL)
            response.raise_for_status()
            books = parse_books(response.json())
    except (httpx.HTTPError, ValueError):
        if _books_cache is None:
            raise
        logger.exception("Failed to refresh book list, using stale cache")
        return _books_cache[1]

    _books_cache = (now, books)
    return books


async def fetch_paragraph(book_id: int, paragraph_id: int) -> tuple[str, str]:
    """Получает параграф и объединяет тексты всех его частей."""
    async with httpx.AsyncClient(timeout=HTTP_TIMEOUT) as client:
        response = await client.get(
            BOOK_API_URL,
            params={"bookId": book_id, "paragraphId": paragraph_id},
        )
        response.raise_for_status()
        data = response.json()

    return parse_paragraph(data)


async def fetch_feature_text(book_id: int, paragraph_id: int, feature: str) -> str:
    """Запрашивает у API пересказ, ответы или тезисы параграфа; если данных нет, возвращает пояснение."""
    spec = API_FEATURES[feature]
    try:
        async with httpx.AsyncClient(timeout=HTTP_TIMEOUT) as client:
            response = await client.get(
                f"{BOOK_API_URL}/{spec.endpoint}",
                params={"bookId": book_id, "paragraphId": paragraph_id},
            )
            response.raise_for_status()
            data = response.json()
    except httpx.HTTPStatusError as error:
        if error.response.status_code == 404:
            return spec.missing_text
        raise

    return parse_feature_text(data, spec.endpoint, spec.is_list) or spec.missing_text
