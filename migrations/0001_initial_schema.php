<?php
/**
 * 迁移：initial_schema
 * 版本：1
 * 作者：系统自动生成
 * 日期：2026-02-24
 */

class InitialSchemaMigration {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function up() {
        // 向上迁移脚本
        $sql = <<<SQL
-- 创建用户表
CREATE TABLE IF NOT EXISTS `users` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '用户ID',
    `username` varchar(50) NOT NULL COMMENT '用户名',
    `password` varchar(255) NOT NULL COMMENT '密码（加密）',
    `email` varchar(100) NOT NULL COMMENT '邮箱地址',
    `role` enum('user','admin') NOT NULL DEFAULT 'user' COMMENT '用户角色',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否激活',
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='系统用户表';

-- 创建系统配置表
CREATE TABLE IF NOT EXISTS `system_configs` (
    `config_key` varchar(100) NOT NULL COMMENT '配置项键名',
    `config_value` text COMMENT '配置项值',
    `config_type` varchar(50) NOT NULL DEFAULT 'string' COMMENT '配置类型',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统配置表';

-- 创建邮件账号表
CREATE TABLE IF NOT EXISTS `email_accounts` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '账号ID',
    `qq_number` varchar(20) NOT NULL COMMENT 'QQ号码',
    `auth_code` varchar(100) NOT NULL COMMENT '授权码',
    `last_used_at` timestamp NULL DEFAULT NULL COMMENT '最后使用时间',
    `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否激活',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `qq_number` (`qq_number`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='邮件账号表';

-- 创建分类表
CREATE TABLE IF NOT EXISTS `categories` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '分类ID',
    `name` varchar(100) NOT NULL COMMENT '分类名称',
    `early_dispose_days` int(11) NOT NULL DEFAULT 0 COMMENT '提前报废天数',
    `shelf_remove_days` int(11) NOT NULL DEFAULT 0 COMMENT '提前下架天数',
    `check_frequency` varchar(10) NOT NULL DEFAULT 'daily' COMMENT '盘点频次',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='物料分类表';

-- 创建物料表
CREATE TABLE IF NOT EXISTS `products` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '物料ID',
    `sku` varchar(50) NOT NULL COMMENT 'SKU编码',
    `name` varchar(200) NOT NULL COMMENT '物料名称',
    `category_id` int(11) DEFAULT NULL COMMENT '分类ID',
    `company_category_raw` varchar(200) DEFAULT NULL COMMENT '原始分类（Excel导入时）',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `sku` (`sku`),
    KEY `category_id` (`category_id`),
    CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='物料表';

-- 创建盘点会话表
CREATE TABLE IF NOT EXISTS `stocktake_sessions` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '盘点ID',
    `session_code` varchar(50) NOT NULL COMMENT '盘点编号',
    `user_id` int(11) NOT NULL COMMENT '用户ID',
    `status` enum('draft','completed') NOT NULL DEFAULT 'draft' COMMENT '状态',
    `ai_analysis` text COMMENT 'AI分析结果',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `session_code` (`session_code`),
    KEY `user_id` (`user_id`),
    CONSTRAINT `stocktake_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='盘点会话表';

-- 创建数据导入待办表
CREATE TABLE IF NOT EXISTS `import_todo` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '待办ID',
    `sku` varchar(50) NOT NULL COMMENT 'SKU编码',
    `product_name` varchar(200) NOT NULL COMMENT '物料名称',
    `company_category_raw` varchar(200) NOT NULL COMMENT '原始分类',
    `user_id` int(11) NOT NULL COMMENT '用户ID',
    `status` enum('pending','processed','failed') NOT NULL DEFAULT 'pending' COMMENT '处理状态',
    `error_message` text COMMENT '错误信息',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    CONSTRAINT `import_todo_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='数据导入待办表';

-- 创建盘点明细表
CREATE TABLE IF NOT EXISTS `stocktake_items` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '盘点明细ID',
    `session_id` int(11) NOT NULL COMMENT '盘点ID',
    `product_id` int(11) NOT NULL COMMENT '物料ID',
    `sku` varchar(50) NOT NULL COMMENT 'SKU编码',
    `product_name` varchar(200) NOT NULL COMMENT '物料名称',
    `quantity` int(11) NOT NULL DEFAULT 0 COMMENT '数量',
    `expiry_date` date NOT NULL COMMENT '到期日期',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    PRIMARY KEY (`id`),
    KEY `session_id` (`session_id`),
    KEY `product_id` (`product_id`),
    CONSTRAINT `stocktake_items_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `stocktake_sessions` (`id`),
    CONSTRAINT `stocktake_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='盘点明细表';

-- 初始化系统配置
INSERT IGNORE INTO `system_configs` (`config_key`, `config_value`, `config_type`) VALUES 
('system_title', '星巴克门店智能效期管理系统', 'string'),
('system_version', '3.0.0', 'string'),
('ai_endpoint', 'https://api.openai.com/v1', 'string'),
('ai_model', 'gpt-4o', 'string'),
('ai_timeout', '30', 'number'),
('email_sender_name', '星巴克效期管理系统', 'string'),
('email_sender_email', 'no-reply@starbucks-expiry.com', 'string');

-- 初始化默认分类
INSERT IGNORE INTO `categories` (`name`, `early_dispose_days`, `shelf_remove_days`, `check_frequency`) VALUES 
('糕点类', 2, 1, 'daily'),
('鲜奶类', 1, 0.5, 'daily'),
('咖啡豆', 7, 3, 'weekly'),
('常温物料-不提前报废', 0, 0, 'monthly'),
('其他', 3, 1, 'weekly');

-- 插入默认管理员（密码：admin123）
INSERT IGNORE INTO `users` (`username`, `password`, `email`, `role`) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@starbucks-expiry.com', 'admin');
SQL;
        
        $this->pdo->exec($sql);
    }

    public function down() {
        // 向下迁移脚本
        $sql = <<<SQL
-- 删除所有表（按依赖顺序）
DROP TABLE IF EXISTS `stocktake_items`;
DROP TABLE IF EXISTS `import_todo`;
DROP TABLE IF EXISTS `stocktake_sessions`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `email_accounts`;
DROP TABLE IF EXISTS `system_configs`;
DROP TABLE IF EXISTS `users`;
SQL;
        
        $this->pdo->exec($sql);
    }
}
