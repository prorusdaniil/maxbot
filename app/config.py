"""Адреса внешних API и ограничения."""

BOOK_API_URL = "https://counters.goweb.kz/book-parser/api.php"
BOOKS_API_URL = "https://counters.goweb.kz/book-parser/api/books"

HTTP_TIMEOUT = 20.0
BOOKS_CACHE_TTL = 3600.0

# Лимит MAX на текст сообщения — 4000 символов, берём с запасом.
MAX_MESSAGE_LENGTH = 3900
