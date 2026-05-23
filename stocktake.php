<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$pdo = db();
$notice = null;
$error = null;

function stocktake_status_text(string $status): string
{
    return $status === 'completed' ? '已完成' : '草稿';
}

function next_stocktake_code(PDO $pdo): string
{
    $prefix = 'STK-' . date('Ymd') . '-';
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM stocktake_sessions WHERE session_code LIKE :prefix');
    $stmt->execute([':prefix' => $prefix . '%']);
    $number = (int) $stmt->fetchColumn() + 1;

    return $prefix . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create_session') {
            $code = trim((string) ($_POST['session_code'] ?? ''));
            $note = trim((string) ($_POST['note'] ?? ''));
            $code = $code !== '' ? $code : next_stocktake_code($pdo);
            $now = now_string();

            $stmt = $pdo->prepare(
                'INSERT INTO stocktake_sessions (session_code, status, note, created_at, updated_at)
                 VALUES (:session_code, :status, :note, :created_at, :updated_at)'
            );
            $stmt->execute([
                ':session_code' => $code,
                ':status' => 'draft',
                ':note' => $note !== '' ? $note : null,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            header('Location: stocktake.php?session_id=' . (int) $pdo->lastInsertId());
            exit;
        }

        if ($action === 'add_item') {
            $sessionId = (int) ($_POST['session_id'] ?? 0);
            $rawCode = (string) ($_POST['raw_code'] ?? '');
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $note = trim((string) ($_POST['note'] ?? ''));

            if ($sessionId <= 0 || $quantity < 1) {
                throw new RuntimeException('盘点单和数量都要填写。');
            }

            $sessionStmt = $pdo->prepare('SELECT * FROM stocktake_sessions WHERE id = :id');
            $sessionStmt->execute([':id' => $sessionId]);
            $session = $sessionStmt->fetch();

            if (!$session) {
                throw new RuntimeException('盘点单不存在。');
            }

            if ($session['status'] === 'completed') {
                throw new RuntimeException('已完成的盘点单不能继续添加。');
            }

            $parsed = parse_qr_code($rawCode);
            if (!$parsed['valid']) {
                throw new RuntimeException(implode('；', $parsed['errors']));
            }

            $product = find_product($pdo, $parsed['sku']);
            $pdo->beginTransaction();

            save_scan($pdo, $parsed, $quantity, $note !== '' ? $note : '盘点单 ' . $session['session_code']);

            $stmt = $pdo->prepare(
                'INSERT INTO stocktake_items (
                    session_id, raw_code, sku, production_date, expiry_date,
                    product_name_snapshot, quantity, note, created_at
                ) VALUES (
                    :session_id, :raw_code, :sku, :production_date, :expiry_date,
                    :product_name_snapshot, :quantity, :note, :created_at
                )'
            );
            $stmt->execute([
                ':session_id' => $sessionId,
                ':raw_code' => $parsed['raw_code'],
                ':sku' => $parsed['sku'],
                ':production_date' => $parsed['production_date'],
                ':expiry_date' => $parsed['expiry_date'],
                ':product_name_snapshot' => $product['name'] ?? null,
                ':quantity' => $quantity,
                ':note' => $note !== '' ? $note : null,
                ':created_at' => now_string(),
            ]);

            $stmt = $pdo->prepare('UPDATE stocktake_sessions SET updated_at = :updated_at WHERE id = :id');
            $stmt->execute([':updated_at' => now_string(), ':id' => $sessionId]);

            $pdo->commit();
            $notice = '盘点项目已添加，同时已同步到录入记录。';
        }

        if ($action === 'delete_item') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $sessionId = (int) ($_POST['session_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM stocktake_items WHERE id = :id');
            $stmt->execute([':id' => $itemId]);
            $notice = '盘点项目已删除。';
            $_GET['session_id'] = (string) $sessionId;
        }

        if ($action === 'complete_session') {
            $sessionId = (int) ($_POST['session_id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE stocktake_sessions SET status = :status, updated_at = :updated_at WHERE id = :id');
            $stmt->execute([':status' => 'completed', ':updated_at' => now_string(), ':id' => $sessionId]);
            $notice = '盘点单已完成。';
            $_GET['session_id'] = (string) $sessionId;
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

$currentSessionId = (int) ($_GET['session_id'] ?? $_POST['session_id'] ?? 0);
$currentSession = null;
$items = [];
$totalQuantity = 0;

if ($currentSessionId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM stocktake_sessions WHERE id = :id');
    $stmt->execute([':id' => $currentSessionId]);
    $currentSession = $stmt->fetch() ?: null;

    if ($currentSession) {
        $stmt = $pdo->prepare(
            'SELECT i.*, COALESCE(i.product_name_snapshot, p.name) AS product_name,
                    p.category, p.alert_days
             FROM stocktake_items i
             LEFT JOIN products p ON p.sku = i.sku
             WHERE i.session_id = :session_id
             ORDER BY i.id DESC'
        );
        $stmt->execute([':session_id' => $currentSessionId]);
        $items = $stmt->fetchAll();

        foreach ($items as $item) {
            $totalQuantity += (int) $item['quantity'];
        }
    }
}

$sessions = $pdo->query(
    'SELECT s.*,
            COUNT(i.id) AS item_count,
            COALESCE(SUM(i.quantity), 0) AS total_quantity
     FROM stocktake_sessions s
     LEFT JOIN stocktake_items i ON i.session_id = s.id
     GROUP BY s.id
     ORDER BY s.id DESC
     LIMIT 100'
)->fetchAll();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>盘点系统</title>
    <link rel="stylesheet" href="assets/app.css?v=20260523-4">
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <div>
                <p class="eyebrow">v3.1.0 模块</p>
                <h1><?= $currentSession ? e($currentSession['session_code']) : '盘点系统' ?></h1>
            </div>
            <div class="top-stat">
                <span><?= $currentSession ? '数量' : '盘点单' ?></span>
                <strong><?= e($currentSession ? $totalQuantity : count($sessions)) ?></strong>
            </div>
        </header>

        <nav class="nav-tabs" aria-label="主导航">
            <a href="index.php">扫码</a>
            <a href="dashboard.php">看板</a>
            <a class="active" href="stocktake.php">盘点</a>
            <a href="ai.php">AI</a>
            <a href="records.php">记录</a>
            <a href="admin.php">后台</a>
        </nav>

        <main>
            <?php if ($notice): ?>
                <div class="notice success"><?= e($notice) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="notice danger"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($currentSession): ?>
                <section class="panel">
                    <div class="section-head">
                        <h2>添加盘点项目</h2>
                        <span><?= e(stocktake_status_text($currentSession['status'])) ?></span>
                    </div>

                    <?php if ($currentSession['status'] === 'completed'): ?>
                        <p class="empty-state">这个盘点单已完成，不能继续添加。</p>
                    <?php else: ?>
                        <form method="post" class="stack-form">
                            <input type="hidden" name="action" value="add_item">
                            <input type="hidden" name="session_id" value="<?= e($currentSession['id']) ?>">

                            <label class="field-label" for="raw_code">二维码内容</label>
                            <textarea id="raw_code" name="raw_code" rows="2" placeholder="001110742820260311#20260311#20260907" required></textarea>

                            <label class="field-label" for="quantity">数量</label>
                            <input id="quantity" name="quantity" type="number" min="1" step="1" inputmode="numeric" required>

                            <label class="field-label" for="note">备注</label>
                            <input id="note" name="note" autocomplete="off" placeholder="可不填">

                            <button class="primary-button wide" type="submit">添加到盘点单</button>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <h2>盘点明细</h2>
                        <form method="post">
                            <input type="hidden" name="action" value="complete_session">
                            <input type="hidden" name="session_id" value="<?= e($currentSession['id']) ?>">
                            <button class="text-button" type="submit" <?= $currentSession['status'] === 'completed' ? 'disabled' : '' ?>>完成</button>
                        </form>
                    </div>

                    <?php if (!$items): ?>
                        <p class="empty-state">还没有项目。</p>
                    <?php else: ?>
                        <div class="record-list large">
                            <?php foreach ($items as $item): ?>
                                <?php
                                $left = days_left($item['expiry_date']);
                                $status = expiry_status($left, product_alert_days($item));
                                ?>
                                <article class="record-card">
                                    <div class="record-card-head">
                                        <div>
                                            <strong><?= e($item['product_name'] ?: '未录入商品名') ?></strong>
                                            <span>
                                                <?= $item['category'] ? e($item['category']) . ' · ' : '' ?>
                                                SKU <?= e($item['sku']) ?> · 数量 <?= e($item['quantity']) ?>
                                            </span>
                                        </div>
                                        <span class="status-pill <?= e($status['code']) ?>"><?= e($status['text']) ?></span>
                                    </div>
                                    <dl class="record-detail-grid">
                                        <div>
                                            <dt>生产</dt>
                                            <dd><?= e(display_date($item['production_date'])) ?></dd>
                                        </div>
                                        <div>
                                            <dt>到期</dt>
                                            <dd><?= e(display_date($item['expiry_date'])) ?></dd>
                                        </div>
                                        <div>
                                            <dt>剩余</dt>
                                            <dd><?= $left === null ? '-' : e($left . ' 天') ?></dd>
                                        </div>
                                        <div>
                                            <dt>录入</dt>
                                            <dd><?= e(substr((string) $item['created_at'], 5, 11)) ?></dd>
                                        </div>
                                    </dl>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <section class="panel">
                    <h2>创建盘点单</h2>
                    <form method="post" class="stack-form">
                        <input type="hidden" name="action" value="create_session">

                        <label class="field-label" for="session_code">盘点编号</label>
                        <input id="session_code" name="session_code" autocomplete="off" placeholder="<?= e(next_stocktake_code($pdo)) ?>">

                        <label class="field-label" for="note">备注</label>
                        <input id="note" name="note" autocomplete="off" placeholder="早班 / 晚班 / 冷藏柜">

                        <button class="primary-button wide" type="submit">创建盘点单</button>
                    </form>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <h2>盘点历史</h2>
                        <a href="records.php">录入记录</a>
                    </div>

                    <?php if (!$sessions): ?>
                        <p class="empty-state">还没有盘点单。</p>
                    <?php else: ?>
                        <div class="record-list large">
                            <?php foreach ($sessions as $session): ?>
                                <a class="record-card" href="stocktake.php?session_id=<?= e($session['id']) ?>">
                                    <div class="record-card-head">
                                        <div>
                                            <strong><?= e($session['session_code']) ?></strong>
                                            <span><?= e(stocktake_status_text($session['status'])) ?> · <?= e($session['item_count']) ?> 项 · 数量 <?= e($session['total_quantity']) ?></span>
                                        </div>
                                        <span class="status-pill <?= $session['status'] === 'completed' ? 'ok' : 'warning' ?>"><?= e(stocktake_status_text($session['status'])) ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
