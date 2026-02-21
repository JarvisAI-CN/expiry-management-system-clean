<?php
/**
 * ========================================
 * 创建审计日志表
 * 文件名: create_log_table.php
 * 版本: v1.0.0
 * ========================================
 */

// 引入数据库连接
require_once 'db.php';

// 获取数据库连接
$conn = getDBConnection();

if (!$conn) {
    die('数据库连接失败');
}

// 检查并创建审计日志表
$sql = "CREATE TABLE IF NOT EXISTS `inventory_edit_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='盘点单编辑审计日志';";

if ($conn->query($sql)) {
    echo "✅ 审计日志表创建成功！\n";
} else {
    echo "❌ 审计日志表创建失败: " . $conn->error . "\n";
}

// 检查并添加 batches.updated_at 字段（如果不存在）
$sql = "SHOW COLUMNS FROM `batches` LIKE 'updated_at'";
$result = $conn->query($sql);
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE `batches` ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`";
    if ($conn->query($sql)) {
        echo "✅ batches.updated_at 字段添加成功！\n";
    } else {
        echo "❌ batches.updated_at 字段添加失败: " . $conn->error . "\n";
    }
} else {
    echo "✅ batches.updated_at 字段已存在！\n";
}

$conn->close();
?>
