"""Минимальная имитация MAX Bot API: принимает исходящие сообщения бота и печатает их в консоль как чат."""

import itertools
import logging
import time

from aiohttp import web

logger = logging.getLogger("dev.max_out")
_mid = itertools.count(1)
BOT_USER = {"user_id": 1000, "first_name": "Dev Bot", "is_bot": True, "last_activity_time": 0}


def _format_attachments(attachments: list[dict]) -> str:
    lines = []
    for attachment in attachments:
        if attachment.get("type") != "inline_keyboard":
            lines.append(f"  [вложение: {attachment.get('type')}]")
            continue
        for row in attachment["payload"]["buttons"]:
            lines.append("  " + "  ".join(f"[ {b['text']} ]→{b.get('payload')}" for b in row))
    return "\n".join(lines)


def _log(title: str, text: str | None, attachments: list[dict]) -> None:
    parts = [title, text or ""]
    if attachments:
        parts.append(_format_attachments(attachments))
    logger.info("\n".join(p for p in parts if p) + "\n" + "─" * 60)


async def send_message(request: web.Request) -> web.Response:
    body = await request.json()
    attachments = body.get("attachments") or []
    _log("🤖 Бот:", body.get("text"), attachments)
    chat_id = int(request.query.get("chat_id") or request.query.get("user_id") or 0)
    return web.json_response(
        {
            "message": {
                "sender": BOT_USER,
                "recipient": {"chat_id": chat_id, "chat_type": "dialog"},
                "timestamp": int(time.time() * 1000),
                "body": {
                    "mid": f"mid.dev.{next(_mid)}",
                    "seq": next(_mid),
                    "text": body.get("text"),
                    "attachments": attachments,
                },
            }
        }
    )


async def answer_callback(request: web.Request) -> web.Response:
    body = await request.json()
    message = body.get("message")
    if message:
        _log(
            "🤖 Бот (изменил своё сообщение):",
            message.get("text"),
            message.get("attachments") or [],
        )
    if body.get("notification"):
        logger.info("🤖 Бот (всплывающее уведомление): %s\n%s", body["notification"], "─" * 60)
    return web.json_response({"success": True})


async def get_me(request: web.Request) -> web.Response:
    return web.json_response({**BOT_USER, "username": "dev_bot"})


async def get_chat(request: web.Request) -> web.Response:
    return web.json_response(
        {
            "chat_id": int(request.match_info["chat_id"]),
            "type": "dialog",
            "status": "active",
            "last_event_time": int(time.time() * 1000),
            "participants_count": 2,
            "is_public": False,
        }
    )


def create_app() -> web.Application:
    app = web.Application()
    app.router.add_get("/me", get_me)
    app.router.add_get("/chats/{chat_id}", get_chat)
    app.router.add_post("/messages", send_message)
    app.router.add_post("/answers", answer_callback)
    return app
