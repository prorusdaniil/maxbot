import asyncio

import httpx
import pytest

from app.books import api as books_api


def patch_http(monkeypatch, handler):
    transport = httpx.MockTransport(handler)
    real_client = httpx.AsyncClient
    monkeypatch.setattr(books_api.httpx, "AsyncClient", lambda **kw: real_client(transport=transport, **kw))


@pytest.mark.parametrize(
    ("feature", "path", "payload", "expected"),
    [
        ("summary", "summary", {"summary": " Пересказ "}, "Пересказ"),
        ("theses", "main_points", {"main_points": ["Один", " Два "]}, "1. Один\n\n2. Два"),
        ("answers", "answers", {"answers": ["Ответ"]}, "1. Ответ"),
    ],
)
def test_feature_uses_own_endpoint_and_field(monkeypatch, feature, path, payload, expected):
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["path"] = request.url.path
        seen["params"] = dict(request.url.params)
        return httpx.Response(200, json=payload)

    patch_http(monkeypatch, handler)

    text = asyncio.run(books_api.fetch_feature_text(3, 2, feature))

    assert seen == {"path": f"/book-parser/api.php/{path}", "params": {"bookId": "3", "paragraphId": "2"}}
    assert text == expected


@pytest.mark.parametrize("feature", ["summary", "answers", "theses"])
def test_feature_missing_data_returns_explanation(monkeypatch, feature):
    patch_http(monkeypatch, lambda request: httpx.Response(404, json={"error": "Data not found"}))

    assert "пока нет" in asyncio.run(books_api.fetch_feature_text(3, 2, feature))


@pytest.mark.parametrize("payload", [{}, {"answers": []}, {"answers": [" "]}])
def test_feature_empty_data_returns_explanation(monkeypatch, payload):
    patch_http(monkeypatch, lambda request: httpx.Response(200, json=payload))

    assert "пока нет" in asyncio.run(books_api.fetch_feature_text(3, 2, "answers"))


@pytest.mark.parametrize("payload", [{"answers": "строка"}, {"answers": [1]}, ["x"]])
def test_feature_malformed_data_raises(monkeypatch, payload):
    patch_http(monkeypatch, lambda request: httpx.Response(200, json=payload))

    with pytest.raises(ValueError):
        asyncio.run(books_api.fetch_feature_text(3, 2, "answers"))


@pytest.mark.parametrize("status", [400, 500])
def test_feature_server_error_propagates(monkeypatch, status):
    patch_http(monkeypatch, lambda request: httpx.Response(status))

    with pytest.raises(httpx.HTTPStatusError):
        asyncio.run(books_api.fetch_feature_text(3, 2, "theses"))


def test_books_are_cached(monkeypatch):
    calls = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(str(request.url))
        return httpx.Response(200, json=[{"bookId": 3, "title": "История 6 класс"}])

    patch_http(monkeypatch, handler)

    first = asyncio.run(books_api.fetch_books())
    second = asyncio.run(books_api.fetch_books())

    assert first == second == {3: "История 6 класс"}
    assert calls == ["https://counters.goweb.kz/bookparser/api/books"]


def test_books_failure_without_cache_raises(monkeypatch):
    patch_http(monkeypatch, lambda request: httpx.Response(500))

    with pytest.raises(httpx.HTTPStatusError):
        asyncio.run(books_api.fetch_books())
