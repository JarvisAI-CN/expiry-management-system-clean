# 编辑盘点单功能 - API 接口清单

**用途**: 开发人员快速参考
**版本**: v1.0
**日期**: 2026-02-21

---

## 📡 API 端点总览

| 端点 | 方法 | 功能 | 状态 |
|------|------|------|------|
| `update_inventory_item` | POST | 更新盘点单商品 | 🆕 新增 |
| `delete_inventory_item` | POST | 删除盘点单商品 | 🆕 新增 |
| `add_inventory_item` | POST | 添加商品到盘点单 | 🆕 新增 |
| `get_session_details` | GET | 获取盘点单详情 | 🔧 修改 |

---

## 🔧 详细接口规范

### 1. update_inventory_item

更新盘点单中商品的数量或到期日期

**请求**:
```http
POST /index.php?api=update_inventory_item
Content-Type: application/json

{
  "batch_id": 123,
  "quantity": 50,              // 可选，不传则不修改
  "expiry_date": "2026-12-31"  // 可选，不传则不修改
}
```

**成功响应** (200):
```json
{
  "success": true,
  "message": "更新成功"
}
```

**错误响应**:
```json
// 400 - 参数错误
{"success": false, "message": "缺少batch_id参数"}

// 404 - 记录不存在
{"success": false, "message": "批次记录不存在"}

// 403 - 无权限
{"success": false, "message": "只能编辑自己创建的盘点单"}

// 400 - 数据验证失败
{"success": false, "message": "数量不能为负数"}
```

**SQL**:
```sql
UPDATE batches 
SET quantity = COALESCE(?, quantity),
    expiry_date = COALESCE(?, expiry_date),
    updated_at = NOW()
WHERE id = ?
```

---

### 2. delete_inventory_item

从盘点单中删除一个商品

**请求**:
```http
POST /index.php?api=delete_inventory_item
Content-Type: application/json

{
  "batch_id": 123
}
```

**成功响应** (200):
```json
{
  "success": true,
  "message": "删除成功",
  "remaining_count": 15  // 删除后剩余商品数
}
```

**错误响应**:
```json
// 400 - 参数错误
{"success": false, "message": "缺少batch_id参数"}

// 404 - 记录不存在
{"success": false, "message": "批次记录不存在"}

// 403 - 无权限
{"success": false, "message": "只能删除自己创建的盘点单中的商品"}
```

**SQL**:
```sql
-- 开始事务
START TRANSACTION;

-- 删除批次
DELETE FROM batches WHERE id = ?;

-- 更新计数
UPDATE inventory_sessions 
SET item_count = (
    SELECT COUNT(*) 
    FROM batches 
    WHERE session_id = ?
)
WHERE session_key = ?;

COMMIT;
```

---

### 3. add_inventory_item

添加新商品到已有盘点单

**请求**:
```http
POST /index.php?api=add_inventory_item
Content-Type: application/json

{
  "session_key": "S1234567890",
  "sku": "6901234567890",
  "name": "可口可乐 500ml",
  "category_id": 1,
  "removal_buffer": 7,
  "batches": [
    {
      "expiry_date": "2026-12-31",
      "quantity": 100
    }
  ]
}
```

**成功响应** (200):
```json
{
  "success": true,
  "message": "添加成功",
  "batch_ids": [123, 124],
  "new_count": 16  // 添加后总商品数
}
```

**错误响应**:
```json
// 400 - 参数错误
{"success": false, "message": "缺少session_key参数"}

// 404 - 盘点单不存在
{"success": false, "message": "盘点单不存在"}

// 400 - 数据验证失败
{"success": false, "message": "到期日期不能早于今天"}
```

**SQL**:
```sql
-- 开始事务
START TRANSACTION;

-- 1. 检查或创建商品
INSERT INTO products (sku, name, category_id, removal_buffer)
VALUES (?, ?, ?, ?)
ON DUPLICATE KEY UPDATE 
    name = VALUES(name),
    category_id = VALUES(category_id),
    removal_buffer = VALUES(removal_buffer);

-- 获取商品ID
SELECT id FROM products WHERE sku = ?;

-- 2. 插入批次
INSERT INTO batches (product_id, expiry_date, quantity, session_id)
VALUES (?, ?, ?, ?);

-- 3. 更新盘点单计数
UPDATE inventory_sessions 
SET item_count = item_count + ?,
    updated_at = NOW()
WHERE session_key = ?;

COMMIT;
```

---

### 4. get_session_details (修改)

获取盘点单详情，新增返回 `batch_id` 等字段

**请求**:
```http
GET /index.php?api=get_session_details&session_id=S1234567890
```

**成功响应** (200):
```json
{
  "success": true,
  "data": [
    {
      "batch_id": 123,        // 🆕 新增：用于编辑
      "product_id": 1,        // 🆕 新增
      "sku": "6901234567890",
      "name": "可口可乐 500ml",
      "expiry_date": "2026-12-31",
      "quantity": 100,
      "category_id": 1,       // 🆕 新增
      "removal_buffer": 7     // 🆕 新增
    }
  ]
}
```

