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

// Цены в долларах за 1 млн токенов
const PRICE_INPUT = 0.3;
const PRICE_CACHED = 0.006;
const PRICE_OUTPUT = 1.2;

function usd(int $tokens, float $pricePerMillion): float
{
    return $tokens * $pricePerMillion / 1000000;
}

function formatUsd(float $value): string
{
    return '$' . number_format($value, 4, '.', ' ');
}

/** Ячейка с токенами и мелкой суммой в долларах под ними. */
function tokenCell(int $tokens, float $price): array
{
    return [$tokens, formatUsd(usd($tokens, $price))];
}

// ---------------------------------------------------------
// ЧТЕНИЕ И СБОР СТАТИСТИКИ
// ---------------------------------------------------------
$total_input = $total_cached = $total_output = 0;
$total_requests = 0;
$feature_stats = [];
$book_stats = [];
$user_stats = [];

try {
    $pdo = db();
    $since = date('Y-m-d H:i:s', $start_time);
    $aggregate = function (string $select, string $group) use ($pdo, $since): array {
        $stmt = $pdo->prepare("SELECT $select, COUNT(*) AS requests, COALESCE(SUM(input_tokens), 0) AS input_tokens,
                                      COALESCE(SUM(cached_tokens), 0) AS cached_tokens,
                                      COALESCE(SUM(output_tokens), 0) AS output_tokens
                               FROM stats WHERE created_at >= ? GROUP BY $group");
        $stmt->execute([$since]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    foreach ($aggregate('feature', 'feature') as $row) {
        $feature_stats[] = ['feature' => $row['feature'], 'requests' => (int)$row['requests'], 'input' => (int)$row['input_tokens'], 'cached' => (int)$row['cached_tokens'], 'output' => (int)$row['output_tokens']];
        $total_requests += (int)$row['requests'];
        $total_input += (int)$row['input_tokens'];
        $total_cached += (int)$row['cached_tokens'];
        $total_output += (int)$row['output_tokens'];
    }
    // Запросы без книги (например, случайный факт) в разрезе книг не показываем.
    try {
        $book_titles = $pdo->query('SELECT id, title FROM books')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        $book_titles = [];
    }
    foreach ($aggregate('book_id', 'book_id') as $row) {
        if ($row['book_id'] === null) continue;
        $book_stats[] = ['book_id' => $book_titles[(int)$row['book_id']] ?? ('Книга #' . $row['book_id']), 'requests' => (int)$row['requests'], 'input' => (int)$row['input_tokens'], 'cached' => (int)$row['cached_tokens'], 'output' => (int)$row['output_tokens']];
    }
    foreach ($aggregate('user_id, MAX(user_name) AS user_name', 'user_id') as $row) {
        $user_stats[] = ['user_id' => $row['user_id'], 'name' => $row['user_name'] ?? '', 'requests' => (int)$row['requests'], 'input' => (int)$row['input_tokens'], 'cached' => (int)$row['cached_tokens'], 'output' => (int)$row['output_tokens']];
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
usort($user_stats_by_tokens, function($a, $b) { return ($b['input'] + $b['cached'] + $b['output']) <=> ($a['input'] + $a['cached'] + $a['output']); });

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
                                            <?php if (is_array($cell)): ?>
                                                <?= number_format($cell[0], 0, '.', ' ') ?>
                                                <div class="text-xs text-slate-400"><?= htmlspecialchars($cell[1]) ?></div>
                                            <?php else: ?>
                                                <?= is_numeric($cell) ? number_format($cell, 0, '.', ' ') : htmlspecialchars($cell) ?>
                                            <?php endif; ?>
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

$total_cost = usd($total_input, PRICE_INPUT) + usd($total_cached, PRICE_CACHED) + usd($total_output, PRICE_OUTPUT);
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
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-10 flex items-center justify-between">
                <span class="text-slate-500 text-xs font-bold uppercase tracking-wider">Общие расходы</span>
                <span class="text-3xl font-extrabold text-slate-800"><?= formatUsd($total_cost) ?></span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
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
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-6">
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex flex-col justify-between">
                    <span class="text-slate-500 text-xs font-bold uppercase tracking-wider mb-2">Входящие токены ($0.3/1M)</span>
                    <span class="text-3xl font-extrabold text-slate-800"><?= number_format($total_input, 0, '.', ' ') ?></span>
                    <span class="text-xs text-slate-400 mt-1"><?= formatUsd(usd($total_input, PRICE_INPUT)) ?></span>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex flex-col justify-between">
                    <span class="text-slate-500 text-xs font-bold uppercase tracking-wider mb-2">Кэшированные токены ($0.006/1M)</span>
                    <span class="text-3xl font-extrabold text-slate-800"><?= number_format($total_cached, 0, '.', ' ') ?></span>
                    <span class="text-xs text-slate-400 mt-1"><?= formatUsd(usd($total_cached, PRICE_CACHED)) ?></span>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex flex-col justify-between">
                    <span class="text-slate-500 text-xs font-bold uppercase tracking-wider mb-2">Исходящие токены ($1.2/1M)</span>
                    <span class="text-3xl font-extrabold text-slate-800"><?= number_format($total_output, 0, '.', ' ') ?></span>
                    <span class="text-xs text-slate-400 mt-1"><?= formatUsd(usd($total_output, PRICE_OUTPUT)) ?></span>
                </div>
            </div>

            <!-- Сетка Таблиц -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                <!-- 1. Использование фич -->
                <?= renderTable('Использование фич (по убыванию)', ['Фича', 'Кол-во обращений', 'Входящие', 'Кэш', 'Исходящие'], array_map(function($s) {
                    return [$s['feature'], $s['requests'], tokenCell($s['input'], PRICE_INPUT), tokenCell($s['cached'], PRICE_CACHED), tokenCell($s['output'], PRICE_OUTPUT)];
                }, $feature_stats)) ?>

                <!-- 2. Популярные книги -->
                <?= renderTable('Популярные книги', ['Название', 'Кол-во обращений', 'Входящие', 'Кэш', 'Исходящие'], array_map(function($s) {
                    return [$s['book_id'], $s['requests'], tokenCell($s['input'], PRICE_INPUT), tokenCell($s['cached'], PRICE_CACHED), tokenCell($s['output'], PRICE_OUTPUT)];
                }, $book_stats)) ?>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- 3. Активные пользователи (по фичам / обращениям) -->
                <?= renderTable('Топ пользователей по активности (Обращения)', ['Пользователь', 'Обращения', 'Входящие', 'Кэш', 'Исходящие'], array_map(function($s) {
                    return [$s['name'] . ' (#' . $s['user_id'] . ')', $s['requests'], tokenCell($s['input'], PRICE_INPUT), tokenCell($s['cached'], PRICE_CACHED), tokenCell($s['output'], PRICE_OUTPUT)];
                }, $user_stats_by_req)) ?>

                <!-- 4. Активные пользователи (по токенам) -->
                <?= renderTable('Топ пользователей по расходам (входящие + кэш + исходящие)', ['Пользователь', 'Обращения', 'Входящие', 'Кэш', 'Исходящие'], array_map(function($s) {
                    return [$s['name'] . ' (#' . $s['user_id'] . ')', $s['requests'], tokenCell($s['input'], PRICE_INPUT), tokenCell($s['cached'], PRICE_CACHED), tokenCell($s['output'], PRICE_OUTPUT)];
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