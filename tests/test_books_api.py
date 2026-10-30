import asyncio

import httpx
import pytest

from app.books import api as books_api


def patch_http(monkeypatch, handler):
    transport = httpx.MockTransport(handler)
    real_client = httpx.AsyncClient
    monkeypatch.setattr(books_api.httpx, "AsyncClient", lambda **kw: real_client(transport=transport, **kw))


def test_feature_request_passes_book_paragraph_and_action(monkeypatch):
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen.update(request.url.params)
        return httpx.Response(200, json={"bookId": 3, "title": "Параграф", "parts": []})

    patch_http(monkeypatch, handler)

    text = asyncio.run(books_api.fetch_feature_text(3, 2, "summary"))

    assert seen == {"bookId": "3", "paragraphId": "2", "action": "summary"}
    assert "скоро появится" in text


@pytest.mark.parametrize("status", [404, 501])
def test_feature_stub_on_missing_endpoint(monkeypatch, status):
    patch_http(monkeypatch, lambda request: httpx.Response(status))

    assert "скоро появится" in asyncio.run(books_api.fetch_feature_text(3, 2, "theses"))


def test_feature_server_error_propagates(monkeypatch):
    patch_http(monkeypatch, lambda request: httpx.Response(500))

    with pytest.raises(httpx.HTTPStatusError):
        asyncio.run(books_api.fetch_feature_text(3, 2, "theses"))


def test_feature_returns_text_when_endpoint_exists(monkeypatch):
    patch_http(monkeypatch, lambda request: httpx.Response(200, json={"text": " Пересказ "}))

    assert asyncio.run(books_api.fetch_feature_text(3, 2, "summary")) == "Пересказ"


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
