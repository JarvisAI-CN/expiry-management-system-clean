<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$pdo = db();
$question = trim((string) ($_POST['question'] ?? ''));
$result = null;
$error = null;
$hasKey = trim((string) get_setting($pdo, 'minimax_token_plan_key', '')) !== '';
$model = get_setting($pdo, 'minimax_model', 'MiniMax-M2.7') ?: 'MiniMax-M2.7';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = call_minimax_inventory_ai($pdo, $question);
    if (!$result['ok']) {
        $error = $result['message'];
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>AI 库存建议</title>
    <link rel="stylesheet" href="assets/app.css?v=20260523-4">
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <div>
                <p class="eyebrow">MiniMax</p>
                <h1>AI 库存建议</h1>
            </div>
            <div class="top-stat">
                <span>模型</span>
                <strong>AI</strong>
            </div>
        </header>

        <nav class="nav-tabs" aria-label="主导航">
            <a href="index.php">扫码</a>
            <a href="dashboard.php">看板</a>
            <a href="stocktake.php">盘点</a>
            <a class="active" href="ai.php">AI</a>
            <a href="records.php">记录</a>
            <a href="admin.php">后台</a>
        </nav>

        <main>
            <?php if (!$hasKey): ?>
                <section class="panel">
                    <h2>先保存 Token Plan Key</h2>
                    <p class="hint-text">到后台的 AI 设置里保存 MiniMax Token Plan Key 后，这里就能根据当前库存生成处理建议。</p>
                    <a class="wide-button" href="admin.php">去后台设置</a>
                </section>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="notice danger"><?= e($error) ?></div>
            <?php endif; ?>

            <section class="panel">
                <div class="section-head">
                    <h2>生成建议</h2>
                    <span><?= e($model) ?></span>
                </div>
                <form method="post" class="stack-form">
                    <label class="field-label" for="question">你想问 AI 什么</label>
                    <textarea id="question" name="question" rows="5" placeholder="请根据当前批次，列出今天优先处理和下架建议。"><?= e($question) ?></textarea>
                    <button class="primary-button wide" type="submit" <?= $hasKey ? '' : 'disabled' ?>>生成建议</button>
                </form>
            </section>

            <?php if ($result && $result['ok']): ?>
                <section class="panel ai-output">
                    <div class="section-head">
                        <h2>AI 建议</h2>
                        <span><?= e($result['model'] ?? $model) ?></span>
                    </div>
                    <div class="ai-result"><?= nl2br(e($result['text'])) ?></div>
                </section>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
