# MAX-бот для пересказа параграфов

Бот на Python предлагает выбрать книгу (список берётся из
`https://counters.goweb.kz/book-parser/api/books`), загружает указанный параграф и предлагает функции:

- пересказ, ответы на вопросы учебника и основные тезисы — отдельные эндпоинты
  `https://counters.goweb.kz/book-parser/api.php/{summary|answers|main_points}?bookId=..&paragraphId=..`.
  Ответ: `{"summary": "текст"}`, `{"answers": ["..."]}` или `{"main_points": ["..."]}`;
  списки бот выводит нумерованными. Если данных для параграфа нет (404), бот сообщает об этом;
- тест по теме — ИИ генерирует вопрос с вариантами (один или несколько
  верных), ответ выбирается кнопками и проверяется кнопкой «Проверить»
  (в MAX нет нативных опросов);
- обсуждение параграфа — вопрос пользователя вместе с текстом параграфа
  уходит в ИИ.

ИИ подключается через адаптеры в папке `llm/` (DeepSeek и GigaChat). Провайдер задаётся
`LLM_PROVIDER=deepseek|gigachat`; если он не указан, выбирается DeepSeek при наличии
`DEEPSEEK_API_KEY`, иначе GigaChat. SDK GigaChat импортируется только при его использовании.

## Структура проекта

```
main.py              точка входа
llm/                 адаптеры LLM, не зависят от бота
  base.py            LLMClient (интерфейс) и ошибки
  deepseek.py        DeepSeekClient
  gigachat.py        GigaChatClient
  factory.py         create_llm_client — выбор провайдера по .env
  call_log.py        LoggingLLMClient — запись запросов к LLM в файл
dev/                 локальный запуск без токенов (python -m dev)
http/webhook.http    примеры вебхуков MAX для ручной проверки
app/
  main.py            запуск polling
  config.py          URL и константы
  runtime.py         клиенты MAX и LLM
  state.py           состояние диалогов
  keyboards.py       inline-клавиатуры
  messaging.py       отправка сообщений
  discussion.py      обсуждение параграфа (промпт + запрос)
  books/             API книг (запросы, разбор ответов, функции)
  quiz/              тест: модель, разбор JSON, текст, генерация
  handlers/          обработчики сообщений и кнопок
tests/
```

## Установка

Требуется Python 3.10–3.13.

```powershell
python -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r requirements.txt
```

## Настройка и запуск в PowerShell

Скопируйте пример настроек и внесите свои ключи:

```powershell
Copy-Item .env.example .env
notepad .env
.\.venv\Scripts\python.exe main.py
```

Впишите в `.env` токен бота MAX и ключ DeepSeek (или Authorization Key GigaChat). Не добавляйте
реальные ключи в репозиторий: `.env` исключён через `.gitignore`. Без ключа ИИ
бот запустится, но тест и обсуждение параграфа будут недоступны.

Установите доверенный сертификат Минцифры, если этого требует ваша среда для
соединения с MAX/GigaChat.

При `/start` бот показывает книги из каталога API. После выбора
книги отправьте номер параграфа.

## Проверка без токена MAX (dev-режим)

Dev-режим поднимает бота как webhook-сервер и имитирует только MAX API: исходящие сообщения
бота печатаются в консоль вместе с кнопками, как чат. Токен MAX не нужен. Книги и LLM
настоящие: ключи берутся из `.env`, как в обычном запуске.

```powershell
.\.venv\Scripts\python.exe -m dev
```

Затем отправляйте запросы из [http/webhook.http](http/webhook.http) (VS Code REST Client или
JetBrains HTTP Client) по порядку — от `/start` до обсуждения параграфа. Файл содержит
обновления в формате вебхука MAX: `message_created` (текст) и `message_callback` (нажатие
кнопки, `callback.payload` — то, что бот положил в кнопку). Id книги и номер параграфа в
файле (`book:3`, `606`) замените на существующие в вашем каталоге. Заголовок
`X-Max-Bot-Api-Secret` должен совпадать с `DEV_WEBHOOK_SECRET` (по умолчанию `dev-secret`).

В консоль выводится только то, что пользователь видит в чате. Остальные логи (включая
ошибки) пишутся в `logs/dev.log` (`DEV_LOG_FILE`). Все запросы к LLM пишутся в
`logs/llm.log` в читаемом виде: системный промпт, текст пользователя и ответ (или ошибка).
Путь меняется переменной `LLM_LOG_FILE`; она работает и в обычном режиме.

Порты dev-режима:

- `DEV_HOST`, `DEV_PORT` (8080, webhook), `DEV_MAX_API_PORT` (8081, имитация MAX).

## Тесты

```powershell
python -m pip install -r requirements-dev.txt
python -m pytest
```

Бот использует polling для локального запуска. В документации MAX polling
предназначен для разработки и тестирования; для production следует настроить
webhook. Состояние диалога хранится в памяти процесса и сбрасывается при перезапуске.
