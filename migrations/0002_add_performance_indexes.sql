-- 添加性能优化索引
-- 执行时间: 2026-03-06
-- 用途: 优化盘点数据查询和导出性能

-- 盘点明细索引：优化按会话ID和到期日期的查询
CREATE INDEX IF NOT EXISTS idx_stocktake_entries_session_expiry
ON stocktake_entries(session_id, expiry_date);

-- 盘点会话索引：优化按创建时间排序
CREATE INDEX IF NOT EXISTS idx_stocktake_sessions_created_at
ON stocktake_sessions(created_at);

-- 盘点会话状态索引：优化按状态筛选
CREATE INDEX IF NOT EXISTS idx_stocktake_sessions_status
ON stocktake_sessions(status);

-- 商品SKU索引：优化商品查询
CREATE INDEX IF NOT EXISTS idx_products_sku
ON products(sku);

-- 商品分类索引：优化按分类筛选商品
CREATE INDEX IF NOT EXISTS idx_products_category
ON products(category_id);
