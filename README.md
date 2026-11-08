# MAX-бот для пересказа параграфов

Бот на PHP (Apache + MySQL/MariaDB) предлагает выбрать книгу (список берётся из
`https://counters.goweb.kz/book-parser/api/books`), загружает указанный параграф и предлагает функции:

- пересказ, ответы на вопросы учебника и основные тезисы — отдельные эндпоинты
  `https://counters.goweb.kz/book-parser/api.php/{summary|answers|main_points}?bookId=..&paragraphId=..`.
  Ответ: `{"summary": "текст"}`, `{"answers": ["..."]}` или `{"main_points": ["..."]}`;
  списки бот выводит нумерованными. Если данных для параграфа нет (404), бот сообщает об этом;
- случайный факт — эндпоинт `https://counters.goweb.kz/book-parser/api/random-fact`;
  бот показывает имя и факт из ответа `{"name": "...", "fact": "..."}`;
- тест по теме — ИИ генерирует вопрос с вариантами (один или несколько
  верных), ответ выбирается кнопками и проверяется кнопкой «Проверить»
  (в MAX нет нативных опросов);
- обсуждение параграфа — вопрос пользователя вместе с текстом параграфа
  уходит в ИИ.

ИИ: DeepSeek и GigaChat. Провайдер задаётся `LLM_PROVIDER=deepseek|gigachat`; если он не указан,
выбирается DeepSeek при наличии `DEEPSEEK_API_KEY`, иначе GigaChat.

## Структура проекта

```
webhook.php          webhook бота: проверка секрета, ответ MAX 200 и обработка обновления
set_webhook.php      CLI: подписка бота на webhook (POST /subscriptions)
api.php              API книг (CRUD, пересказ, ответы, тезисы, случайный факт)
generate_details.php CLI: генерация пересказа, ответов и тезисов для параграфов
schema.sql           таблица bot_sessions (создаётся и автоматически)
src/
  bootstrap.php      подключение модулей и загрузка .env
  Env.php            чтение .env, env()
  Http.php           curl-запросы
  Log.php            лог бота (logs/bot.log)
  MaxApi.php         клиент MAX Bot API и inline-клавиатуры
  Books.php          API книг: запросы, разбор ответов, функции
  Quiz.php           тест: модель, разбор JSON от ИИ, текст
  Llm.php            DeepSeek, GigaChat, лог запросов к ИИ, обсуждение параграфа
  Sessions.php       состояние диалогов в MySQL
  Bot.php            обработчики сообщений и кнопок
tests/run.php        проверка логики бота без сети и MySQL
http/webhook.http    примеры вебхуков MAX для ручной проверки
```

Требуется PHP 8.1+ с расширениями `curl`, `mbstring`, `pdo_mysql`.

## Установка

1. Загрузите файлы на сервер. Webhook доступен по `https://<домен>/<путь>/webhook.php`
   (MAX принимает только HTTPS на порту 443).
2. Скопируйте `.env.example` в `.env` и заполните: `MAX_BOT_TOKEN`, `MAX_WEBHOOK_SECRET`
   (A-Z, a-z, 0-9, `_`, `-`), `DB_*`, ключ ИИ (`DEEPSEEK_API_KEY` или GigaChat). Не добавляйте
   реальные ключи в репозиторий: `.env` исключён через `.gitignore`. Файлы `.htaccess`
   закрывают `.env`, `src/` и `logs/` от доступа по HTTP — проверьте, что на сервере разрешён
   `AllowOverride`, либо запретите доступ в конфигурации Apache сами.
3. Таблица `bot_sessions` создаётся автоматически при первом обновлении. Если у пользователя БД нет
   права `CREATE`, выполните `schema.sql` вручную.
4. Подпишите бота на webhook (из консоли сервера):

   ```
   php set_webhook.php --url=https://<домен>/<путь>/webhook.php
   php set_webhook.php --list
   ```

   Подписка задаёт `update_types` `message_created`, `message_callback`, `bot_started` и секрет
   из `MAX_WEBHOOK_SECRET`; webhook проверяет его в заголовке `X-Max-Bot-Api-Secret`.
5. Корневой сертификат Минцифры (нужен для MAX API и GigaChat) лежит в `certs/russian_trusted_root_ca.pem`
   и подключается автоматически; другой файл можно указать в `RUSSIAN_CA_BUNDLE`. Не отключайте проверку TLS.
6. Если curl на сервере собран с NSS (`curl_version()`: `NSS/...`, например CentOS 7), он не разбирает
   сертификат MAX (ошибка `Unrecognized Object Identifier`). Бот сам переключается на потоки PHP
   (расширение `openssl`, нужен `allow_url_fopen=On`); принудительно: `HTTP_TRANSPORT=stream|curl`.
7. Диагностика сервера: загрузите `diag.php` и откройте `diag.php?key=<MAX_WEBHOOK_SECRET>`; после
   проверки удалите файл.

При `/start` бот показывает книги из каталога API. После выбора книги отправьте номер параграфа.

## Как это работает

- `webhook.php` сразу отвечает MAX `200`, а обновление обрабатывает после закрытия соединения
  (запросы к ИИ могут занимать десятки секунд, MAX ждёт ответ не дольше 30 с).
- Состояние диалога хранится в `bot_sessions`, потому что PHP не держит память между запросами.
  Обновления одного пользователя обрабатываются по очереди (`GET_LOCK`).
- Каталог книг кэшируется на час во временном каталоге сервера.
- Ошибки и предупреждения пишутся в `logs/bot.log` (`BOT_LOG_FILE`). Все запросы к ИИ можно
  писать в читаемом виде в файл, задав `LLM_LOG_FILE=logs/llm.log`.

## Проверка

Логика бота (диалог, тест, обсуждение, разбор ответов API) проверяется без сети и MySQL:

```
php tests/run.php
```

Для ручной проверки используйте запросы из [http/webhook.http](http/webhook.http)
(VS Code REST Client или JetBrains HTTP Client): укажите свой домен и `MAX_WEBHOOK_SECRET`,
а `user_id`/`chat_id` — реального пользователя и диалог с ботом. Id книги и номер параграфа в
файле (`book:3`, `1`) замените на существующие в вашем каталоге.
