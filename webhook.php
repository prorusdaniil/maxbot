<?php
declare(strict_types=1);

/**
 * Webhook бота MAX. Адрес для подписки: https://<домен>/webhook.php (см. set_webhook.php).
 * MAX шлёт POST с JSON-обновлением и заголовком X-Max-Bot-Api-Secret.
 */

ini_set('display_errors', '0');

require __DIR__ . '/src/bootstrap.php';

/** Отвечает MAX сразу, а обновление обрабатывает после закрытия соединения (запросы к ИИ долгие). */
function respondAndContinue(): void
{
    ignore_user_abort(true);
    $body = '{"ok":true}';
    header('Content-Type: application/json');
    header('Content-Encoding: none');
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

function reject(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reject(405, 'Method not allowed');
}

$secret = env('MAX_WEBHOOK_SECRET');
if ($secret === null) {
    botLog('error', 'Не задан MAX_WEBHOOK_SECRET');
    reject(500, 'Webhook is not configured');
}
if (!hash_equals($secret, (string)($_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? ''))) {
    botLog('warning', 'Webhook отклонён: заголовок X-Max-Bot-Api-Secret не совпадает с MAX_WEBHOOK_SECRET');
    reject(401, 'Invalid secret');
}

$update = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($update)) {
    reject(400, 'Invalid JSON');
}

respondAndContinue();
set_time_limit(180);
botLog('info', 'Получено обновление ' . ($update['update_type'] ?? '?'));

try {
    $sessions = Sessions::fromEnv();
    try {
        $sessions->ensureSchema();
    } catch (PDOException $e) {
        // Нет прав на CREATE: таблицу нужно создать вручную из schema.sql.
        logException('Не удалось создать таблицу bot_sessions', $e);
    }

    $bot = new Bot(MaxApi::fromEnv(), Books::fromEnv(), $sessions, 'createLlmClient');
    $bot->handleUpdate($update);
} catch (Throwable $e) {
    logException('Ошибка обработки обновления ' . ($update['update_type'] ?? '?'), $e);
}
