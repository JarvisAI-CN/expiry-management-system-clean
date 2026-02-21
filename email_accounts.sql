-- ========================================
-- 保质期管理系统 - 邮箱配置功能
-- 数据库迁移脚本
-- 创建时间: 2026-02-21
-- ========================================

USE `expiry_system`;

-- ========================================
-- 1. 邮箱账户表
-- ========================================
CREATE TABLE IF NOT EXISTS `email_accounts` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '账户ID',
  `qq_number` VARCHAR(20) NOT NULL COMMENT 'QQ号',
  `email_address` VARCHAR(100) NOT NULL COMMENT '完整邮箱地址 (自动生成)',
  `auth_code_encrypted` TEXT NOT NULL COMMENT '加密后的授权码',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用: 1=启用, 0=禁用',
  `priority` INT(11) NOT NULL DEFAULT 0 COMMENT '优先级 (数字越大优先级越高)',
  `send_count` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计发送次数',
  `last_sent_at` DATETIME DEFAULT NULL COMMENT '最后发送时间',
  `last_sent_success` TINYINT(1) DEFAULT NULL COMMENT '最后发送是否成功: 1=成功, 0=失败',
  `error_message` TEXT DEFAULT NULL COMMENT '最后的错误信息',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `created_by` INT(11) UNSIGNED DEFAULT NULL COMMENT '创建人ID (关联users表)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_qq_number` (`qq_number`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_priority` (`priority`),
  KEY `idx_last_sent` (`last_sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮箱账户配置表';

-- ========================================
-- 2. 邮件发送日志表
-- ========================================
CREATE TABLE IF NOT EXISTS `email_logs` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `account_id` INT(11) UNSIGNED NOT NULL COMMENT '邮箱账户ID',
  `recipient` VARCHAR(200) NOT NULL COMMENT '收件人邮箱',
  `subject` VARCHAR(500) NOT NULL COMMENT '邮件主题',
  `body` TEXT DEFAULT NULL COMMENT '邮件正文',
  `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending' COMMENT '发送状态',
  `error_message` TEXT DEFAULT NULL COMMENT '错误信息',
  `sent_at` DATETIME DEFAULT NULL COMMENT '发送时间',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_status` (`status`),
  KEY `idx_sent_at` (`sent_at`),
  KEY `idx_recipient` (`recipient`),
  CONSTRAINT `fk_email_logs_account` FOREIGN KEY (`account_id`) 
    REFERENCES `email_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮件发送日志表';

-- ========================================
-- 3. 初始化测试数据（可选）
-- ========================================
-- INSERT INTO `email_accounts` (`qq_number`, `email_address`, `auth_code_encrypted`, `priority`) 
-- VALUES 
-- ('123456789', '123456789@qq.com', 'encrypted_code_here', 10);

-- ========================================
-- 4. 创建索引优化查询
-- ========================================
-- 复合索引用于轮换算法查询
CREATE INDEX `idx_rotation_selection` ON `email_accounts` (`is_active`, `priority` DESC, `send_count` ASC, `last_sent_at` ASC);

-- ========================================
-- 5. 添加设置项（系统默认邮箱配置）
-- ========================================
INSERT INTO `settings` (`s_key`, `s_value`) VALUES 
('email_smtp_host', 'smtp.qq.com'),
('email_smtp_port', '465'),
('email_smtp_encryption', 'ssl'),
('email_cooldown_seconds', '300')
ON DUPLICATE KEY UPDATE `s_value` = VALUES(`s_value`);

-- ========================================
-- 安装完成提示
-- ========================================
SELECT '邮箱配置功能数据库安装完成！' AS message;