**SQL 修改**:
```sql
SELECT 
    b.id as batch_id,        -- 🆕 新增
    b.product_id,             -- 🆕 新增
    p.sku, 
    p.name, 
    b.expiry_date, 
    b.quantity, 
    p.category_id,            -- 🆕 新增
    p.removal_buffer          -- 🆕 新增
FROM batches b 
JOIN products p ON b.product_id = p.id 
WHERE b.session_id = ? 
ORDER BY DATE_SUB(b.expiry_date, INTERVAL p.removal_buffer DAY) ASC
```

---

## 🗃️ 数据模型

### inventory_sessions 表

| 字段 | 类型 | 说明 | 修改 |
|------|------|------|------|
| id | INT UNSIGNED | 主键 | - |
| session_key | VARCHAR(50) | 唯一标识 | - |
| user_id | INT UNSIGNED | 创建用户 | - |
| item_count | INT | 商品数量 | - |
| created_at | DATETIME | 创建时间 | - |
| updated_at | DATETIME | 更新时间 | 🆕 新增 |

### batches 表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT UNSIGNED | 主键（用于编辑操作） |
| product_id | INT UNSIGNED | 商品ID |
| expiry_date | DATE | 到期日期 |
| quantity | INT | 数量 |
| session_id | VARCHAR(50) | 关联盘点单 |
| created_at | DATETIME | 创建时间 |
| updated_at | DATETIME | 更新时间 |

### products 表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT UNSIGNED | 主键 |
| sku | VARCHAR(100) | 商品SKU |
| name | VARCHAR(200) | 商品名称 |
| category_id | INT UNSIGNED | 分类ID |
| removal_buffer | INT | 提前下架天数 |

---

## 🧪 测试用例

### update_inventory_item
```bash
# 测试1: 更新数量
curl -X POST http://localhost/index.php?api=update_inventory_item \
  -H "Content-Type: application/json" \
  -d '{"batch_id":1,"quantity":50}'

# 测试2: 更新日期
curl -X POST http://localhost/index.php?api=update_inventory_item \
  -H "Content-Type: application/json" \
  -d '{"batch_id":1,"expiry_date":"2026-12-31"}'

# 测试3: 同时更新
curl -X POST http://localhost/index.php?api=update_inventory_item \
  -H "Content-Type: application/json" \
  -d '{"batch_id":1,"quantity":50,"expiry_date":"2026-12-31"}'

# 测试4: 负数数量（应失败）
curl -X POST http://localhost/index.php?api=update_inventory_item \
  -H "Content-Type: application/json" \
  -d '{"batch_id":1,"quantity":-10}'
```

### delete_inventory_item
```bash
# 测试1: 删除存在的商品
curl -X POST http://localhost/index.php?api=delete_inventory_item \
  -H "Content-Type: application/json" \
  -d '{"batch_id":1}'

# 测试2: 删除不存在的商品（应失败）
curl -X POST http://localhost/index.php?api=delete_inventory_item \
  -H "Content-Type: application/json" \
  -d '{"batch_id":99999}'
```

### add_inventory_item
```bash
# 测试1: 添加新商品
curl -X POST http://localhost/index.php?api=add_inventory_item \
  -H "Content-Type: application/json" \
  -d '{
    "session_key":"S1234567890",
    "sku":"6901234567890",
    "name":"测试商品",
    "batches":[{"expiry_date":"2026-12-31","quantity":100}]
  }'

# 测试2: 添加过期商品（应失败）
curl -X POST http://localhost/index.php?api=add_inventory_item \
  -H "Content-Type: application/json" \
  -d '{
    "session_key":"S1234567890",
    "sku":"6901234567890",
    "name":"测试商品",
    "batches":[{"expiry_date":"2020-01-01","quantity":100}]
  }'
```

---

## 📋 开发检查清单

### 后端开发
- [ ] 实现 `update_inventory_item` 接口
  - [ ] 参数验证
  - [ ] 权限检查
  - [ ] 数据验证（数量≥0，日期≥今天）
  - [ ] 事务处理
  - [ ] 操作日志

- [ ] 实现 `delete_inventory_item` 接口
  - [ ] 参数验证
  - [ ] 权限检查
  - [ ] 事务处理（删除批次+更新计数）
  - [ ] 操作日志

- [ ] 实现 `add_inventory_item` 接口
  - [ ] 参数验证
  - [ ] 权限检查
  - [ ] 商品去重处理
  - [ ] 事务处理
  - [ ] 操作日志

- [ ] 修改 `get_session_details` 接口
  - [ ] 增加 `batch_id` 字段
  - [ ] 增加 `product_id` 字段
  - [ ] 增加 `category_id` 字段
  - [ ] 增加 `removal_buffer` 字段

### 前端开发
- [ ] 修改盘点单详情弹窗
  - [ ] 表格改为可编辑
  - [ ] 添加"保存"按钮
  - [ ] 添加"删除"按钮
  - [ ] 添加"添加商品"按钮

- [ ] 实现编辑功能
  - [ ] 行内编辑数量和日期
  - [ ] 保存按钮调用 API
  - [ ] 删除按钮确认并调用 API
  - [ ] 添加商品弹窗

- [ ] 用户体验优化
  - [ ] Loading 状态
  - [ ] 成功/失败提示
  - [ ] 数据刷新
  - [ ] 错误处理

### 测试
- [ ] 单元测试
- [ ] 集成测试
- [ ] 用户测试
- [ ] 性能测试

---

**文档结束**
