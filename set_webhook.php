<?php
declare(strict_types=1);

/**
 * Управление подпиской бота MAX на webhook. Запуск только из консоли:
 *   php set_webhook.php --url=https://example.com/webhook.php   # установить подписку
 *   php set_webhook.php --list                                  # показать подписки
 *   php set_webhook.php --delete=https://example.com/webhook.php # удалить подписку
 *
 * Токен и секрет берутся из .env (MAX_BOT_TOKEN, MAX_WEBHOOK_SECRET).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/src/bootstrap.php';

$options = getopt('', ['url:', 'list', 'delete:']);

try {
    $api = MaxApi::fromEnv();

    if (isset($options['list'])) {
        echo json_encode($api->request('GET', '/subscriptions'), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } elseif (isset($options['delete'])) {
        $api->request('DELETE', '/subscriptions', ['url' => $options['delete']]);
        echo "Подписка удалена\n";
    } elseif (isset($options['url'])) {
        $secret = env('MAX_WEBHOOK_SECRET');
        if ($secret === null) {
            throw new RuntimeException('Не задан MAX_WEBHOOK_SECRET');
        }
        $api->request('POST', '/subscriptions', [], [
            'url' => $options['url'],
            'update_types' => ['message_created', 'message_callback', 'bot_started'],
            'secret' => $secret,
        ]);
        echo "Подписка установлена: {$options['url']}\n";
    } else {
        fwrite(STDERR, "Укажите --url=..., --list или --delete=...\n");
        exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}
