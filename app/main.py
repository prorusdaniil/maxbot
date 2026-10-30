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


def build_dispatcher() -> Dispatcher:
    dp = Dispatcher()
    dp.include_routers(*routers)
    return dp


async def main() -> None:
    """Проверяет конфигурацию и запускает polling для локальной разработки."""
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
    try:
        runtime.get_llm()
    except LLMConfigurationError as error:
        logger.warning("ИИ не настроен, тест и обсуждение параграфа недоступны: %s", error)

    bot = Bot(token=token)
    runtime.set_bot(bot)
    try:
        # Polling подходит для локального тестирования; для production настройте webhook.
        await build_dispatcher().start_polling(bot)
    except InvalidToken:
        raise SystemExit(
            "MAX отклонил MAX_BOT_TOKEN. Проверьте, что в .env указан актуальный "
            "токен доступа именно этого бота MAX (а не его ID или ключ LLM). "
            "При необходимости выпустите новый токен в настройках бота и замените "
            "значение MAX_BOT_TOKEN."
        ) from None
    finally:
        await bot.close_session()
