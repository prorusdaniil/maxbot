"""Локальный запуск бота в режиме webhook без токена MAX.

Бот принимает обновления в формате MAX по HTTP (см. http/webhook.http). Исходящие сообщения
уходят в имитацию MAX API и печатаются в консоль как чат. API книг и LLM настоящие (ключи из
.env, запросы к LLM пишутся в файл). Остальные логи уходят в файл, а не в консоль.
"""

import asyncio
import logging
import os

from aiohttp import web
from dotenv import load_dotenv
from maxapi import Bot, Dispatcher
from maxapi.webhook.aiohttp import AiohttpMaxWebhook

from app import runtime
from app.handlers import routers
from dev import fake_max_api
from llm import LLMConfigurationError, create_llm_client

DEFAULT_LLM_LOG = "logs/llm.log"
DEFAULT_DEV_LOG = "logs/dev.log"
CHAT_LOGGER = "dev.max_out"


def setup_logging(dev_log: str) -> None:
    """В консоль попадает только «чат» бота, все остальные логи — в файл."""
    os.makedirs(os.path.dirname(dev_log) or ".", exist_ok=True)
    file_handler = logging.FileHandler(dev_log, encoding="utf-8")
    file_handler.setFormatter(
        logging.Formatter("%(asctime)s %(levelname)s %(name)s: %(message)s")
    )
    console = logging.StreamHandler()
    console.setFormatter(logging.Formatter("%(message)s"))
    console.addFilter(lambda record: record.name == CHAT_LOGGER)
    logging.basicConfig(level=logging.INFO, handlers=[file_handler, console], force=True)


async def main() -> None:
    load_dotenv()
    setup_logging(os.getenv("DEV_LOG_FILE") or DEFAULT_DEV_LOG)
    logger = logging.getLogger(__name__)

    host = os.getenv("DEV_HOST", "127.0.0.1")
    port = int(os.getenv("DEV_PORT", "8080"))
    max_api_port = int(os.getenv("DEV_MAX_API_PORT", "8081"))
    secret = os.getenv("DEV_WEBHOOK_SECRET", "dev-secret")
    llm_log = os.getenv("LLM_LOG_FILE") or DEFAULT_LLM_LOG

    try:
        runtime.set_llm(create_llm_client({**os.environ, "LLM_LOG_FILE": llm_log}))
    except LLMConfigurationError as error:
        logger.warning("LLM не настроен, тест и обсуждение будут недоступны: %s", error)

    # Имитация MAX API должна слушать до старта вебхука: диспетчер при запуске вызывает GET /me.
    max_api = web.AppRunner(fake_max_api.create_app())
    await max_api.setup()
    await web.TCPSite(max_api, host, max_api_port).start()

    bot = Bot(token="dev-token")
    bot.set_api_url(f"http://{host}:{max_api_port}")
    runtime.set_bot(bot)

    dp = Dispatcher()
    dp.include_routers(*routers)
    app = AiohttpMaxWebhook(dp=dp, bot=bot, secret=secret).create_app(path="/webhook")

    runner = web.AppRunner(app)
    try:
        await runner.setup()
        await web.TCPSite(runner, host, port).start()
        logger.info("Dev-режим: webhook http://%s:%s/webhook, лог LLM: %s", host, port, llm_log)
        await asyncio.Event().wait()
    finally:
        await runner.cleanup()
        await max_api.cleanup()
        await bot.close_session()
