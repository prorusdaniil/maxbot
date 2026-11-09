<?php
declare(strict_types=1);

/** Общее подключение к MySQL/MariaDB (параметры DB_* из .env); в тестах подменяется через $GLOBALS['__db']. */
function db(): PDO
{
    if (!isset($GLOBALS['__db'])) {
        $user = env('DB_USER');
        if ($user === null) {
            throw new RuntimeException('Не задан DB_USER');
        }
        $host = env('DB_HOST', 'localhost');
        $name = env('DB_NAME', 'books');
        $GLOBALS['__db'] = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $GLOBALS['__db'];
}
