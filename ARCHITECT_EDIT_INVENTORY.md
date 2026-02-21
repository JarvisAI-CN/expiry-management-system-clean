# 编辑盘点单功能 - 架构设计文档

**作者**: The Architect (贾维斯)
**日期**: 2026-02-21
**版本**: v1.0
**状态**: 设计阶段

---

## 📋 需求分析

### 现有功能
1. ✅ 创建盘点单 (`save_product` + `submit_session`)
2. ✅ 查看盘点单详情 (`get_session_details`)
3. ✅ 删除盘点单 (`delete_inventory_session`)
4. ✅ 发送盘点单邮件 (`send_inventory_email`)

### 缺失功能
- ❌ 编辑盘点单中的商品数量
- ❌ 修改商品的到期日期
- ❌ 删除盘点单中的单个商品
- ❌ 添加新商品到已有盘点单

### 用户需求
用户希望能够：
1. 在查看盘点单详情时，直接修改商品数量
2. 修正错误的到期日期
3. 删除误录入的商品
4. 继续添加商品到未完成的盘点单

---

## 🏗️ 系统架构

### 数据流图

```
┌─────────────────┐
│   前端界面      │
│  (Edit Modal)   │
└────────┬────────┘
         │ HTTP POST
         ▼
┌─────────────────────────────────┐
│      API 层 (index.php)         │
│  ┌───────────────────────────┐  │
│  │ update_inventory_item     │  │ 更新商品
│  │ delete_inventory_item     │  │ 删除商品
│  │ add_inventory_item        │  │ 添加商品
│  │ get_session_details       │  │ 获取详情
│  └───────────────────────────┘  │
└────────┬────────────────────────┘
         │ SQL Query
         ▼
┌─────────────────────────────────┐
│      数据层 (MySQL)              │
│  ┌───────────────────────────┐  │
│  │ inventory_sessions        │  │ 盘点单会话
│  │ batches (session_id)      │  │ 批次记录
│  │ products                  │  │ 商品信息
│  └───────────────────────────┘  │
└─────────────────────────────────┘
```

---

## 🔌 API 接口设计

### 1. 更新盘点单商品

**接口**: `index.php?api=update_inventory_item`

**请求方法**: POST

**请求参数**:
```json
{
  "batch_id": 123,           // 必需：批次ID（batches表主键）
  "quantity": 50,            // 可选：新数量
  "expiry_date": "2026-12-31" // 可选：新到期日期
}
```

**响应**:
```json
{
  "success": true,
  "message": "更新成功"
}
```

**SQL 逻辑**:
```sql
UPDATE batches 
SET quantity = ?, 
    expiry_date = ?,
    updated_at = NOW()
WHERE id = ?
```

---

### 2. 删除盘点单商品

**接口**: `index.php?api=delete_inventory_item`

**请求方法**: POST

**请求参数**:
```json
{
  "batch_id": 123  // 必需：批次ID
}
```

**响应**:
```json
{
  "success": true,
  "message": "删除成功"
}
```

**SQL 逻辑**:
```sql
-- 1. 删除批次记录
DELETE FROM batches WHERE id = ?;

-- 2. 更新盘点单的商品计数
UPDATE inventory_sessions 
SET item_count = (
    SELECT COUNT(*) 
    FROM batches 
    WHERE session_id = ?
)
WHERE session_key = ?;
```

---

### 3. 添加商品到盘点单

**接口**: `index.php?api=add_inventory_item`

**请求方法**: POST

**请求参数**:
```json
{
  "session_key": "S1234567890", // 必需：盘点单ID
  "sku": "6901234567890",       // 必需：商品SKU
  "name": "商品名称",           // 必需：商品名称
  "category_id": 1,             // 可选：分类ID
  "removal_buffer": 7,          // 可选：提前下架天数
  "batches": [                  // 必需：批次列表
    {
      "expiry_date": "2026-12-31",
      "quantity": 100
    }
  ]
}
```

