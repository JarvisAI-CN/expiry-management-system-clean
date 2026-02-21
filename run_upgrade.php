<?php
/**
 * ========================================
 * 编辑盘点单功能 - 命令行升级脚本
 * 文件名: run_upgrade.php
 * 版本: v1.0.0
 * ========================================
 */

require_once 'db.php';

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

// 执行升级
$result = performUpgrade();

// 输出结果
if ($result['success']) {
    echo "\033[32m" . $result['message'] . "\033[0m\n";
    if (isset($result['details'])) {
        foreach ($result['details'] as $key => $value) {
            echo "  - " . $value . "\n";
        }
    }
} else {
    echo "\033[31m" . $result['message'] . "\033[0m\n";
}
?>
