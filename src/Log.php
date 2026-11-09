<?php
declare(strict_types=1);

const LOG_LEVELS = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];

/** Запоминает id пользователя MAX, который попадёт во все последующие строки лога (null — сброс). */
function setLogUser(?int $userId, ?string $userName = null): void
{
    $GLOBALS['__log_user_id'] = $userId;
    $GLOBALS['__log_user_name'] = $userName;
}

/** Пишет строку, учитывая id и username текущего пользователя (пробелы в имени заменяются на «_»). */
function logUserFields(): array
{
    $name = $GLOBALS['__log_user_name'] ?? null;
    return [$GLOBALS['__log_user_id'] ?? '-', $name === null ? '-' : preg_replace('/\s+/u', '_', $name)];
}

/** Статистика использования: строка в таблице stats; отключается STATS_ENABLED=false. Сбой записи не мешает боту. */
function statsLog(string $feature, int $tokens, ?int $bookId = null, ?int $paragraphId = null): void
{
    if (in_array(strtolower(env('STATS_ENABLED', 'true') ?? 'true'), ['0', 'false', 'off', 'no'], true)) {
        return;
    }
    $userId = $GLOBALS['__log_user_id'] ?? null;
    if ($userId === null) {
        return;
    }
    try {
        db()->prepare(
            'INSERT INTO stats (created_at, user_id, user_name, book_id, paragraph_id, feature, tokens_used)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([date('Y-m-d H:i:s'), $userId, $GLOBALS['__log_user_name'] ?? null, $bookId, $paragraphId, $feature, $tokens]);
    } catch (Throwable $e) {
        logException('Не удалось записать статистику', $e);
    }
}

/**
 * Пишет строку «время УРОВЕНЬ user=id username=имя сообщение» в BOT_LOG_FILE (по умолчанию logs/bot.log).
 * Записываются только события не ниже LOG_LEVEL из .env (по умолчанию ERROR).
 * Ключи и токены сюда передавать нельзя.
 */
function botLog(string $level, string $message, ?int $userId = null): void
{
    $level = strtoupper($level);
    $threshold = LOG_LEVELS[strtoupper(env('LOG_LEVEL', 'ERROR') ?? 'ERROR')] ?? LOG_LEVELS['ERROR'];
    if ((LOG_LEVELS[$level] ?? LOG_LEVELS['ERROR']) < $threshold) {
        return;
    }

    $path = resolvePath(env('BOT_LOG_FILE', 'logs/bot.log'));
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    [$currentId, $userName] = logUserFields();
    $line = sprintf(
        "%s %s user=%s username=%s %s\n",
        date('Y-m-d H:i:s'),
        $level,
        $userId ?? $currentId,
        $userName,
        str_replace(["\r", "\n"], ' ', $message)
    );
    if (!@error_log($line, 3, $path)) {
        error_log(trim($line));
    }
}

function logException(string $context, Throwable $error, ?int $userId = null): void
{
    botLog('error', sprintf('%s: %s: %s (%s:%d)', $context, get_class($error), $error->getMessage(), basename($error->getFile()), $error->getLine()), $userId);
}
