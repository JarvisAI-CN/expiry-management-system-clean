<?php
/**
 * SKU维护 - 数据库表创建脚本
 * 运行一次即可创建所需表结构
 */

require_once 'db.php';

echo "开始创建SKU维护相关表...\n";

$conn = getDBConnection();
if (!$conn) {
    die("数据库连接失败\n");
}

// 1. SKU待办表
$sql = "CREATE TABLE IF NOT EXISTS `sku_todos` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sku` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(200) NOT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `inventory_cycle` ENUM('weekly', 'monthly', 'quarterly', 'yearly', 'none') DEFAULT 'none',
  `status` ENUM('pending', 'done') DEFAULT 'pending',
  `source_file` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_sku` (`sku`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "✓ sku_todos 表创建成功\n";
} else {
    echo "✗ sku_todos 表创建失败: " . $conn->error . "\n";
}

// 2. SKU上传任务队列表
$sql = "CREATE TABLE IF NOT EXISTS `sku_upload_tasks` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `filename` VARCHAR(255) NOT NULL,
  `status` ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
  `total_rows` INT DEFAULT 0,
  `new_skus` INT DEFAULT 0,
  `missing_skus` INT DEFAULT 0,
  `duplicate_skus` INT DEFAULT 0,
  `result_data` TEXT DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_status` (`status`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "✓ sku_upload_tasks 表创建成功\n";
} else {
    echo "✗ sku_upload_tasks 表创建失败: " . $conn->error . "\n";
}

echo "\n表结构创建完成！\n";

