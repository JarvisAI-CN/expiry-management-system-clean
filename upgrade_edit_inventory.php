<?php
/**
 * ========================================
 * 编辑盘点单功能 - 数据库升级脚本
 * 文件名: upgrade_edit_inventory.php
 * 版本: v1.0.0
 * ========================================
 */

session_start();
require_once 'db.php';

// 执行升级
if (isset($_GET['execute_upgrade'])) {
    header('Content-Type: application/json');
    $result = performUpgrade();
    echo json_encode($result);
    exit;
}

/**
 * 执行数据库升级
 */
function performUpgrade() {
    $conn = getDBConnection();

    if (!$conn) {
        return [
            'success' => false,
            'message' => '数据库连接失败'
        ];
    }

    try {
        $conn->begin_transaction();

        // 1. 创建审计日志表
        $conn->query("CREATE TABLE IF NOT EXISTS `inventory_edit_logs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `session_id` VARCHAR(50) NOT NULL COMMENT '盘点单ID',
            `batch_id` INT UNSIGNED DEFAULT NULL COMMENT '批次ID',
            `action` VARCHAR(20) NOT NULL COMMENT '操作类型: update, delete, add',
            `old_value` JSON DEFAULT NULL COMMENT '修改前的值',
            `new_value` JSON DEFAULT NULL COMMENT '修改后的值',
            `user_id` INT UNSIGNED NOT NULL COMMENT '操作人ID',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '操作时间',
            PRIMARY KEY (`id`),
            KEY `idx_session_id` (`session_id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='盘点单编辑审计日志'");

        // 2. 为batches表添加updated_at字段（如果不存在）
        $checkColumn = $conn->query("SHOW COLUMNS FROM `batches` LIKE 'updated_at'");
        if ($checkColumn && $checkColumn->num_rows == 0) {
            $conn->query("ALTER TABLE `batches`
                ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                AFTER `created_at`");
        }

        $conn->commit();

        return [
            'success' => true,
            'message' => '✅ 数据库升级成功！已添加编辑盘点单功能所需的表和字段。',
            'details' => [
                'inventory_edit_logs' => '审计日志表已创建',
                'batches.updated_at' => '批次表更新时间字段已添加'
            ]
        ];

    } catch (Exception $e) {
        $conn->rollback();
        return [
            'success' => false,
            'message' => '❌ 升级失败: ' . $e->getMessage()
        ];
    }
}

/**
 * 检查升级状态
 */
function checkUpgradeStatus() {
    $conn = getDBConnection();
    if (!$conn) return false;

    // 检查审计日志表
    $result = $conn->query("SHOW TABLES LIKE 'inventory_edit_logs'");
    $hasLogsTable = $result && $result->num_rows > 0;

    // 检查batches表的updated_at字段
    $result = $conn->query("SHOW COLUMNS FROM `batches` LIKE 'updated_at'");
    $hasUpdatedField = $result && $result->num_rows > 0;

    return [
        'has_edit_logs_table' => $hasLogsTable,
        'has_batches_updated_at' => $hasUpdatedField,
        'is_ready' => $hasLogsTable && $hasUpdatedField
    ];
}

// 如果直接访问，显示状态页面
if (!isset($_GET['execute_upgrade'])) {
    $status = checkUpgradeStatus();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>编辑盘点单功能 - 数据库升级</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f5f7; padding: 40px 0; }
        .upgrade-card {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .status-item { padding: 10px; margin: 5px 0; border-radius: 6px; }
        .status-ok { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
    </style>
</head>
<body>
    <div class="upgrade-card">
        <h2 class="mb-4">📦 编辑盘点单功能 - 数据库升级</h2>

        <h5 class="mb-3">当前状态</h5>
        <div class="status-item <?php echo $status['has_edit_logs_table'] ? 'status-ok' : 'status-pending'; ?>">
            <?php echo $status['has_edit_logs_table'] ? '✅' : '⏳'; ?>
            审计日志表 (inventory_edit_logs)
        </div>
        <div class="status-item <?php echo $status['has_batches_updated_at'] ? 'status-ok' : 'status-pending'; ?>">
            <?php echo $status['has_batches_updated_at'] ? '✅' : '⏳'; ?>
            批次表更新字段 (batches.updated_at)
        </div>

        <?php if ($status['is_ready']): ?>
            <div class="alert alert-success mt-4">
                <strong>✅ 升级已完成！</strong>
                <p>编辑盘点单功能所需的数据库结构已就绪。</p>
            </div>
            <a href="index.php" class="btn btn-success">返回系统</a>
        <?php else: ?>
            <div class="alert alert-warning mt-4">
                <strong>⚠️ 需要升级</strong>
                <p>数据库需要添加编辑盘点单功能所需的表和字段。</p>
            </div>
            <button onclick="executeUpgrade()" class="btn btn-primary w-100">
                🚀 开始升级
            </button>
        <?php endif; ?>

        <div id="upgradeResult" class="mt-3" style="display:none;"></div>
    </div>

    <script>
        function executeUpgrade() {
            if (!confirm('确定要开始升级吗？此操作将修改数据库结构。')) {
                return;
            }

            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>升级中...';

            fetch('upgrade_edit_inventory.php?execute_upgrade=1')
                .then(r => r.json())
                .then(data => {
                    const resultDiv = document.getElementById('upgradeResult');
                    resultDiv.style.display = 'block';

                    if (data.success) {
                        resultDiv.innerHTML = `
                            <div class="alert alert-success">
                                <strong>✅ 升级成功！</strong>
                                <p>${data.message}</p>
                                <ul>
                                    ${Object.entries(data.details || {}).map(([k, v]) =>
                                        `<li>${v}</li>`
                                    ).join('')}
                                </ul>
                                <a href="index.php" class="btn btn-success mt-2">返回系统</a>
                            </div>
                        `;
                    } else {
                        resultDiv.innerHTML = `
                            <div class="alert alert-danger">
                                <strong>❌ 升级失败</strong>
                                <p>${data.message}</p>
                            </div>
                        `;
                        btn.disabled = false;
                        btn.innerHTML = '🚀 重试升级';
                    }
                })
                .catch(err => {
                    document.getElementById('upgradeResult').innerHTML = `
                        <div class="alert alert-danger">
                            <strong>❌ 网络错误</strong>
                            <p>${err.message}</p>
                        </div>
                    `;
                    btn.disabled = false;
                    btn.innerHTML = '🚀 重试升级';
                });
        }
    </script>
</body>
</html>
<?php
}
?>
