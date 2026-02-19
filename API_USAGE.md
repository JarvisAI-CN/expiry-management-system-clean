# 保质期管理系统 - API 使用说明 (v2.9.0)

> 本文档面向开发者 / AI 助手，介绍如何使用 `api.php` 暴露的 HTTP 接口，以及 v2.9.0 新增的 **Scopes 权限系统** 和 REST 风格端点。

---

## 1. 认证方式

所有接口统一使用 API Key 认证：

```http
Authorization: Bearer YOUR_API_KEY
```

也可以在调试时将 `api_key` 放在 query string 中：

```http
GET /api.php?endpoint=summary&api_key=YOUR_API_KEY
```

生产环境推荐只使用 Header 方式。

---

## 2. Scopes 权限系统

v2.9.0 开始，API Key 具备 **作用域（Scopes）**，用于控制不同密钥能访问的接口范围。

- 数据库存储字段：`api_keys.scopes`（逗号分隔字符串）
- 特殊 Scope：
  - `admin`      → 拥有所有权限
  - `read:all`   → 拥有所有只读接口权限（不含系统升级等敏感操作）

### 2.1 常用 Scopes 一览

| Scope            | 说明                                   | 对应 endpoint                      |
|------------------|----------------------------------------|-------------------------------------|
| `read:summary`   | 查看汇总统计                          | `summary`                           |
| `read:products`  | 查看商品列表                          | `products`                          |
| `read:batches`   | 查看批次明细                          | `batches`                           |
| `read:expiring`  | 查看即将过期数据                      | `expiring`                          |
| `read:categories`| 查看分类数据                          | `categories`                        |
| `read:all`       | 所有只读端点（上面所有 + `all`、库存） | `products/batches/.../items/...`    |
| `read:inventories`| 查看盘点会话列表                     | `inventories`                       |
| `read:items`     | 查看库存 / 盘点明细                   | `items`                             |
| `system:upgrade` | 通过 API 触发 v2.9.0 升级脚本         | `system.upgrade` (POST 模式)        |

> 提示：如果 `scopes` 为空，系统会自动回退为 `read:all`，保持向下兼容。

### 2.2 Scope 判定规则

后端在 `api.php` 中按如下规则校验：

1. 如果包含 `admin` → 直接放行
2. 对于以 `read:` 开头的 scope：
   - 如果 API Key 包含 `read:all` → 放行
   - 否则必须显式包含所需的那个 scope
3. 对于非读操作（如 `system:upgrade`）→ 必须显式包含对应 scope

---

## 3. 现有数据端点 (与 v2.8 保持兼容)

所有请求统一使用：

```http
GET /api.php?endpoint=ENDPOINT_NAME
```

### 3.1 `summary` - 汇总统计

- Scope：`read:summary` 或 `read:all`
- 示例：

```bash
curl -H "Authorization: Bearer YOUR_API_KEY" \
  "https://your-domain/api.php?endpoint=summary"
```

- 响应示例：

```json
{
  "success": true,
  "endpoint": "summary",
  "generated_at": "2026-02-19 10:00:00",
  "statistics": {
    "total_products": 100,
    "total_batches": 250,
    "total_stock": 5000,
    "expired": 5,
    "critical": 10,
    "warning": 25
  },
  "category_stats": [
    { "name": "小食品", "product_count": 60 },
    { "name": "物料", "product_count": 30 }
  ]
}
```

### 3.2 其他旧接口速览

| endpoint    | 说明                 | 可选参数          | Scope            |
|-------------|----------------------|-------------------|------------------|
| `products`  | 商品基础信息        | -                 | `read:products`  |
| `batches`   | 批次明细            | -                 | `read:batches`   |
| `expiring`  | 即将/已过期批次     | `days`=整数，默认30 | `read:expiring`  |
| `categories`| 分类信息            | -                 | `read:categories`|
| `all`       | 完整导出（全部数据）| -                 | `read:all`       |

---

## 4. v2.9.0 新增 REST 风格端点

### 4.1 `inventories` - 盘点会话列表

- Scope：`read:inventories` 或 `read:all`
- 方法：`GET`
- URL：

```http
GET /api.php?endpoint=inventories&limit=50
```

- 参数：
  - `limit` (可选，1-200，默认 50)：返回的最大会话数量

- 响应示例：

