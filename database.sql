-- ========================================
-- 保质期管理系统 - 数据库结构
-- 创建时间: 2026-02-15
-- ========================================

-- 创建数据库（如果不存在）
CREATE DATABASE IF NOT EXISTS `expiry_system` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `expiry_system`;

-- ========================================
-- 1. 商品基础信息表 (products)
-- ========================================
CREATE TABLE IF NOT EXISTS `products` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '商品ID',
  `category_id` INT(11) UNSIGNED DEFAULT 0 COMMENT '分类ID',
  `sku` VARCHAR(100) NOT NULL COMMENT '商品SKU/条形码',
  `name` VARCHAR(200) NOT NULL COMMENT '商品名称',
  `removal_buffer` INT(5) UNSIGNED DEFAULT 0 COMMENT '提前下架天数',
  `inventory_cycle` VARCHAR(20) DEFAULT 'none' COMMENT '盘点周期: daily, weekly, monthly, yearly, none',
  `last_inventory_at` DATETIME DEFAULT NULL COMMENT '上次盘点时间',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sku` (`sku`),
  KEY `idx_name` (`name`),
  KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='商品基础信息表';

-- ========================================
-- 1.1 商品分类表 (categories)
-- ========================================
CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `type` VARCHAR(20) NOT NULL COMMENT '小食品, 物料, 咖啡豆',
  `rule` TEXT COMMENT 'JSON格式的规则定义',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品分类表';

-- 初始化默认分类
INSERT IGNORE INTO `categories` (`name`, `type`, `rule`) VALUES 
('小食品', 'snack', '{"need_buffer":true, "scrap_on_removal":true}'),
('物料', 'material', '{"need_buffer":false, "scrap_on_removal":false}'),
('咖啡豆', 'coffee', '{"need_buffer":true, "scrap_on_removal":false, "allow_gift":true}');

-- ========================================
-- 2. 批次有效期表 (batches)
-- ========================================
CREATE TABLE IF NOT EXISTS `batches` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '批次ID',
  `product_id` INT(11) UNSIGNED NOT NULL COMMENT '关联商品ID',
  `expiry_date` DATE NOT NULL COMMENT '到期日期',
  `quantity` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '数量',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_expiry_date` (`expiry_date`),
  KEY `idx_product_expiry` (`product_id`, `expiry_date`),
  CONSTRAINT `fk_batches_products` FOREIGN KEY (`product_id`) 
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='批次有效期表';

-- ========================================
-- 初始化测试数据（可选）
-- ========================================
-- 插入测试商品
INSERT INTO `products` (`sku`, `name`) VALUES 
('6901234567890', '可口可乐 500ml'),
('6901234567891', '康师傅红烧牛肉面');

-- 插入测试批次
INSERT INTO `batches` (`product_id`, `expiry_date`, `quantity`) VALUES 
(1, '2026-12-31', 100),
(1, '2027-06-30', 50),
(2, '2026-03-15', 200);

-- ========================================
-- 查询示例
-- ========================================
-- 查看所有商品及其批次
-- SELECT p.id, p.sku, p.name, b.expiry_date, b.quantity 
-- FROM products p 
-- LEFT JOIN batches b ON p.id = b.product_id 
-- ORDER BY p.id, b.expiry_date;

-- 查找即将过期的商品（30天内）
-- SELECT p.sku, p.name, b.expiry_date, b.quantity 
-- FROM products p 
-- JOIN batches b ON p.id = b.product_id 
-- WHERE b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
-- ORDER BY b.expiry_date;
