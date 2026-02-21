CREATE TABLE IF NOT EXISTS `inventory_edit_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='盘点单编辑审计日志';

ALTER TABLE `batches` 
    ADD COLUMN IF NOT EXISTS `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    AFTER `created_at`;
