import asyncio
import json

import httpx
import pytest

from llm import DeepSeekClient, LLMError


def make_client(handler, **kwargs) -> DeepSeekClient:
    return DeepSeekClient("secret-key", transport=httpx.MockTransport(handler), **kwargs)


def test_sends_openai_compatible_request():
    captured = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["url"] = str(request.url)
        captured["auth"] = request.headers["Authorization"]
        captured["body"] = json.loads(request.content)
        return httpx.Response(200, json={"choices": [{"message": {"content": " Привет "}}]})

    result = asyncio.run(make_client(handler, model="deepseek-flash").complete("система", "пользователь"))

    assert result == "Привет"
    assert captured["url"] == "https://api.deepseek.com/chat/completions"
    assert captured["auth"] == "Bearer secret-key"
    assert captured["body"] == {
        "model": "deepseek-flash",
        "messages": [
            {"role": "system", "content": "система"},
            {"role": "user", "content": "пользователь"},
        ],
        "stream": False,
    }


def test_custom_base_url():
    urls = []

    def handler(request: httpx.Request) -> httpx.Response:
        urls.append(str(request.url))
        return httpx.Response(200, json={"choices": [{"message": {"content": "ok"}}]})

    asyncio.run(make_client(handler, base_url="https://proxy.local/v1/").complete("s", "u"))

    assert urls == ["https://proxy.local/v1/chat/completions"]


@pytest.mark.parametrize(
    "response",
    [
        httpx.Response(401, json={"error": "bad key"}),
        httpx.Response(500),
        httpx.Response(200, text="не json"),
        httpx.Response(200, json={"choices": []}),
        httpx.Response(200, json={"unexpected": True}),
        httpx.Response(200, json={"choices": [{"message": {"content": "  "}}]}),
        httpx.Response(200, json={"choices": [{"message": {"content": None}}]}),
    ],
)
def test_errors_are_wrapped(response):
    with pytest.raises(LLMError):
        asyncio.run(make_client(lambda request: response).complete("s", "u"))


def test_network_error_does_not_leak_api_key():
    def handler(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("connection refused")

    with pytest.raises(LLMError) as error:
        asyncio.run(make_client(handler).complete("s", "u"))

    assert "secret-key" not in str(error.value)
