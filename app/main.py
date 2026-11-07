import asyncio
import logging
import os

from dotenv import load_dotenv
from maxapi import Bot, Dispatcher
from maxapi.exceptions import InvalidToken

from app import runtime
from app.handlers import routers
from llm import LLMConfigurationError

logger = logging.getLogger(__name__)


DEFAULT_HOST = "0.0.0.0"
DEFAULT_PORT = 8000
DEFAULT_PATH = "/webhook"


def build_dispatcher() -> Dispatcher:
    dp = Dispatcher()
    dp.include_routers(*routers)
    return dp


async def main() -> None:
    """Проверяет конфигурацию и запускает webhook-сервер, принимающий обновления MAX."""
    load_dotenv()
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )

    token = os.getenv("MAX_BOT_TOKEN", "").strip()
    if not token:
        raise SystemExit(
            "Не задан MAX_BOT_TOKEN. Создайте .env по образцу .env.example "
            "и укажите токен бота MAX."
        )
    secret = os.getenv("MAX_WEBHOOK_SECRET", "").strip()
    if not secret:
        raise SystemExit(
            "Не задан MAX_WEBHOOK_SECRET. Укажите в .env тот же секрет, который "
            "передан в поле secret при установке webhook (POST /subscriptions)."
        )
    host = os.getenv("WEBHOOK_HOST", DEFAULT_HOST)
    port = int(os.getenv("WEBHOOK_PORT", DEFAULT_PORT))
    path = os.getenv("WEBHOOK_PATH", DEFAULT_PATH)

    try:
        runtime.get_llm()
    except LLMConfigurationError as error:
        logger.warning("ИИ не настроен, тест и обсуждение параграфа недоступны: %s", error)

    bot = Bot(token=token)
    runtime.set_bot(bot)
    try:
        # Адрес сервера MAX узнаёт из подписки (POST /subscriptions), бот её не создаёт.
        await build_dispatcher().handle_webhook(
            bot, host=host, port=port, path=path, secret=secret
        )
    except InvalidToken:
        raise SystemExit(
            "MAX отклонил MAX_BOT_TOKEN. Проверьте, что в .env указан актуальный "
            "токен доступа именно этого бота MAX (а не его ID или ключ LLM). "
            "При необходимости выпустите новый токен в настройках бота и замените "
            "значение MAX_BOT_TOKEN."
        ) from None
    finally:
        await bot.close_session()
