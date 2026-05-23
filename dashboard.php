<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$pdo = db();

$stmt = $pdo->query(
    'SELECT s.*, COALESCE(s.product_name_snapshot, p.name) AS product_name,
            p.category, p.alert_days, p.shelf_remove_days
     FROM scans s
     LEFT JOIN products p ON p.sku = s.sku
     ORDER BY s.expiry_date ASC, s.id DESC'
);
$rows = $stmt->fetchAll();

$metrics = [
    'batches' => 0,
    'quantity' => 0,
    'expired_qty' => 0,
    'urgent_qty' => 0,
    'warning_qty' => 0,
    'ok_qty' => 0,
];
$statusCounts = [
    'expired' => 0,
    'urgent' => 0,
    'warning' => 0,
    'ok' => 0,
    'unknown' => 0,
];
$riskRows = [];
$categoryTotals = [];

foreach ($rows as $row) {
    $quantity = (int) $row['quantity'];
    $left = days_left($row['expiry_date']);
    $status = expiry_status($left, product_alert_days($row));
    $statusCode = $status['code'];
    $category = $row['category'] ?: '未分类';

    $metrics['batches']++;
    $metrics['quantity'] += $quantity;
    $statusCounts[$statusCode] = ($statusCounts[$statusCode] ?? 0) + 1;
    $categoryTotals[$category] = ($categoryTotals[$category] ?? 0) + $quantity;

    if ($statusCode === 'expired') {
        $metrics['expired_qty'] += $quantity;
    } elseif ($statusCode === 'urgent') {
        $metrics['urgent_qty'] += $quantity;
    } elseif ($statusCode === 'warning') {
        $metrics['warning_qty'] += $quantity;
    } elseif ($statusCode === 'ok') {
        $metrics['ok_qty'] += $quantity;
    }

    if (in_array($statusCode, ['expired', 'urgent', 'warning'], true)) {
        $row['days_left_value'] = $left;
        $row['status'] = $status;
        $riskRows[] = $row;
    }
}

usort($riskRows, static function (array $a, array $b): int {
    return strcmp((string) $a['expiry_date'], (string) $b['expiry_date']);
});
$riskRows = array_slice($riskRows, 0, 20);

arsort($categoryTotals);
$maxCategoryQuantity = max($categoryTotals ?: [0]);
$todoQuantity = $metrics['expired_qty'] + $metrics['urgent_qty'];
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>保质期看板</title>
    <link rel="stylesheet" href="assets/app.css?v=20260523-4">
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <div>
                <p class="eyebrow">看板</p>
                <h1>今日库存风险</h1>
            </div>
            <div class="top-stat">
                <span>待处理</span>
                <strong><?= e($todoQuantity) ?></strong>
            </div>
        </header>

        <nav class="nav-tabs" aria-label="主导航">
            <a href="index.php">扫码</a>
            <a class="active" href="dashboard.php">看板</a>
            <a href="stocktake.php">盘点</a>
            <a href="ai.php">AI</a>
            <a href="records.php">记录</a>
            <a href="admin.php">后台</a>
        </nav>

        <main>
            <section class="panel dashboard-panel">
                <div class="metric-grid">
                    <a class="metric-card" href="records.php">
                        <span>总批次</span>
                        <strong><?= e($metrics['batches']) ?></strong>
                    </a>
                    <a class="metric-card" href="records.php">
                        <span>总数量</span>
                        <strong><?= e($metrics['quantity']) ?></strong>
                    </a>
                    <a class="metric-card danger" href="records.php?status=expired">
                        <span>已过期</span>
                        <strong><?= e($metrics['expired_qty']) ?></strong>
                    </a>
                    <a class="metric-card danger" href="records.php?status=urgent">
                        <span>临期</span>
                        <strong><?= e($metrics['urgent_qty']) ?></strong>
                    </a>
                    <a class="metric-card warn" href="records.php?status=warning">
                        <span>30 天内</span>
                        <strong><?= e($metrics['warning_qty']) ?></strong>
                    </a>
                    <a class="metric-card ok" href="records.php?status=ok">
                        <span>正常</span>
                        <strong><?= e($metrics['ok_qty']) ?></strong>
                    </a>
                </div>
            </section>

            <section class="panel">
                <div class="section-head">
                    <h2>优先处理</h2>
                    <span><?= e(count($riskRows)) ?> 条</span>
                </div>

                <?php if (!$riskRows): ?>
                    <p class="empty-state">现在没有临期或过期批次。</p>
                <?php else: ?>
                    <div class="record-list large">
                        <?php foreach ($riskRows as $row): ?>
                            <article class="record-card compact-card">
                                <div class="record-card-head">
                                    <div>
                                        <strong><?= e($row['product_name'] ?: '未录入商品名') ?></strong>
                                        <span>
                                            <?= $row['category'] ? e($row['category']) . ' · ' : '' ?>
                                            SKU <?= e($row['sku']) ?> · 数量 <?= e($row['quantity']) ?>
                                        </span>
                                    </div>
                                    <span class="status-pill <?= e($row['status']['code']) ?>"><?= e($row['status']['text']) ?></span>
                                </div>
                                <dl class="record-detail-grid">
                                    <div>
                                        <dt>到期</dt>
                                        <dd><?= e(display_date($row['expiry_date'])) ?></dd>
                                    </div>
                                    <div>
                                        <dt>剩余</dt>
                                        <dd><?= $row['days_left_value'] === null ? '-' : e($row['days_left_value'] . ' 天') ?></dd>
                                    </div>
                                    <div>
                                        <dt>提醒</dt>
                                        <dd><?= e(product_alert_days($row)) ?> 天</dd>
                                    </div>
                                    <div>
                                        <dt>录入</dt>
                                        <dd><?= e(substr((string) $row['created_at'], 5, 11)) ?></dd>
                                    </div>
                                </dl>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="panel">
                <div class="section-head">
                    <h2>分类数量</h2>
                    <span><?= e(count($categoryTotals)) ?> 类</span>
                </div>

                <?php if (!$categoryTotals): ?>
                    <p class="empty-state">还没有录入记录。</p>
                <?php else: ?>
                    <div class="category-bars">
                        <?php foreach ($categoryTotals as $category => $quantity): ?>
                            <?php $width = $maxCategoryQuantity > 0 ? round($quantity / $maxCategoryQuantity * 100) : 0; ?>
                            <div class="category-row">
                                <div>
                                    <strong><?= e($category) ?></strong>
                                    <span><?= e($quantity) ?> 件</span>
                                </div>
                                <div class="category-track">
                                    <span class="category-fill" style="--bar: <?= e($width) ?>%"></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