**响应**:
```json
{
  "success": true,
  "message": "添加成功",
  "batch_ids": [123, 124]
}
```

**SQL 逻辑**:
```sql
-- 1. 检查或创建商品
INSERT INTO products (sku, name, category_id, removal_buffer)
VALUES (?, ?, ?, ?)
ON DUPLICATE KEY UPDATE 
    name = VALUES(name),
    category_id = VALUES(category_id),
    removal_buffer = VALUES(removal_buffer);

-- 2. 插入批次
INSERT INTO batches (product_id, expiry_date, quantity, session_id)
VALUES (?, ?, ?, ?);

-- 3. 更新盘点单计数
UPDATE inventory_sessions 
SET item_count = item_count + ?
WHERE session_key = ?;
```

---

### 4. 增强的获取详情接口

**接口**: `index.php?api=get_session_details` (修改现有接口)

**修改点**: 返回结果增加 `batch_id` 字段，用于编辑操作

**当前返回**:
```json
{
  "success": true,
  "data": [
    {
      "sku": "6901234567890",
      "name": "商品名称",
      "expiry_date": "2026-12-31",
      "quantity": 100
    }
  ]
}
```

**修改后返回**:
```json
{
  "success": true,
  "data": [
    {
      "batch_id": 123,  // 新增：批次ID，用于编辑
      "product_id": 1,  // 新增：商品ID
      "sku": "6901234567890",
      "name": "商品名称",
      "expiry_date": "2026-12-31",
      "quantity": 100,
      "category_id": 1,
      "removal_buffer": 7
    }
  ]
}
```

**SQL 修改**:
```sql
SELECT b.id as batch_id,  -- 新增
       b.product_id,       -- 新增
       p.sku, 
       p.name, 
       b.expiry_date, 
       b.quantity, 
       p.category_id,      -- 新增
       p.removal_buffer    -- 新增
FROM batches b 
JOIN products p ON b.product_id = p.id 
WHERE b.session_id = ? 
ORDER BY DATE_SUB(b.expiry_date, INTERVAL p.removal_buffer DAY) ASC
```

---

## 🎨 前端界面设计

### 1. 盘点单详情弹窗改造

**当前**: 只读表格
```html
<table>
  <thead>
    <tr>
      <th>商品</th>
      <th>效期</th>
      <th>数</th>
    </tr>
  </thead>
  <tbody>
    <!-- 只读数据 -->
  </tbody>
</table>
```

**改造后**: 可编辑表格 + 操作按钮
```html
<table>
  <thead>
    <tr>
      <th>商品</th>
      <th>效期</th>
      <th>数量</th>
      <th>操作</th>
    </tr>
  </thead>
  <tbody>
    <tr data-batch-id="123">
      <td>商品名称</td>
      <td><input type="date" value="2026-12-31"></td>
      <td><input type="number" value="100"></td>
      <td>
        <button onclick="saveItem(this)">保存</button>
        <button onclick="deleteItem(this)">删除</button>
      </td>
    </tr>
  </tbody>
</table>

<div class="modal-footer">
  <button onclick="showAddItemModal()">+ 添加商品</button>
  <button onclick="sendInventoryEmail()">发送到邮箱</button>
</div>
```

### 2. 添加商品弹窗

复用现有的 `entryModal`，但需要修改提交逻辑：
- 如果是编辑模式，调用 `update_inventory_item`
- 如果是添加模式，调用 `add_inventory_item`

---

## 🔒 数据一致性保证

### 1. 事务处理

所有涉及多表操作的API都应使用事务：

```php
$conn->begin_transaction();

try {
    // 1. 操作batches表
    // 2. 更新inventory_sessions计数
    // 3. 记录操作日志
    
    $conn->commit();
    return ['success' => true];
} catch (Exception $e) {
    $conn->rollback();
    return ['success' => false, 'message' => $e->getMessage()];
}
```

### 2. 并发控制

**问题**: 多人同时编辑同一盘点单
**解决方案**:
1. 添加 `updated_at` 字段到 `inventory_sessions`
2. 编辑前检查时间戳，使用乐观锁
3. 如果数据已被修改，提示用户刷新

