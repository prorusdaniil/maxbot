-- Состояние диалогов бота. Таблица создаётся автоматически при первом запросе
-- к webhook.php; этот файл нужен, если у пользователя БД нет права CREATE.
CREATE TABLE IF NOT EXISTS bot_sessions (
    user_id BIGINT NOT NULL PRIMARY KEY,
    data LONGTEXT NOT NULL,
    updated_at INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Статистика использования функций бота (создаётся автоматически вместе с bot_sessions).
CREATE TABLE IF NOT EXISTS stats (
    created_at DATETIME NOT NULL,
    user_id BIGINT NOT NULL,
    user_name VARCHAR(255) NULL,
    book_id INT NULL,
    paragraph_id INT NULL,
    feature VARCHAR(32) NOT NULL,
    tokens_used INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
