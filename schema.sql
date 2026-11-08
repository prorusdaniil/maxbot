-- Состояние диалогов бота. Таблица создаётся автоматически при первом запросе
-- к webhook.php; этот файл нужен, если у пользователя БД нет права CREATE.
CREATE TABLE IF NOT EXISTS bot_sessions (
    user_id BIGINT NOT NULL PRIMARY KEY,
    data LONGTEXT NOT NULL,
    updated_at INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