```php
// 检查是否被修改
$stmt = $conn->prepare("SELECT updated_at FROM inventory_sessions WHERE session_key = ?");
$stmt->bind_param("s", $session_key);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

if ($result['updated_at'] > $client_timestamp) {
    return ['success' => false, 'message' => '数据已被其他用户修改，请刷新'];
}
```

### 3. 权限控制

- 只允许创建者编辑自己的盘点单
- 管理员可以编辑所有盘点单

```php
// 检查权限
$stmt = $conn->prepare("SELECT user_id FROM inventory_sessions WHERE session_key = ?");
$stmt->bind_param("s", $session_key);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

if ($result['user_id'] != $_SESSION['user_id'] && !isAdmin()) {
    return ['success' => false, 'message' => '没有权限编辑此盘点单'];
}
```

### 4. 数据验证

- 数量必须 ≥ 0
- 到期日期必须 ≥ 今天
- SKU必须存在或可创建

```php
if ($quantity < 0) {
    return ['success' => false, 'message' => '数量不能为负数'];
}

if (strtotime($expiry_date) < strtotime(date('Y-m-d'))) {
    return ['success' => false, 'message' => '到期日期不能早于今天'];
}
```

---

## 📊 数据库修改

### 需要添加的字段

```sql
-- inventory_sessions 表添加更新时间戳
ALTER TABLE inventory_sessions 
ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- 添加索引以提高查询性能
ALTER TABLE batches 
ADD INDEX idx_session_id (session_id);

-- 添加操作日志表（可选）
CREATE TABLE IF NOT EXISTS inventory_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_key VARCHAR(50),
    user_id INT UNSIGNED,
    action ENUM('create', 'update', 'delete', 'add_item'),
    details JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## 🧪 测试计划

### 单元测试
1. `update_inventory_item`: 更新数量、更新日期、同时更新
2. `delete_inventory_item`: 删除商品后计数正确
3. `add_inventory_item`: 新商品创建、已存在商品添加批次

### 集成测试
1. 编辑 → 保存 → 邮件发送验证
2. 删除商品 → 计数更新
3. 并发编辑冲突检测

### 用户测试
1. 界面易用性
2. 错误提示清晰度
3. 操作流程流畅性

---

## 🚀 实施计划

### 阶段 1: 后端 API (TDD Developer)
- [ ] 实现 `update_inventory_item` 接口
- [ ] 实现 `delete_inventory_item` 接口
- [ ] 实现 `add_inventory_item` 接口
- [ ] 修改 `get_session_details` 返回字段
- [ ] 添加数据验证和事务处理

### 阶段 2: 前端界面 (TDD Developer)
- [ ] 修改详情弹窗为可编辑模式
- [ ] 实现保存按钮功能
- [ ] 实现删除按钮功能
- [ ] 实现添加商品功能
- [ ] 添加加载状态和错误提示

### 阶段 3: 测试与审计 (Regression Guard)
- [ ] 功能测试
- [ ] 数据一致性测试
- [ ] 权限测试
- [ ] 并发测试
- [ ] 代码审计

---

## 📝 代码风格规范

### PHP
- 遵循 PSR-12 编码标准
- 使用 prepared statements 防止 SQL 注入
- 所有API返回统一格式：`{success: bool, message: string, data: any}`

### JavaScript
- 使用 async/await 处理异步
- 所有用户操作提供即时反馈（loading、toast）
- 错误处理要有用户友好的提示

### 命名约定
- API endpoint: 动词_noun (如 `update_inventory_item`)
- 数据库字段: snake_case
- JavaScript 变量: camelCase

---

## 📚 参考资料

- 现有代码: `/home/ubuntu/.openclaw/workspace/expiry-clean/`
- 数据库结构: `database.sql`
- API 接口: `index.php` (line 100-360)
- 前端代码: `index.php` (line 700-1200)

---

**文档结束**
