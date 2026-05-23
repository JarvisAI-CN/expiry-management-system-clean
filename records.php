<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$pdo = db();
$q = trim((string) ($_GET['q'] ?? ''));
$dateFrom = parse_ymd((string) ($_GET['from'] ?? ''));
$dateTo = parse_ymd((string) ($_GET['to'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');

$where = ['1 = 1'];
$params = [];

if ($q !== '') {
    $where[] = '(s.sku LIKE :q OR s.sku LIKE :q_sku OR s.product_name_snapshot LIKE :q OR p.name LIKE :q OR p.category LIKE :q OR s.raw_code LIKE :q)';
    $params[':q'] = '%' . $q . '%';
    $params[':q_sku'] = '%' . normalize_sku($q) . '%';
}

if ($dateFrom !== null) {
    $where[] = 'date(s.created_at) >= :from';
    $params[':from'] = $dateFrom;
}

if ($dateTo !== null) {
    $where[] = 'date(s.created_at) <= :to';
    $params[':to'] = $dateTo;
}

if ($statusFilter === 'expired') {
    $where[] = 's.expiry_date < :today';
    $params[':today'] = $today;
}

if ($statusFilter === 'urgent') {
    $where[] = 's.expiry_date >= :today AND (julianday(s.expiry_date) - julianday(:today)) <= COALESCE(p.alert_days, 7)';
    $params[':today'] = $today;
}

if ($statusFilter === 'warning') {
    $where[] = 's.expiry_date >= :today
        AND (julianday(s.expiry_date) - julianday(:today)) > COALESCE(p.alert_days, 7)
        AND (julianday(s.expiry_date) - julianday(:today)) <= 30';
    $params[':today'] = $today;
}

if ($statusFilter === 'ok') {
    $where[] = 's.expiry_date >= :today AND (julianday(s.expiry_date) - julianday(:today)) > 30';
    $params[':today'] = $today;
}

$sql = 'SELECT s.*, COALESCE(s.product_name_snapshot, p.name) AS product_name,
               p.category, p.alert_days, p.shelf_remove_days
        FROM scans s
        LEFT JOIN products p ON p.sku = s.sku
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY s.id DESC';

if (isset($_GET['export'])) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="shelf-life-records.csv"');
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, ['录入时间', 'SKU', '商品名称', '分类', '状态', '生产日期', '到期日期', '剩余天数', '提醒天数', '数量', '备注', '原始二维码']);

    foreach ($stmt->fetchAll() as $row) {
        $left = days_left($row['expiry_date']);
        $status = expiry_status($left, product_alert_days($row));

        fputcsv($output, [
            $row['created_at'],
            $row['sku'],
            $row['product_name'] ?: '未录入商品名',
            $row['category'] ?: '',
            $status['text'],
            display_date($row['production_date']),
            display_date($row['expiry_date']),
            $left,
            product_alert_days($row),
            $row['quantity'],
            $row['note'],
            $row['raw_code'],
        ]);
    }

    exit;
}

$stmt = $pdo->prepare($sql . ' LIMIT 500');
$stmt->execute($params);
$records = $stmt->fetchAll();

$totalQuantity = 0;
foreach ($records as $record) {
    $totalQuantity += (int) $record['quantity'];
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>录入记录</title>
    <link rel="stylesheet" href="assets/app.css?v=20260523-4">
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <div>
                <p class="eyebrow">记录</p>
                <h1>已录入批次</h1>
            </div>
            <div class="top-stat">
                <span>数量</span>
                <strong><?= e($totalQuantity) ?></strong>
            </div>
        </header>

        <nav class="nav-tabs" aria-label="主导航">
            <a href="index.php">扫码</a>
            <a href="dashboard.php">看板</a>
            <a href="stocktake.php">盘点</a>
            <a href="ai.php">AI</a>
            <a class="active" href="records.php">记录</a>
            <a href="admin.php">后台</a>
        </nav>

        <main>
            <section class="panel">
                <form method="get" class="filter-form">
                    <input name="q" value="<?= e($q) ?>" placeholder="SKU/商品名/分类">
                    <input name="from" type="date" value="<?= e($dateFrom ?? '') ?>">
                    <input name="to" type="date" value="<?= e($dateTo ?? '') ?>">
                    <select name="status">
                        <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>全部状态</option>
                        <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>已过期</option>
                        <option value="urgent" <?= $statusFilter === 'urgent' ? 'selected' : '' ?>>临期</option>
                        <option value="warning" <?= $statusFilter === 'warning' ? 'selected' : '' ?>>30 天内</option>
                        <option value="ok" <?= $statusFilter === 'ok' ? 'selected' : '' ?>>正常</option>
                    </select>
                    <button class="wide-button" type="submit">筛选</button>
                    <a class="export-link" href="records.php?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>">导出 CSV</a>
                </form>
            </section>

            <section class="panel">
                <div class="section-head">
                    <h2>记录列表</h2>
                    <span><?= e(count($records)) ?> 条</span>
                </div>

                <?php if (!$records): ?>
                    <p class="empty-state">还没有符合条件的记录。</p>
                <?php else: ?>
                    <div class="record-list large">
                        <?php foreach ($records as $record): ?>
                            <?php
                            $left = days_left($record['expiry_date']);
                            $status = expiry_status($left, product_alert_days($record));
                            $categoryText = $record['category'] ? $record['category'] . ' · ' : '';
                            ?>
                            <article class="record-card">
                                <div class="record-card-head">
                                    <div>
                                        <strong><?= e($record['product_name'] ?: '未录入商品名') ?></strong>
                                        <span><?= e($categoryText) ?>SKU <?= e($record['sku']) ?> · <?= e(product_alert_days($record)) ?> 天提醒</span>
                                    </div>
                                    <span class="status-pill <?= e($status['code']) ?>"><?= e($status['text']) ?></span>
                                </div>
                                <dl class="record-detail-grid">
                                    <div>
                                        <dt>生产</dt>
                                        <dd><?= e(display_date($record['production_date'])) ?></dd>
                                    </div>
                                    <div>
                                        <dt>到期</dt>
                                        <dd><?= e(display_date($record['expiry_date'])) ?></dd>
                                    </div>
                                    <div>
                                        <dt>剩余</dt>
                                        <dd><?= $left === null ? '-' : e($left . ' 天') ?></dd>
                                    </div>
                                    <div>
                                        <dt>数量</dt>
                                        <dd><?= e($record['quantity']) ?></dd>
                                    </div>
                                </dl>
                                <p class="record-time"><?= e($record['created_at']) ?><?= $record['note'] ? ' · ' . e($record['note']) : '' ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
