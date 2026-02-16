-- ========================================
-- 保质期管理系统 v2.8.0-alpha
-- 数据库升级脚本
-- ========================================

-- 1. 盘点任务表 (inventory_tasks)
CREATE TABLE IF NOT EXISTS `inventory_tasks` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '任务ID',
  `product_id` INT(11) UNSIGNED NOT NULL COMMENT '关联商品ID',
  `task_type` VARCHAR(20) NOT NULL COMMENT '任务类型: daily, weekly, monthly, yearly',
  `scheduled_date` DATE NOT NULL COMMENT '计划日期',
  `status` VARCHAR(20) DEFAULT 'pending' COMMENT '状态: pending, in_progress, completed, overdue',
  `completed_at` DATETIME DEFAULT NULL COMMENT '完成时间',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_scheduled_date` (`scheduled_date`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_inventory_tasks_products` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='盘点任务表';

-- 2. 系统配置表 (system_settings)
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL COMMENT '配置键',
  `setting_value` TEXT COMMENT '配置值',
  `description` VARCHAR(255) DEFAULT NULL COMMENT '配置说明',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统配置表';

-- 插入默认预警配置
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('warning_days_level1', '7', '一级预警天数（严重）'),
('warning_days_level2', '15', '二级预警天数（警告）'),
('warning_days_level3', '30', '三级预警天数（提醒）'),
('low_stock_threshold', '10', '低库存阈值'),
('enable_auto_tasks', '1', '是否自动生成盘点任务');

-- 3. 预警日志表 (warning_logs)
CREATE TABLE IF NOT EXISTS `warning_logs` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT(11) UNSIGNED NOT NULL COMMENT '商品ID',
  `batch_id` INT(11) UNSIGNED DEFAULT NULL COMMENT '批次ID',
  `warning_level` VARCHAR(20) NOT NULL COMMENT '预警级别: critical, warning, reminder, low_stock',
  `warning_type` VARCHAR(50) NOT NULL COMMENT '预警类型: expiry, stock',
  `message` TEXT COMMENT '预警消息',
  `is_resolved` TINYINT(1) DEFAULT 0 COMMENT '是否已解决',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_warning_level` (`warning_level`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_is_resolved` (`is_resolved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='预警日志表';

-- 4. 为products表添加last_inventory_at字段（如果不存在）
-- 注意：该字段在v2.7.3已存在，此处仅为兼容性检查
