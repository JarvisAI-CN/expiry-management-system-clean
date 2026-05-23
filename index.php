<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$pdo = db();
$today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
$tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');

$todayCountStmt = $pdo->prepare('SELECT COUNT(*) FROM scans WHERE created_at >= :today AND created_at < :tomorrow');
$todayCountStmt->execute([':today' => $today . ' 00:00:00', ':tomorrow' => $tomorrow . ' 00:00:00']);
$todayCount = (int) $todayCountStmt->fetchColumn();

$recentStmt = $pdo->query(
    'SELECT s.*, COALESCE(s.product_name_snapshot, p.name) AS product_name,
            p.category, p.alert_days
     FROM scans s
     LEFT JOIN products p ON p.sku = s.sku
     ORDER BY s.id DESC
     LIMIT 8'
);
$recentScans = $recentStmt->fetchAll();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>保质期扫码录入</title>
    <link rel="stylesheet" href="assets/app.css?v=20260523-4">
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <div>
                <p class="eyebrow">保质期管理</p>
                <h1>扫码录入</h1>
            </div>
            <div class="top-stat">
                <span>今日</span>
                <strong><?= e($todayCount) ?></strong>
            </div>
        </header>

        <nav class="nav-tabs" aria-label="主导航">
            <a class="active" href="index.php">扫码</a>
            <a href="dashboard.php">看板</a>
            <a href="stocktake.php">盘点</a>
            <a href="ai.php">AI</a>
            <a href="records.php">记录</a>
            <a href="admin.php">后台</a>
        </nav>

        <main>
            <section class="panel scan-panel">
                <div class="video-box">
                    <video id="scanVideo" playsinline muted></video>
                    <div id="scanHint" class="video-hint">点击开始扫码</div>
                </div>

                <div class="button-row">
                    <button class="primary-button" id="startScan" type="button">开始扫码</button>
                    <button class="ghost-button" id="stopScan" type="button">停止</button>
                </div>

                <label class="field-label" for="rawCode">扫码内容</label>
                <textarea id="rawCode" rows="2" placeholder="001110742820260311#20260311#20260907"></textarea>
                <button class="wide-button" id="parseButton" type="button">识别内容</button>
                <p id="scannerMessage" class="inline-message"></p>
            </section>

            <section class="panel result-panel is-hidden" id="resultPanel" aria-live="polite">
                <div class="result-head">
                    <div>
                        <p class="eyebrow">当前批次</p>
                        <h2 id="productName">-</h2>
                    </div>
                    <span class="status-pill" id="expiryStatus">待确认</span>
                </div>

                <dl class="detail-grid">
                    <div>
                        <dt>SKU</dt>
                        <dd id="skuText">-</dd>
                    </div>
                    <div>
                        <dt>分类</dt>
                        <dd id="categoryText">-</dd>
                    </div>
                    <div>
                        <dt>生产日期</dt>
                        <dd id="productionText">-</dd>
                    </div>
                    <div>
                        <dt>到期日期</dt>
                        <dd id="expiryText">-</dd>
                    </div>
                    <div>
                        <dt>剩余天数</dt>
                        <dd id="daysLeftText">-</dd>
                    </div>
                    <div>
                        <dt>提醒规则</dt>
                        <dd id="alertDaysText">-</dd>
                    </div>
                </dl>

                <form id="saveForm" class="save-form">
                    <label class="field-label" for="quantity">这个批次的数量</label>
                    <input id="quantity" name="quantity" type="number" min="1" step="1" inputmode="numeric" autocomplete="off" required>

                    <label class="field-label" for="note">备注</label>
                    <input id="note" name="note" type="text" autocomplete="off" placeholder="可不填">

                    <button class="primary-button wide" type="submit">保存并继续扫码</button>
                </form>
            </section>

            <section class="panel">
                <div class="section-head">
                    <h2>最近录入</h2>
                    <a href="records.php">查看全部</a>
                </div>

                <?php if (!$recentScans): ?>
                    <p class="empty-state">还没有录入记录。</p>
                <?php else: ?>
                    <div class="record-list">
                        <?php foreach ($recentScans as $scan): ?>
                            <?php
                            $left = days_left($scan['expiry_date']);
                            $status = expiry_status($left, product_alert_days($scan));
                            $categoryText = $scan['category'] ? $scan['category'] . ' · ' : '';
                            ?>
                            <article class="record-item">
                                <div>
                                    <strong><?= e($scan['product_name'] ?: '未录入商品名') ?></strong>
                                    <span><?= e($categoryText) ?>SKU <?= e($scan['sku']) ?> · <?= e(display_date($scan['expiry_date'])) ?></span>
                                </div>
                                <div class="record-meta">
                                    <span class="status-dot <?= e($status['code']) ?>"></span>
                                    <b><?= e($scan['quantity']) ?></b>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <script src="assets/scan.js?v=20260523-4"></script>
</body>
</html>
