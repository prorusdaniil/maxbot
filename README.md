# MAX-бот для пересказа параграфов

Сервер бота работает в Docker (PHP 8.3 + Apache). База данных MySQL/MariaDB внешняя, в контейнер не входит.

## Установка

1. Скопируйте `.env.example` в `.env` и заполните:
   - `MAX_BOT_TOKEN` — токен бота MAX;
   - `MAX_WEBHOOK_SECRET` — секрет вебхука (символы A-Z, a-z, 0-9, `_`, `-`);
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — доступ к удалённой базе данных;
   - ключ ИИ: `DEEPSEEK_API_KEY` (или `GIGACHAT_CREDENTIALS` и `LLM_PROVIDER=gigachat`).

## Запуск сервера

```
docker compose up -d --build
```

Сервер слушает порт `8080` (`http://localhost:8080/webhook.php`). Логи бота пишутся в `logs/bot.log`.
Остановка: `docker compose down`.

## Прописать вебхук в MAX

MAX принимает вебхук только по HTTPS на порту 443, поэтому опубликуйте сервер на своём домене
(например, через nginx/Caddy с TLS-сертификатом, проксирующий на порт `8080`). Затем выполните:

```
docker compose exec bot php set_webhook.php --url=https://<домен>/webhook.php
```

Проверить подписку:

```
docker compose exec bot php set_webhook.php --list
```

Удалить подписку:

```
docker compose exec bot php set_webhook.php --delete=https://<домен>/webhook.php
```

## Размещение на внешнем хостинге по FTP

Требования к хостингу: Apache с поддержкой `.htaccess` (`AllowOverride`), PHP 8.1+ с расширениями
`curl`, `mbstring`, `pdo_mysql`, HTTPS-домен.

1. Заполните `.env` локально (см. «Установка»).
2. Подключитесь к хостингу по FTP/SFTP (FileZilla, WinSCP и т. п.) и загрузите в папку сайта
   (например, `public_html/maxbot/`):
   - `webhook.php`, `api.php`, `set_webhook.php`, `generate_details.php`, `schema.sql`;
   - папки `src/` и `certs/`;
   - `.htaccess` и `.env` (скрытые файлы: включите в FTP-клиенте показ скрытых файлов);
   - пустую папку `logs/` вместе с `logs/.htaccess`, права на запись для PHP (`755` или `775`).

   Не загружайте `.git/`, `tests/`, `http/`, `Dockerfile`, `docker-compose.yml`, `.dockerignore`.
3. Проверьте, что `https://<домен>/<путь>/.env` отдаёт `403`/`404`, а не содержимое файла.
   Если файл открывается, `.htaccess` не работает: включите `AllowOverride` у хостинга.
4. Пропишите вебхук (см. выше). Если на хостинге нет SSH, выполните команду со своего компьютера
   с тем же `.env` — ей нужен только доступ к MAX API:

   ```
   docker compose run --rm bot php set_webhook.php --url=https://<домен>/<путь>/webhook.php
   ```

   Адрес вебхука должен вести на загруженный `webhook.php`.
5. При обновлении бота заново загрузите изменённые файлы; `.env` перезаписывайте только при
   изменении настроек.
