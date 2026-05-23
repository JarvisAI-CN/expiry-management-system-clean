<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$pdo = db();
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_category') {
            $name = (string) ($_POST['name'] ?? '');
            $earlyDisposeDays = $_POST['early_dispose_days'] ?? null;
            $shelfRemoveDays = $_POST['shelf_remove_days'] ?? null;
            $frequency = (string) ($_POST['check_frequency'] ?? 'daily');

            if (!upsert_category($pdo, $name, $earlyDisposeDays, $shelfRemoveDays, $frequency)) {
                throw new RuntimeException('分类名称不能为空。');
            }

            if (isset($_POST['apply_to_products'])) {
                $stmt = $pdo->prepare(
                    'UPDATE products
                     SET alert_days = :alert_days,
                         shelf_remove_days = :shelf_remove_days,
                         updated_at = :updated_at
                     WHERE category = :category'
                );
                $stmt->execute([
                    ':alert_days' => normalize_day_count($earlyDisposeDays, 7, 0, 365),
                    ':shelf_remove_days' => normalize_day_count($shelfRemoveDays, 0, 0, 365),
                    ':updated_at' => now_string(),
                    ':category' => trim($name),
                ]);
            }

            $notice = '分类规则已保存。';
        }

        if ($action === 'delete_category') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $stmt = $pdo->prepare('DELETE FROM categories WHERE name = :name');
            $stmt->execute([':name' => $name]);

            $stmt = $pdo->prepare('UPDATE products SET category = NULL, updated_at = :updated_at WHERE category = :name');
            $stmt->execute([':updated_at' => now_string(), ':name' => $name]);

            $notice = '分类已删除，相关商品已转为未分类。';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$categories = list_categories($pdo);
$productCounts = [];
$stmt = $pdo->query("SELECT COALESCE(category, '未分类') AS category_name, COUNT(*) AS total FROM products GROUP BY COALESCE(category, '未分类')");
foreach ($stmt->fetchAll() as $row) {
    $productCounts[$row['category_name']] = (int) $row['total'];
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>分类规则</title>
    <link rel="stylesheet" href="assets/app.css?v=20260523-4">
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <div>
                <p class="eyebrow">后台</p>
                <h1>分类规则</h1>
            </div>
            <div class="top-stat">
                <span>分类</span>
                <strong><?= e(count($categories)) ?></strong>
            </div>
        </header>

        <nav class="nav-tabs" aria-label="主导航">
            <a href="index.php">扫码</a>
            <a href="dashboard.php">看板</a>
            <a href="stocktake.php">盘点</a>
            <a href="ai.php">AI</a>
            <a href="records.php">记录</a>
            <a class="active" href="admin.php">后台</a>
        </nav>

        <main>
            <?php if ($notice): ?>
                <div class="notice success"><?= e($notice) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="notice danger"><?= e($error) ?></div>
            <?php endif; ?>

            <section class="panel">
                <h2>新增/更新分类</h2>
                <form method="post" class="stack-form">
                    <input type="hidden" name="action" value="save_category">

                    <label class="field-label" for="name">分类名称</label>
                    <input id="name" name="name" autocomplete="off" placeholder="糖浆/果汁" required>

                    <label class="field-label" for="early_dispose_days">提前提醒天数</label>
                    <input id="early_dispose_days" name="early_dispose_days" type="number" min="0" max="365" step="1" inputmode="numeric" value="7">

                    <label class="field-label" for="shelf_remove_days">提前下架天数</label>
                    <input id="shelf_remove_days" name="shelf_remove_days" type="number" min="0" max="365" step="1" inputmode="numeric" value="0">

                    <label class="field-label" for="check_frequency">盘点频次</label>
                    <select id="check_frequency" name="check_frequency">
                        <option value="daily">每天</option>
                        <option value="weekly">每周</option>
                        <option value="monthly">每月</option>
                    </select>

                    <label class="check-row">
                        <input name="apply_to_products" type="checkbox" value="1">
                        <span>同步更新这个分类下已有商品的提醒规则</span>
                    </label>

                    <button class="primary-button wide" type="submit">保存分类</button>
                </form>
            </section>

            <section class="panel">
                <div class="section-head">
                    <h2>分类列表</h2>
                    <a href="admin.php">商品库</a>
                </div>

                <?php if (!$categories): ?>
                    <p class="empty-state">还没有分类。</p>
                <?php else: ?>
                    <div class="record-list large">
                        <?php foreach ($categories as $category): ?>
                            <article class="record-card">
                                <div class="record-card-head">
                                    <div>
                                        <strong><?= e($category['name']) ?></strong>
                                        <span>
                                            <?= e((string) ($productCounts[$category['name']] ?? 0)) ?> 个商品
                                            · <?= e($category['early_dispose_days']) ?> 天提醒
                                            · <?= e($category['shelf_remove_days']) ?> 天下架
                                        </span>
                                    </div>
                                    <form method="post" onsubmit="return confirm('删除这个分类？');">
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="name" value="<?= e($category['name']) ?>">
                                        <button class="text-button danger-text" type="submit">删除</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
