<?php
/**
 * ========================================
 * 保质期管理系统 - v2.8.0-alpha 升级补丁
 * 文件名: upgrade_v2.8.php
 * ========================================
 */

session_start();
require_once 'db.php';

define('APP_VERSION', '2.8.0-alpha');

// 处理升级请求
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];
    $conn = getDBConnection();

    if ($action === 'upgrade_to_v28') {
        $result = upgradeToV28($conn);
        echo json_encode($result);
        exit;
    }

    if ($action === 'get_upgrade_status') {
        $status = checkUpgradeStatus($conn);
        echo json_encode(['success' => true, 'status' => $status]);
        exit;
    }
}

/**
 * 升级到 v2.8.0-alpha
 */
function upgradeToV28($conn) {
    try {
        $conn->begin_transaction();

        // 1. 创建盘点任务表
        $conn->query("CREATE TABLE IF NOT EXISTS `inventory_tasks` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id` INT(11) UNSIGNED NOT NULL,
            `task_type` VARCHAR(20) NOT NULL COMMENT 'daily, weekly, monthly, yearly',
            `scheduled_date` DATE NOT NULL,
            `status` VARCHAR(20) DEFAULT 'pending',
            `completed_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_product_id` (`product_id`),
            KEY `idx_scheduled_date` (`scheduled_date`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 2. 创建系统配置表
        $conn->query("CREATE TABLE IF NOT EXISTS `system_settings` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `setting_key` VARCHAR(100) NOT NULL,
            `setting_value` TEXT,
            `description` VARCHAR(255) DEFAULT NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_setting_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 3. 插入默认配置
        $conn->query("INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
            ('warning_days_level1', '7', '一级预警天数（严重）'),
            ('warning_days_level2', '15', '二级预警天数（警告）'),
            ('warning_days_level3', '30', '三级预警天数（提醒）'),
            ('low_stock_threshold', '10', '低库存阈值'),
            ('enable_auto_tasks', '1', '是否自动生成盘点任务'),
            ('app_version', '" . APP_VERSION . "', '当前系统版本'");

        // 4. 创建预警日志表
        $conn->query("CREATE TABLE IF NOT EXISTS `warning_logs` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id` INT(11) UNSIGNED NOT NULL,
            `batch_id` INT(11) UNSIGNED DEFAULT NULL,
            `warning_level` VARCHAR(20) NOT NULL COMMENT 'critical, warning, reminder, low_stock',
            `warning_type` VARCHAR(50) NOT NULL COMMENT 'expiry, stock',
            `message` TEXT,
            `is_resolved` TINYINT(1) DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_product_id` (`product_id`),
            KEY `idx_warning_level` (`warning_level`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_is_resolved` (`is_resolved`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 5. 更新VERSION.txt
        file_put_contents(__DIR__ . '/VERSION.txt', APP_VERSION);

        $conn->commit();
        return ['success' => true, 'message' => '成功升级到 v2.8.0-alpha'];
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => '升级失败: ' . $e->getMessage()];
    }
}

/**
 * 检查升级状态
 */
function checkUpgradeStatus($conn) {
    $status = [
        'inventory_tasks_exists' => false,
        'system_settings_exists' => false,
        'warning_logs_exists' => false,
        'current_version' => file_get_contents(__DIR__ . '/VERSION.txt')
    ];

    $result = $conn->query("SHOW TABLES LIKE 'inventory_tasks'");
    $status['inventory_tasks_exists'] = $result && $result->num_rows > 0;

    $result = $conn->query("SHOW TABLES LIKE 'system_settings'");
    $status['system_settings_exists'] = $result && $result->num_rows > 0;

    $result = $conn->query("SHOW TABLES LIKE 'warning_logs'");
    $status['warning_logs_exists'] = $result && $result->num_rows > 0;

    return $status;
}
?>
