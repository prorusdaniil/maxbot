<?php
declare(strict_types=1);

/** Пишет строку в лог бота (BOT_LOG_FILE, по умолчанию logs/bot.log); ключи и токены сюда передавать нельзя. */
function botLog(string $level, string $message): void
{
    $path = resolvePath(env('BOT_LOG_FILE', 'logs/bot.log'));
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = sprintf("%s %s %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
    if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
        error_log(trim($line));
    }
}

function logException(string $context, Throwable $error): void
{
    botLog('error', sprintf('%s: %s: %s (%s:%d)', $context, get_class($error), $error->getMessage(), basename($error->getFile()), $error->getLine()));
}