```json
{
  "success": true,
  "endpoint": "inventories",
  "count": 2,
  "data": [
    {
      "id": 12,
      "session_key": "SESSION-20260218-01",
      "user_id": 1,
      "username": "admin",
      "item_count": 35,
      "created_at": "2026-02-18 10:20:00"
    },
    {
      "id": 11,
      "session_key": "SESSION-20260217-01",
      "user_id": 2,
      "username": "user",
      "item_count": 20,
      "created_at": "2026-02-17 15:30:00"
    }
  ]
}
```

### 4.2 `items` - 库存 / 盘点明细

- Scope：`read:items` 或 `read:all`
- 方法：`GET`
- 用途：
  1. **查看某次盘点的明细**（带 `session_key`）
  2. **查看当前库存汇总**（不带 `session_key`）

#### 4.2.1 按盘点会话查询

```http
GET /api.php?endpoint=items&session_key=SESSION-20260218-01
```

- 响应示例：

```json
{
  "success": true,
  "endpoint": "items",
  "mode": "session",
  "session_key": "SESSION-20260218-01",
  "count": 3,
  "data": [
    {
      "sku": "6901234567890",
      "name": "可口可乐 500ml",
      "expiry_date": "2026-12-31",
      "quantity": 50,
      "removal_buffer": 7
    },
    {
      "sku": "6901234567891",
      "name": "康师傅红烧牛肉面",
      "expiry_date": "2026-03-15",
      "quantity": 20,
      "removal_buffer": 0
    }
  ]
}
```

> 注意：兼容参数 `session_id`，效果等同于 `session_key`。

#### 4.2.2 按 SKU 聚合当前库存

```http
GET /api.php?endpoint=items
```

- 响应示例：

```json
{
  "success": true,
  "endpoint": "items",
  "mode": "stock",
  "count": 2,
  "data": [
    {
      "id": 1,
      "sku": "6901234567890",
      "name": "可口可乐 500ml",
      "total_quantity": 150,
      "nearest_expiry": "2026-06-30"
    },
    {
      "id": 2,
      "sku": "6901234567891",
      "name": "康师傅红烧牛肉面",
      "total_quantity": 200,
      "nearest_expiry": "2026-03-15"
    }
  ]
}
```

### 4.3 `system.upgrade` - 系统升级接口

该接口主要用于 **自动化执行 v2.9.0 数据库升级脚本**，为 `api_keys` 表添加 `scopes` 字段，并尝试更新 `VERSION.txt`。

- Scope：`system:upgrade`（建议单独创建只用于升级的密钥）

#### 4.3.1 查看升级状态（GET）

```http
GET /api.php?endpoint=system.upgrade
```

- 响应示例：

```json
{
  "success": true,
  "endpoint": "system.upgrade",
  "mode": "status",
  "current_version": "2.8.3.2",
  "target_version": "2.9.0"
}
```

#### 4.3.2 执行升级（POST）

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_API_KEY_WITH_SYSTEM_UPGRADE" \
  "https://your-domain/api.php?endpoint=system.upgrade"
``;

- 响应示例：

```json
{
  "success": true,
  "endpoint": "system.upgrade",
  "mode": "execute",
  "messages": [
    "✓ 成功为 api_keys 表添加 scopes 字段",
    "✓ 已为现有 API 密钥填充默认 scopes = 'read:all'",
    "✓ 已将 VERSION.txt 更新为 2.9.0"
  ]
}
```

> 注意：该接口内部调用 `upgrade_v2.9.0.php` 中的 `run_upgrade_v2_9_0(true)`，因此也可以在浏览器直接访问该 PHP 文件进行人工升级。

---

## 5. 与前端 / AI 助手的典型集成

### 5.1 AI 助手获取“需要处理的项目”

1. 调用 `summary` 获得总体健康情况
2. 调用 `expiring` 获取即将过期明细
3. 调用 `items` (stock 模式) 了解当前库存
4. 调用 `inventories` / `items?session_key=...` 结合最近盘点情况输出建议

### 5.2 安全实践建议

- 为不同用途创建不同 API Key：
  - 只读分析：`read:all`
  - 监控/看板：`read:summary,read:expiring`
  - 自动升级：`system:upgrade`（请短期使用，完成后停用密钥）
- 为密钥设置过期时间 `expires_at`
- 定期检查 `api_logs` 表中的访问记录

---

## 6. 版本说明

- v2.9.0
  - 新增：`inventories`、`items`、`system.upgrade` REST 风格端点
  - 新增：`api_keys.scopes` 字段与 Scope 权限系统
  - 新增：`upgrade_v2.9.0.php` 数据库升级脚本
  - 文档：本文件 `API_USAGE.md`
