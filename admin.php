<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$pdo = db();
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_product') {
            $saved = upsert_product(
                $pdo,
                (string) ($_POST['sku'] ?? ''),
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['category'] ?? ''),
                $_POST['alert_days'] ?? null,
                $_POST['shelf_remove_days'] ?? null
            );
            $notice = $saved ? '商品已保存。' : 'SKU 和商品名称都要填写。';
        }

        if ($action === 'paste_import') {
            $result = import_products_from_text($pdo, (string) ($_POST['products_text'] ?? ''));
            $notice = "已导入 {$result['imported']} 条，跳过 {$result['skipped']} 条。";
        }

        if ($action === 'upload_csv') {
            if (!isset($_FILES['products_file']) || $_FILES['products_file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('文件上传失败。');
            }

            $content = file_get_contents($_FILES['products_file']['tmp_name']);
            if ($content === false || trim($content) === '') {
                throw new RuntimeException('文件内容为空。');
            }

            $result = import_products_from_text($pdo, $content);
            $notice = "已导入 {$result['imported']} 条，跳过 {$result['skipped']} 条。";
        }

        if ($action === 'delete_product') {
            $sku = normalize_sku((string) ($_POST['sku'] ?? ''));
            $stmt = $pdo->prepare('DELETE FROM products WHERE sku = :sku');
            $stmt->execute([':sku' => $sku]);
            $notice = '商品已删除。';
        }

        if ($action === 'save_ai_settings') {
            $newKey = trim((string) ($_POST['minimax_key'] ?? ''));
            $clearKey = isset($_POST['clear_minimax_key']);
            $model = trim((string) ($_POST['minimax_model'] ?? 'MiniMax-M2.7'));
            $endpoint = trim((string) ($_POST['minimax_endpoint'] ?? 'https://api.minimaxi.com/anthropic/v1/messages'));

            if ($clearKey) {
                set_setting($pdo, 'minimax_token_plan_key', null);
            } elseif ($newKey !== '') {
                set_setting($pdo, 'minimax_token_plan_key', $newKey);
            }

            set_setting($pdo, 'minimax_model', $model !== '' ? $model : 'MiniMax-M2.7');
            set_setting($pdo, 'minimax_endpoint', $endpoint !== '' ? $endpoint : 'https://api.minimaxi.com/anthropic/v1/messages');
            $notice = 'AI 设置已保存。';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$params = [];
$where = '';

if ($q !== '') {
    $where = 'WHERE sku LIKE :q OR sku LIKE :q_sku OR display_sku LIKE :q OR name LIKE :q OR category LIKE :q';
    $params[':q'] = '%' . $q . '%';
    $params[':q_sku'] = '%' . normalize_sku($q) . '%';
}

$stmt = $pdo->prepare("SELECT * FROM products {$where} ORDER BY updated_at DESC, sku ASC LIMIT 300");
$stmt->execute($params);
$products = $stmt->fetchAll();

$count = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$categoryCount = (int) $pdo->query("SELECT COUNT(DISTINCT category) FROM products WHERE category IS NOT NULL AND category <> ''")->fetchColumn();
$minimaxKey = get_setting($pdo, 'minimax_token_plan_key', '');
$minimaxModel = get_setting($pdo, 'minimax_model', 'MiniMax-M2.7') ?: 'MiniMax-M2.7';
$minimaxEndpoint = get_setting($pdo, 'minimax_endpoint', 'https://api.minimaxi.com/anthropic/v1/messages') ?: 'https://api.minimaxi.com/anthropic/v1/messages';
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>SKU 后台</title>
    <link rel="stylesheet" href="assets/app.css?v=20260523-4">
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <div>
                <p class="eyebrow">后台</p>
                <h1>SKU 商品库</h1>
            </div>
            <div class="top-stat">
                <span>商品</span>
                <strong><?= e($count) ?></strong>
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
                <div class="section-head">
                    <h2>单个商品</h2>
                    <span><?= e($categoryCount) ?> 个分类</span>
                </div>
                <form method="post" class="stack-form">
                    <input type="hidden" name="action" value="save_product">

                    <label class="field-label" for="sku">SKU</label>
                    <input id="sku" name="sku" inputmode="numeric" autocomplete="off" placeholder="11107428" required>

                    <label class="field-label" for="name">商品名称</label>
                    <input id="name" name="name" autocomplete="off" placeholder="柠檬浓缩汁" required>

                    <label class="field-label" for="category">分类</label>
                    <input id="category" name="category" autocomplete="off" placeholder="糖浆 / 奶类 / 果汁">

                    <label class="field-label" for="alert_days">提前提醒天数</label>
                    <input id="alert_days" name="alert_days" type="number" min="0" max="365" step="1" inputmode="numeric" value="7">

                    <label class="field-label" for="shelf_remove_days">提前下架天数</label>
                    <input id="shelf_remove_days" name="shelf_remove_days" type="number" min="0" max="365" step="1" inputmode="numeric" value="0">

                    <button class="primary-button wide" type="submit">保存商品</button>
                </form>
            </section>

            <section class="panel">
                <div class="section-head">
                    <h2>发布版模块</h2>
                    <span>v3.1.0</span>
                </div>
                <div class="quick-links">
                    <a class="wide-button" href="stocktake.php">盘点单</a>
                    <a class="wide-button" href="categories.php">分类规则</a>
                    <a class="wide-button" href="records.php">导出记录</a>
                </div>
            </section>

            <section class="panel">
                <div class="section-head">
                    <h2>AI 设置</h2>
                    <span><?= e(mask_secret($minimaxKey)) ?></span>
                </div>
                <form method="post" class="stack-form">
                    <input type="hidden" name="action" value="save_ai_settings">

                    <label class="field-label" for="minimax_key">MiniMax Token Plan Key</label>
                    <input id="minimax_key" name="minimax_key" type="password" autocomplete="off" placeholder="留空表示不修改已保存 Key">

                    <label class="field-label" for="minimax_model">模型</label>
                    <input id="minimax_model" name="minimax_model" value="<?= e($minimaxModel) ?>" autocomplete="off">

                    <label class="field-label" for="minimax_endpoint">接口地址</label>
                    <input id="minimax_endpoint" name="minimax_endpoint" value="<?= e($minimaxEndpoint) ?>" autocomplete="off">

                    <label class="check-row">
                        <input name="clear_minimax_key" type="checkbox" value="1">
                        <span>清空已保存的 Key</span>
                    </label>

                    <button class="wide-button" type="submit">保存 AI 设置</button>
                </form>
            </section>

            <section class="panel">
                <h2>批量导入</h2>
                <p class="hint-text">支持 SKU、商品名称、分类、提醒天数、下架天数。只有前两列也可以照常导入。</p>
                <form method="post" enctype="multipart/form-data" class="stack-form">
                    <input type="hidden" name="action" value="upload_csv">
                    <label class="field-label" for="products_file">CSV 或 TXT 文件</label>
                    <input id="products_file" name="products_file" type="file" accept=".csv,.txt,text/csv,text/plain">
                    <button class="wide-button" type="submit">上传导入</button>
                </form>

                <form method="post" class="stack-form paste-form">
                    <input type="hidden" name="action" value="paste_import">
                    <label class="field-label" for="products_text">从 Excel 复制后粘贴</label>
                    <textarea id="products_text" name="products_text" rows="6" placeholder="SKU,商品名称,分类,提醒天数,下架天数&#10;11107428,柠檬浓缩汁,果汁,7,0"></textarea>
                    <button class="wide-button" type="submit">粘贴导入</button>
                </form>
            </section>

            <section class="panel">
                <div class="section-head">
                    <h2>商品列表</h2>
                    <form method="get" class="search-form">
                        <input name="q" value="<?= e($q) ?>" placeholder="搜索 SKU/名称/分类">
                    </form>
                </div>

                <?php if (!$products): ?>
                    <p class="empty-state">没有找到商品。</p>
                <?php else: ?>
                    <div class="product-list">
                        <?php foreach ($products as $product): ?>
                            <article class="product-row">
                                <div>
                                    <strong><?= e($product['name']) ?></strong>
                                    <span>
                                        SKU <?= e($product['sku']) ?>
                                        <?php if ($product['category']): ?>
                                            · <?= e($product['category']) ?>
                                        <?php endif; ?>
                                        · <?= e(product_alert_days($product)) ?> 天提醒
                                    </span>
                                </div>
                                <form method="post" onsubmit="return confirm('删除这个 SKU？');">
                                    <input type="hidden" name="action" value="delete_product">
                                    <input type="hidden" name="sku" value="<?= e($product['sku']) ?>">
                                    <button class="text-button danger-text" type="submit">删除</button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
