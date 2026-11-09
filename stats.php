<?php
// Отключаем вывод предупреждений для чистой работы (можно включить при отладке)
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

require __DIR__ . '/src/bootstrap.php';

// ---------------------------------------------------------
// ОБРАБОТКА ФИЛЬТРОВ ПО ВРЕМЕНИ
// ---------------------------------------------------------
$period = $_GET['period'] ?? '7';
$start_time = 0;
$period_label = '';

switch ($period) {
    case '1':
        $start_time = strtotime('-1 day');
        $period_label = 'За 24 часа';
        break;
    case '30':
        $start_time = strtotime('-30 days');
        $period_label = 'За 30 дней';
        break;
    case 'month':
        $start_time = strtotime('first day of this month 00:00:00');
        $period_label = 'С 1 числа месяца';
        break;
    case '7':
    default:
        $start_time = strtotime('-7 days');
        $period_label = 'За 7 дней';
        break;
}

// ---------------------------------------------------------
// ЧТЕНИЕ И СБОР СТАТИСТИКИ
// ---------------------------------------------------------
$total_tokens = 0;
$total_requests = 0;
$feature_stats = [];
$book_stats = [];
$user_stats = [];

try {
    $pdo = db();
    $since = date('Y-m-d H:i:s', $start_time);
    $aggregate = function (string $select, string $group) use ($pdo, $since): array {
        $stmt = $pdo->prepare("SELECT $select, COUNT(*) AS requests, COALESCE(SUM(tokens_used), 0) AS tokens
                               FROM stats WHERE created_at >= ? GROUP BY $group");
        $stmt->execute([$since]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    foreach ($aggregate('feature', 'feature') as $row) {
        $feature_stats[] = ['feature' => $row['feature'], 'requests' => (int)$row['requests'], 'tokens' => (int)$row['tokens']];
        $total_requests += (int)$row['requests'];
        $total_tokens += (int)$row['tokens'];
    }
    // Запросы без книги (например, случайный факт) в разрезе книг не показываем.
    foreach ($aggregate('book_id', 'book_id') as $row) {
        if ($row['book_id'] === null) continue;
        $book_stats[] = ['book_id' => 'Книга #' . $row['book_id'], 'requests' => (int)$row['requests'], 'tokens' => (int)$row['tokens']];
    }
    foreach ($aggregate('user_id, MAX(user_name) AS user_name', 'user_id') as $row) {
        $user_stats[] = ['user_id' => $row['user_id'], 'name' => $row['user_name'] ?? '', 'requests' => (int)$row['requests'], 'tokens' => (int)$row['tokens']];
    }
} catch (Throwable $e) {
    logException('Не удалось прочитать статистику', $e);
}

// ---------------------------------------------------------
// СОРТИРОВКА И ПОДГОТОВКА ДАННЫХ ДЛЯ ТАБЛИЦ
// ---------------------------------------------------------
$feature_stats = array_values($feature_stats);
usort($feature_stats, function($a, $b) { return $b['requests'] <=> $a['requests']; });

$book_stats = array_values($book_stats);
usort($book_stats, function($a, $b) { return $b['requests'] <=> $a['requests']; });

$user_stats_by_req = $user_stats;
usort($user_stats_by_req, function($a, $b) { return $b['requests'] <=> $a['requests']; });

$user_stats_by_tokens = $user_stats;
usort($user_stats_by_tokens, function($a, $b) { return $b['tokens'] <=> $a['tokens']; });

// Вспомогательная функция для рендеринга таблиц
function renderTable($title, $headers, $rows) {
    ob_start();
    ?>
    <div class="flex flex-col h-full">
        <h2 class="text-lg font-semibold mb-3 text-slate-800"><?= htmlspecialchars($title) ?></h2>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex-grow">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <?php foreach($headers as $i => $header): ?>
                                <th class="px-6 py-4 text-<?= $i === 0 ? 'left' : 'right' ?> text-xs font-semibold text-slate-500 uppercase tracking-wider">
                                    <?= htmlspecialchars($header) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-200">
                        <?php if(empty($rows)): ?>
                            <tr><td colspan="<?= count($headers) ?>" class="px-6 py-8 text-center text-slate-400">Нет данных за этот период</td></tr>
                        <?php else: ?>
                            <?php foreach(array_slice($rows, 0, 50) as $row): // Ограничение в 50 строк ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <?php $first = true; foreach($row as $cell): ?>
                                        <td class="px-6 py-3 whitespace-nowrap text-sm <?= $first ? 'font-medium text-slate-900' : 'text-slate-600 text-right' ?>">
                                            <?= is_numeric($cell) ? number_format($cell, 0, '.', ' ') : htmlspecialchars($cell) ?>
                                        </td>
                                    <?php $first = false; endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

$unique_users = count($user_stats);
$top_feature = !empty($feature_stats) ? $feature_stats[0]['feature'] : '-';

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Статистика Использования API</title>
    <link rel="icon" href="data:,">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased">
    <div class="min-h-screen flex flex-col">
        <!-- Шапка и Навигация -->
        <header class="bg-indigo-700 shadow-md">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row justify-between items-center">
                <h1 class="text-2xl font-bold text-white mb-4 sm:mb-0 flex items-center gap-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    Дашборд Статистики
                </h1>
                <nav class="flex flex-wrap gap-2 bg-indigo-800 p-1 rounded-lg">
                    <a href="?period=1" class="<?= $period === '1' ? 'bg-indigo-600 text-white shadow' : 'text-indigo-100 hover:text-white hover:bg-indigo-700' ?> px-4 py-2 rounded-md text-sm font-medium transition-all">1 день</a>
                    <a href="?period=7" class="<?= $period === '7' ? 'bg-indigo-600 text-white shadow' : 'text-indigo-100 hover:text-white hover:bg-indigo-700' ?> px-4 py-2 rounded-md text-sm font-medium transition-all">7 дней</a>
                    <a href="?period=30" class="<?= $period === '30' ? 'bg-indigo-600 text-white shadow' : 'text-indigo-100 hover:text-white hover:bg-indigo-700' ?> px-4 py-2 rounded-md text-sm font-medium transition-all">30 дней</a>
                    <a href="?period=month" class="<?= $period === 'month' ? 'bg-indigo-600 text-white shadow' : 'text-indigo-100 hover:text-white hover:bg-indigo-700' ?> px-4 py-2 rounded-md text-sm font-medium transition-all">С 1-го числа</a>
                </nav>
            </div>
        </header>

        <!-- Основной Контент -->
        <main class="flex-grow max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">
            <!-- Карточки Основных Показателей -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex flex-col justify-between">
                    <span class="text-slate-500 text-xs font-bold uppercase tracking-wider mb-2">Общий расход токенов</span>
                    <span class="text-3xl font-extrabold text-indigo-600"><?= number_format($total_tokens, 0, '.', ' ') ?></span>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex flex-col justify-between">
                    <span class="text-slate-500 text-xs font-bold uppercase tracking-wider mb-2">Всего обращений</span>
                    <span class="text-3xl font-extrabold text-slate-800"><?= number_format($total_requests, 0, '.', ' ') ?></span>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex flex-col justify-between">
                    <span class="text-slate-500 text-xs font-bold uppercase tracking-wider mb-2">Уникальных юзеров</span>
                    <span class="text-3xl font-extrabold text-slate-800"><?= number_format($unique_users, 0, '.', ' ') ?></span>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex flex-col justify-between">
                    <span class="text-slate-500 text-xs font-bold uppercase tracking-wider mb-2">Самая частая фича</span>
                    <span class="text-3xl font-extrabold text-emerald-600 truncate uppercase"><?= htmlspecialchars($top_feature) ?></span>
                </div>
            </div>

            <!-- Сетка Таблиц -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                <!-- 1. Использование фич -->
                <?= renderTable('Использование фич (по убыванию)', ['Фича', 'Кол-во обращений', 'Потрачено токенов'], array_map(function($s) {
                    return [$s['feature'], $s['requests'], $s['tokens']];
                }, $feature_stats)) ?>

                <!-- 2. Популярные книги -->
                <?= renderTable('Популярные книги', ['Идентификатор', 'Кол-во обращений', 'Потрачено токенов'], array_map(function($s) {
                    return [$s['book_id'], $s['requests'], $s['tokens']];
                }, $book_stats)) ?>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- 3. Активные пользователи (по фичам / обращениям) -->
                <?= renderTable('Топ пользователей по активности (Обращения)', ['Пользователь', 'Обращения', 'Токены'], array_map(function($s) {
                    return [$s['name'] . ' (#' . $s['user_id'] . ')', $s['requests'], $s['tokens']];
                }, $user_stats_by_req)) ?>

                <!-- 4. Активные пользователи (по токенам) -->
                <?= renderTable('Топ пользователей по расходам (Токены)', ['Пользователь', 'Токены', 'Обращения'], array_map(function($s) {
                    return [$s['name'] . ' (#' . $s['user_id'] . ')', $s['tokens'], $s['requests']];
                }, $user_stats_by_tokens)) ?>
            </div>
            
        </main>
        
        <footer class="bg-white border-t border-slate-200 py-6 mt-auto">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center text-sm text-slate-500">
                Сводка данных сгенерирована на основе логов <?= $period_label ?>
            </div>
        </footer>
    </div>
</body>
</html>