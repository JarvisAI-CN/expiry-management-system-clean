-- ========================================
-- API密钥管理表
-- 创建时间: 2026-02-18
-- ========================================

-- API密钥表（使用 SHA256 哈希存储密钥）
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '密钥ID',
  `name` VARCHAR(100) NOT NULL COMMENT '密钥名称（便于识别）',
  `api_key_hash` VARCHAR(64) NOT NULL COMMENT 'API密钥哈希（sha256）',
  `api_key` VARCHAR(64) DEFAULT NULL COMMENT '旧版明文/API密钥字段（兼容保留，不再用于校验）',
  `created_by` INT(11) UNSIGNED NOT NULL COMMENT '创建者用户ID',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `last_used_at` DATETIME DEFAULT NULL COMMENT '最后使用时间',
  `expires_at` DATETIME DEFAULT NULL COMMENT '过期时间（NULL=永不过期）',
  `is_active` TINYINT(1) DEFAULT 1 COMMENT '是否启用：0=禁用，1=启用',
  `scopes` VARCHAR(255) NOT NULL DEFAULT 'read:all' COMMENT '权限范围（逗号分隔的scope列表）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_api_key_hash` (`api_key_hash`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='API密钥管理表';

-- 兼容已有安装：如表已存在且缺少 api_key_hash 字段，则补齐并迁移数据
ALTER TABLE `api_keys`
  ADD COLUMN IF NOT EXISTS `api_key_hash` VARCHAR(64) NULL COMMENT 'API密钥哈希（sha256）' AFTER `name`,
  ADD COLUMN IF NOT EXISTS `api_key` VARCHAR(64) NULL COMMENT '旧版明文/API密钥字段（兼容保留，不再用于校验）' AFTER `api_key_hash`,
  ADD COLUMN IF NOT EXISTS `scopes` VARCHAR(255) NOT NULL DEFAULT 'read:all' COMMENT '权限范围（逗号分隔的scope列表）' AFTER `is_active`;

-- 将旧字段中的明文/哈希迁移到 api_key_hash
UPDATE `api_keys`
   SET `api_key_hash` = COALESCE(`api_key_hash`, SHA2(`api_key`, 256))
 WHERE `api_key` IS NOT NULL
   AND (`api_key_hash` IS NULL OR `api_key_hash` = '');

-- 为新哈希字段创建唯一索引（如尚未存在）
ALTER TABLE `api_keys`
  ADD UNIQUE KEY `uk_api_key_hash` (`api_key_hash`);

-- API访问日志表（可选，用于审计）
CREATE TABLE IF NOT EXISTS `api_logs` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `api_key_id` INT(11) UNSIGNED NOT NULL COMMENT '关联密钥ID',
  `endpoint` VARCHAR(100) NOT NULL COMMENT '访问的接口',
  `request_params` TEXT COMMENT '请求参数',
  `response_code` INT(5) DEFAULT 200 COMMENT 'HTTP状态码',
  `ip_address` VARCHAR(45) DEFAULT NULL COMMENT '客户端IP',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '访问时间',
  PRIMARY KEY (`id`),
  KEY `idx_api_key_id` (`api_key_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='API访问日志表';
