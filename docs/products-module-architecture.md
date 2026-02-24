# 星巴克门店智能效期管理系统 V3.0.3
## 物料管理模块（admin/products.php）架构设计

**文档版本**: 1.0
**创建日期**: 2026-02-24
**作者**: 系统架构师
**状态**: 设计阶段

---

## 📋 目录

1. [模块概述](#模块概述)
2. [需求分析](#需求分析)
3. [页面架构设计](#页面架构设计)
4. [数据库操作设计](#数据库操作设计)
5. [API接口设计](#api接口设计)
6. [页面结构设计](#页面结构设计)
7. [功能流程设计](#功能流程设计)
8. [安全设计](#安全设计)
9. [响应式设计](#响应式设计)
10. [代码规范](#代码规范)

---

## 1. 模块概述

### 1.1 模块定位
物料管理模块是星巴克门店智能效期管理系统的核心功能之一，负责管理所有门店物料的SKU、名称、分类等基础信息。它是盘点系统、数据导入等上游模块的数据基础。

### 1.2 模块职责
- **产品CRUD管理**: 创建、读取、更新、删除产品信息
- **SKU唯一性保证**: 确保SKU作为产品唯一标识符
- **分类关联**: 维护产品与分类的多对一关系
- **数据统计**: 提供产品总数和分类分布统计
- **搜索筛选**: 支持多维度产品搜索
- **数据导出**: 导出产品列表为Excel格式
- **批量操作**: 支持批量删除、批量修改分类等操作

### 1.3 技术栈
- **后端**: Native PHP 8.1+
- **数据库**: MySQL 8.0 (PDO模式)
- **前端**: Bootstrap 5 + jQuery + DataTables
- **依赖**: 
  - `core/Database.php` - 数据库连接
  - `core/AuthService.php` - 鉴权服务
  - `includes/functions.php` - 公用函数

---

## 2. 需求分析

### 2.1 功能需求

| 需求ID | 功能描述 | 优先级 | 复杂度 |
|--------|----------|--------|--------|
| FR-01 | 添加产品（SKU、名称、分类） | P0 | 低 |
| FR-02 | 编辑产品信息 | P0 | 低 |
| FR-03 | 删除单个产品 | P0 | 低 |
| FR-04 | SKU唯一性校验 | P0 | 中 |
| FR-05 | 产品列表展示（分页） | P0 | 中 |
| FR-06 | 按SKU/名称/分类搜索 | P1 | 中 |
| FR-07 | 显示产品总数和分类分布 | P1 | 低 |
| FR-08 | 批量删除产品 | P1 | 中 |
| FR-09 | 批量修改分类 | P2 | 中 |
| FR-10 | 导出产品列表为Excel | P1 | 高 |
| FR-11 | 从Excel导入产品 | P2 | 高 |

### 2.2 非功能需求

| 需求ID | 需求描述 | 指标 |
|--------|----------|------|
| NFR-01 | 响应式设计 | 适配iPad（768px+） |
| NFR-02 | 页面加载时间 | < 2秒 |
| NFR-03 | SQL注入防护 | 使用PDO prepared statements |
| NFR-04 | XSS防护 | 输出时使用escapeHtml() |
| NFR-05 | SKU搜索性能 | < 500ms（10万条数据） |
| NFR-06 | 代码规范 | 遵循PSR-12编码标准 |

### 2.3 约束条件
- 产品必须关联一个分类（category_id可为NULL）
- SKU在系统中必须唯一
- 删除分类时，该分类下的产品category_id设为NULL
- 支持模糊搜索（SKU、产品名称）

---

## 3. 页面架构设计

### 3.1 页面组件层次

```
products.php
├── 页面头部（header.php）
│   ├── 导航栏
│   ├── 左侧边栏
│   └── CSS/JS依赖
├── 主内容区
│   ├── 页面标题区
│   │   ├── 标题: "物料管理"
│   │   └── 返回按钮
│   ├── 提示消息区
│   │   ├── 成功提示
│   │   └── 错误提示
│   ├── 统计卡片区
│   │   ├── 总产品数卡片
│   │   ├── 分类分布卡片
│   │   └── 无分类产品卡片
│   ├── 操作工具栏
│   │   ├── 添加产品按钮
│   │   ├── 搜索输入框
│   │   ├── 分类筛选下拉框
│   │   ├── 批量操作按钮组
│   │   └── 导出按钮
│   ├── 产品数据表格（DataTables）
│   │   ├── 全选复选框
│   │   ├── 列: SKU | 名称 | 分类 | 原始分类 | 创建时间 | 操作
│   │   └── 行内操作: 编辑 | 删除
│   └── 分页控件（DataTables自带）
└── 页面底部（footer.php）
```

### 3.2 模态框设计

#### 3.2.1 添加产品模态框
```
┌─────────────────────────────────────┐
│ 添加产品                      [X]  │
├─────────────────────────────────────┤
│                                     │
│  SKU编码: [___________]             │
│  (唯一标识符，支持模糊搜索)          │
│                                     │
│  产品名称: [___________________]    │
│  (显示名称)                          │
│                                     │
│  所属分类: [下拉选择框       ▼]     │
│  (必选，从categories表读取)         │
│                                     │
│  原始分类: [___________________]    │
│  (可选，Excel导入时保留原始数据)     │
│                                     │
├─────────────────────────────────────┤
│            [取消]      [添加]       │
└─────────────────────────────────────┘
```

#### 3.2.2 编辑产品模态框
```
结构同添加产品模态框，但：
- 标题改为"编辑产品"
- 字段预填充现有数据
- SKU不可编辑（保证唯一性）
- 提交按钮改为"保存"
```

#### 3.2.3 批量操作模态框
```
┌─────────────────────────────────────┐
│ 批量操作                      [X]  │
├─────────────────────────────────────┤
│                                     │
│  已选择 X 个产品                    │
│                                     │
│  操作类型: [○ 修改分类]            │
│           [○ 删除]                  │
│                                     │
│  目标分类: [下拉选择框       ▼]     │
│  (仅在"修改分类"时显示)             │
│                                     │
├─────────────────────────────────────┤
│            [取消]    [确认操作]     │
└─────────────────────────────────────┘
```

### 3.3 状态管理

```php
// 页面状态变量
$state = [
    'success' => '',    // 成功消息
    'error' => '',      // 错误消息
    'products' => [],   // 产品列表
    'categories' => [], // 分类列表
    'stats' => [        // 统计数据
        'total' => 0,
        'by_category' => [],
        'no_category' => 0
    ]
];
```

---

## 4. 数据库操作设计

### 4.1 数据表结构

```sql
-- 物料表（已存在）
CREATE TABLE `products` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `sku` VARCHAR(50) NOT NULL COMMENT 'SKU编码（唯一）',
    `name` VARCHAR(200) NOT NULL COMMENT '物料名称',
    `category_id` INT(11) DEFAULT NULL COMMENT '分类ID',
    `company_category_raw` VARCHAR(200) DEFAULT NULL COMMENT '原始分类（导入时保留）',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `sku` (`sku`),
    KEY `category_id` (`category_id`),
    KEY `name` (`name`),
    CONSTRAINT `fk_products_category` 
        FOREIGN KEY (`category_id`) 
        REFERENCES `categories` (`id`) 
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4.2 查询操作

#### 4.2.1 获取产品列表（带分类信息）
```php
/**
 * 获取产品列表
 * @param int $page 页码
 * @param int $limit 每页数量
 * @param string|null $search 搜索关键词
 * @param int|null $categoryId 分类筛选
 * @return array 产品列表和总数
 */
function getProducts($pdo, $page = 1, $limit = 10, $search = null, $categoryId = null) {
    $offset = ($page - 1) * $limit;
    
    // 基础查询
    $sql = "SELECT p.*, c.name as category_name 
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            WHERE 1=1";
    
    $params = [];
    
    // 搜索条件
    if ($search) {
        $sql .= " AND (p.sku LIKE ? OR p.name LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    // 分类筛选
    if ($categoryId) {
        $sql .= " AND p.category_id = ?";
        $params[] = $categoryId;
    }
    
    // 获取总数
    $countSql = str_replace(
        "SELECT p.*, c.name as category_name",
        "SELECT COUNT(*)",
        $sql
    );
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    // 获取数据
    $sql .= " ORDER BY p.id DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'data' => $products,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit)
    ];
}
```

#### 4.2.2 获取产品统计
```php
/**
 * 获取产品统计信息
 * @return array 统计数据
 */
function getProductStats($pdo) {
    // 总产品数
    $stmt = $pdo->query("SELECT COUNT(*) FROM products");
    $total = $stmt->fetchColumn();
    
    // 按分类统计
    $stmt = $pdo->query("
        SELECT c.name, COUNT(p.id) as count 
        FROM categories c 
        LEFT JOIN products p ON c.id = p.category_id 
        GROUP BY c.id, c.name 
        ORDER BY count DESC
    ");
    $byCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 无分类产品数
    $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE category_id IS NULL");
    $noCategory = $stmt->fetchColumn();
    
    return [
        'total' => $total,
        'by_category' => $byCategory,
        'no_category' => $noCategory
    ];
}
```

#### 4.2.3 检查SKU是否存在
```php
/**
 * 检查SKU是否存在
 * @param string $sku SKU编码
 * @param int|null $excludeId 排除的产品ID（编辑时使用）
 * @return bool 是否存在
 */
function skuExists($pdo, $sku, $excludeId = null) {
    $sql = "SELECT COUNT(*) FROM products WHERE sku = ?";
    $params = [$sku];
    
    if ($excludeId) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() > 0;
}
```

### 4.3 插入操作

```php
/**
 * 添加产品
 * @param string $sku SKU编码
 * @param string $name 产品名称
 * @param int|null $categoryId 分类ID
 * @param string|null $companyCategoryRaw 原始分类
 * @return int 新产品ID
 * @throws Exception SKU已存在时抛出异常
 */
function addProduct($pdo, $sku, $name, $categoryId = null, $companyCategoryRaw = null) {
    // 检查SKU是否存在
    if (skuExists($pdo, $sku)) {
        throw new Exception("SKU '{$sku}' 已存在");
    }
    
    $sql = "INSERT INTO products (sku, name, category_id, company_category_raw, created_at, updated_at) 
            VALUES (?, ?, ?, ?, NOW(), NOW())";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sku, $name, $categoryId, $companyCategoryRaw]);
    
    return $pdo->lastInsertId();
}
```

### 4.4 更新操作

```php
/**
 * 更新产品
 * @param int $id 产品ID
 * @param string $name 产品名称
 * @param int|null $categoryId 分类ID
 * @param string|null $companyCategoryRaw 原始分类
 * @return bool 是否成功
 * @throws Exception 产品不存在时抛出异常
 */
function updateProduct($pdo, $id, $name, $categoryId = null, $companyCategoryRaw = null) {
    // 检查产品是否存在
    $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        throw new Exception("产品不存在");
    }
    
    $sql = "UPDATE products 
            SET name = ?, category_id = ?, company_category_raw = ?, updated_at = NOW() 
            WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$name, $categoryId, $companyCategoryRaw, $id]);
}
```

### 4.5 删除操作

```php
/**
 * 删除单个产品
 * @param int $id 产品ID
 * @return bool 是否成功
 * @throws Exception 产品被引用时抛出异常
 */
function deleteProduct($pdo, $id) {
    // 检查是否被盘点记录引用
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stocktake_items WHERE product_id = ?");
    $stmt->execute([$id]);
    $count = $stmt->fetchColumn();
    
    if ($count > 0) {
        throw new Exception("该产品被 {$count} 条盘点记录引用，无法删除");
    }
    
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    return $stmt->execute([$id]);
}

/**
 * 批量删除产品
 * @param array $ids 产品ID数组
 * @return array 删除结果
 */
function deleteProducts($pdo, $ids) {
    $results = [
        'success' => [],
        'failed' => []
    ];
    
    foreach ($ids as $id) {
        try {
            deleteProduct($pdo, $id);
            $results['success'][] = $id;
        } catch (Exception $e) {
            $results['failed'][] = [
                'id' => $id,
                'error' => $e->getMessage()
            ];
        }
    }
    
    return $results;
}
```

### 4.6 批量操作

```php
/**
 * 批量修改分类
 * @param array $ids 产品ID数组
 * @param int $categoryId 目标分类ID
 * @return int 影响行数
 */
function batchUpdateCategory($pdo, $ids, $categoryId) {
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    $sql = "UPDATE products SET category_id = ?, updated_at = NOW() 
            WHERE id IN ({$placeholders})";
    
    $params = array_merge([$categoryId], $ids);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->rowCount();
}
```

---

## 5. API接口设计

### 5.1 内部接口（与其他模块交互）

#### 5.1.1 获取产品SKU自动完成
```php
/**
 * API: /api/products/suggest
 * 方法: GET
 * 参数: q=搜索关键词
 * 返回: JSON格式的SKU建议列表
 * 
 * 用于盘点系统扫描枪输入时的自动完成
 */
function apiSuggestSkus($pdo) {
    $query = $_GET['q'] ?? '';
    
    if (strlen($query) < 2) {
        return [];
    }
    
    $stmt = $pdo->prepare("
        SELECT sku, name, category_id 
        FROM products 
        WHERE sku LIKE ? OR name LIKE ? 
        ORDER BY sku 
        LIMIT 20
    ");
    $stmt->execute(["%{$query}%", "%{$query}%"]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

#### 5.1.2 根据SKU获取产品信息
```php
/**
 * API: /api/products/get
 * 方法: GET
 * 参数: sku=SKU编码
 * 返回: JSON格式的产品信息
 * 
 * 用于盘点系统快速查找产品
 */
function apiGetProductBySku($pdo) {
    $sku = $_GET['sku'] ?? '';
    
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as category_name, 
               c.early_dispose_days, c.shelf_remove_days, c.check_frequency
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.sku = ?
    ");
    $stmt->execute([$sku]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        http_response_code(404);
        return ['error' => '产品不存在'];
    }
    
    return $product;
}
```

### 5.2 前后端交互接口（Ajax）

#### 5.2.1 实时搜索
```javascript
/**
 * 前端调用示例: 产品搜索
 */
function searchProducts(keyword, categoryId) {
    return $.ajax({
        url: 'admin/products.php',
        method: 'GET',
        data: {
            ajax: 'search',
            keyword: keyword,
            category_id: categoryId
        },
        dataType: 'json'
    });
}

// 后端处理
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    $keyword = $_GET['keyword'] ?? '';
    $categoryId = $_GET['category_id'] ?? null;
    
    $result = getProducts($pdo, 1, 50, $keyword, $categoryId);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $result['data']
    ]);
    exit;
}
```

#### 5.2.2 SKU唯一性检查
```javascript
/**
 * 前端调用示例: SKU唯一性检查
 */
function checkSkuExists(sku, excludeId = null) {
    return $.ajax({
        url: 'admin/products.php',
        method: 'POST',
        data: {
            ajax: 'check_sku',
            sku: sku,
            exclude_id: excludeId
        },
        dataType: 'json'
    });
}

// 后端处理
if (isset($_POST['ajax']) && $_POST['ajax'] === 'check_sku') {
    $sku = $_POST['sku'] ?? '';
    $excludeId = $_POST['exclude_id'] ?? null;
    
    $exists = skuExists($pdo, $sku, $excludeId);
    
    header('Content-Type: application/json');
    echo json_encode([
        'exists' => $exists
    ]);
    exit;
}
```

---

## 6. 页面结构设计

### 6.1 HTML结构

```html
<!-- 页面标题区 -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="page-title">
        <i class="fas fa-box"></i> 物料管理
    </h1>
    <div class="breadcrumb">
        <a href="../dashboard.php">首页</a> / 
        <span>物料管理</span>
    </div>
</div>

<!-- 提示消息区 -->
<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- 统计卡片区 -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h6><i class="fas fa-boxes"></i> 总产品数</h6>
                <h2><?php echo number_format($stats['total']); ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h6><i class="fas fa-tags"></i> 已分类产品</h6>
                <h2><?php echo number_format($stats['total'] - $stats['no_category']); ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-warning text-dark">
            <div class="card-body">
                <h6><i class="fas fa-exclamation-triangle"></i> 未分类产品</h6>
                <h2><?php echo number_format($stats['no_category']); ?></h2>
            </div>
        </div>
    </div>
</div>

<!-- 分类分布卡片 -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> 分类分布</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <?php foreach ($stats['by_category'] as $cat): ?>
            <div class="col-md-3 mb-3">
                <div class="p-3 border rounded">
                    <h6><?php echo escapeHtml($cat['name']); ?></h6>
                    <div class="progress">
                        <div class="progress-bar" 
                             style="width: <?php echo ($cat['count'] / $stats['total']) * 100; ?>%">
                            <?php echo $cat['count']; ?> 个
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- 操作工具栏 -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="input-group">
                    <input type="text" class="form-control" id="searchInput" 
                           placeholder="搜索SKU或产品名称...">
                    <button class="btn btn-outline-secondary" type="button" id="searchBtn">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            <div class="col-md-3">
                <select class="form-select" id="categoryFilter">
                    <option value="">全部分类</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>">
                        <?php echo escapeHtml($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 text-end">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" 
                        data-bs-target="#addProductModal">
                    <i class="fas fa-plus"></i> 添加产品
                </button>
                <button type="button" class="btn btn-success" id="exportBtn">
                    <i class="fas fa-file-excel"></i> 导出
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 产品数据表格 -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="productsTable">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>SKU</th>
                        <th>产品名称</th>
                        <th>分类</th>
                        <th>原始分类</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products['data'] as $product): ?>
                    <tr>
                        <td><input type="checkbox" class="row-checkbox" value="<?php echo $product['id']; ?>"></td>
                        <td><code><?php echo escapeHtml($product['sku']); ?></code></td>
                        <td><?php echo escapeHtml($product['name']); ?></td>
                        <td>
                            <?php if ($product['category_id']): ?>
                            <span class="badge bg-info">
                                <?php echo escapeHtml($product['category_name']); ?>
                            </span>
                            <?php else: ?>
                            <span class="badge bg-secondary">未分类</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($product['company_category_raw']): ?>
                            <small class="text-muted">
                                <?php echo escapeHtml($product['company_category_raw']); ?>
                            </small>
                            <?php else: ?>
                            <small class="text-muted">-</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo formatDate($product['created_at'], 'Y-m-d'); ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-warning edit-btn"
                                    data-id="<?php echo $product['id']; ?>"
                                    data-sku="<?php echo escapeHtml($product['sku']); ?>"
                                    data-name="<?php echo escapeHtml($product['name']); ?>"
                                    data-category="<?php echo $product['category_id']; ?>"
                                    data-raw-category="<?php echo escapeHtml($product['company_category_raw'] ?? ''); ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-danger delete-btn"
                                    data-id="<?php echo $product['id']; ?>"
                                    data-name="<?php echo escapeHtml($product['name']); ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 分页 -->
        <div class="d-flex justify-content-between align-items-center mt-3">
            <div>
                显示 <?php echo ($products['page'] - 1) * $products['limit'] + 1; ?> 
                到 <?php echo min($products['page'] * $products['limit'], $products['total']); ?> 
                / 共 <?php echo number_format($products['total']); ?> 条
            </div>
            <?php echo generatePagination($products['page'], $products['pages']); ?>
        </div>
    </div>
</div>
```

### 6.2 模态框结构

```html
<!-- 添加产品模态框 -->
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle"></i> 添加产品
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addProductForm" method="post">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="sku" class="form-label">
                                SKU编码 <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="sku" name="sku" 
                                   placeholder="例如: SB-001" required
                                   pattern="[A-Z0-9\-]+"
                                   title="SKU只能包含大写字母、数字和横线">
                            <div class="form-text">
                                唯一标识符，支持扫码枪扫描
                            </div>
                            <div class="invalid-feedback" id="skuFeedback"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label">
                                产品名称 <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="name" name="name" 
                                   placeholder="例如: 拿铁咖啡豆 1kg" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="category_id" class="form-label">
                                所属分类 <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="category_id" name="category_id" required>
                                <option value="">请选择分类</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>">
                                    <?php echo escapeHtml($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="company_category_raw" class="form-label">
                                原始分类（可选）
                            </label>
                            <input type="text" class="form-control" id="company_category_raw" 
                                   name="company_category_raw" 
                                   placeholder="导入时的原始分类">
                            <div class="form-text">
                                保留Excel导入时的原始分类信息
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> 添加
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 编辑产品模态框 -->
<div class="modal fade" id="editProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit"></i> 编辑产品
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editProductForm" method="post">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_sku_display" class="form-label">SKU编码</label>
                            <input type="text" class="form-control" id="edit_sku_display" 
                                   disabled>
                            <div class="form-text text-info">
                                <i class="fas fa-info-circle"></i> SKU不可修改
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_name" class="form-label">
                                产品名称 <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="edit_name" 
                                   name="name" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_category_id" class="form-label">
                                所属分类 <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="edit_category_id" 
                                    name="category_id" required>
                                <option value="">请选择分类</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>">
                                    <?php echo escapeHtml($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_company_category_raw" class="form-label">
                                原始分类（可选）
                            </label>
                            <input type="text" class="form-control" 
                                   id="edit_company_category_raw" 
                                   name="company_category_raw">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> 保存
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
```

---

## 7. 功能流程设计

### 7.1 添加产品流程

```
用户操作流程:
┌─────────────────────────────────────────────────────────┐
│ 1. 点击"添加产品"按钮                                    │
│    → 打开添加产品模态框                                  │
├─────────────────────────────────────────────────────────┤
│ 2. 填写产品信息                                         │
│    ├─ 输入SKU（自动格式化为大写）                        │
│    ├─ 实时检查SKU唯一性                                  │
│    ├─ 输入产品名称                                       │
│    ├─ 选择所属分类                                       │
│    └─ (可选) 输入原始分类                                │
├─────────────────────────────────────────────────────────┤
│ 3. 前端验证                                             │
│    ├─ SKU不能为空                                       │
│    ├─ 产品名称不能为空                                   │
│    ├─ 必须选择分类                                       │
│    └─ SKU格式验证（大写字母、数字、横线）                │
├─────────────────────────────────────────────────────────┤
│ 4. 提交表单                                             │
│    → POST /admin/products.php (action=add)             │
├─────────────────────────────────────────────────────────┤
│ 5. 后端处理                                             │
│    ├─ 再次验证SKU唯一性（防止并发）                      │
│    ├─ 插入数据库                                         │
│    └─ 返回成功/失败消息                                  │
├─────────────────────────────────────────────────────────┤
│ 6. 页面刷新                                             │
│    ├─ 显示成功消息                                       │
│    ├─ 重新加载产品列表                                   │
│    └─ 更新统计数据                                       │
└─────────────────────────────────────────────────────────┘
```

### 7.2 编辑产品流程

```
用户操作流程:
┌─────────────────────────────────────────────────────────┐
│ 1. 点击产品的"编辑"按钮                                  │
│    → 打开编辑产品模态框                                  │
├─────────────────────────────────────────────────────────┤
│ 2. 预填充数据                                           │
│    ├─ SKU显示为只读                                      │
│    ├─ 产品名称                                           │
│    ├─ 当前分类                                           │
│    └─ 原始分类                                           │
├─────────────────────────────────────────────────────────┤
│ 3. 用户修改                                             │
│    ├─ 修改产品名称                                       │
│    ├─ 修改分类                                           │
│    └─ 修改原始分类                                       │
├─────────────────────────────────────────────────────────┤
│ 4. 提交表单                                             │
│    → POST /admin/products.php (action=update)          │
├─────────────────────────────────────────────────────────┤
│ 5. 后端处理                                             │
│    ├─ 验证产品ID存在性                                    │
│    ├─ 更新数据库                                         │
│    └─ 返回成功/失败消息                                  │
├─────────────────────────────────────────────────────────┤
│ 6. 页面刷新                                             │
│    └─ 同添加产品流程                                     │
└─────────────────────────────────────────────────────────┘
```

### 7.3 删除产品流程

```
用户操作流程:
┌─────────────────────────────────────────────────────────┐
│ 1. 点击产品的"删除"按钮                                  │
├─────────────────────────────────────────────────────────┤
│ 2. 弹出确认对话框                                       │
│    "确定要删除产品 [XXX] 吗？"                           │
├─────────────────────────────────────────────────────────┤
│ 3. 用户确认                                             │
│    → POST /admin/products.php (action=delete)          │
├─────────────────────────────────────────────────────────┤
│ 4. 后端处理                                             │
│    ├─ 检查是否被盘点记录引用                              │
│    │  └─ 是: 返回错误消息                                │
│    │  └─ 否: 继续删除                                    │
│    ├─ 执行删除操作                                       │
│    └─ 返回成功/失败消息                                  │
├─────────────────────────────────────────────────────────┤
│ 5. 页面刷新                                             │
│    └─ 同添加产品流程                                     │
└─────────────────────────────────────────────────────────┘
```

### 7.4 搜索筛选流程

```
用户操作流程:
┌─────────────────────────────────────────────────────────┐
│ 1. 输入搜索关键词或选择分类                              │
├─────────────────────────────────────────────────────────┤
│ 2. 触发搜索（自动防抖500ms）                              │
│    → GET /admin/products.php?keyword=xxx&category_id=x  │
├─────────────────────────────────────────────────────────┤
│ 3. 后端处理                                             │
│    ├─ 构建SQL查询（支持模糊搜索）                         │
│    ├─ 分页处理                                           │
│    └─ 返回产品列表                                       │
├─────────────────────────────────────────────────────────┤
│ 4. 前端渲染                                             │
│    ├─ 更新表格数据                                       │
│    ├─ 高亮搜索关键词                                      │
│    └─ 更新分页控件                                       │
└─────────────────────────────────────────────────────────┘
```

### 7.5 批量操作流程

```
批量删除流程:
┌─────────────────────────────────────────────────────────┐
│ 1. 勾选多个产品复选框                                    │
├─────────────────────────────────────────────────────────┤
│ 2. 点击"批量操作"下拉菜单 → "删除"                       │
├─────────────────────────────────────────────────────────┤
│ 3. 弹出确认对话框                                       │
│    "确定要删除选中的 X 个产品吗？"                        │
├─────────────────────────────────────────────────────────┤
│ 4. 用户确认                                             │
│    → POST /admin/products.php (action=batch_delete)    │
├─────────────────────────────────────────────────────────┤
│ 5. 后端处理                                             │
│    ├─ 遍历产品ID列表                                      │
│    ├─ 逐个检查引用关系                                    │
│    ├─ 执行批量删除                                        │
│    └─ 返回操作结果（成功/失败数量）                       │
├─────────────────────────────────────────────────────────┤
│ 6. 显示结果                                             │
│    "成功删除 X 个产品，Y 个产品删除失败"                  │
└─────────────────────────────────────────────────────────┘

批量修改分类流程:
┌─────────────────────────────────────────────────────────┐
│ 1. 勾选多个产品复选框                                    │
├─────────────────────────────────────────────────────────┤
│ 2. 点击"批量操作"下拉菜单 → "修改分类"                    │
├─────────────────────────────────────────────────────────┤
│ 3. 打开批量修改模态框                                    │
│    ├─ 显示已选产品数量                                    │
│    └─ 选择目标分类                                        │
├─────────────────────────────────────────────────────────┤
│ 4. 提交修改                                             │
│    → POST /admin/products.php (action=batch_update)    │
├─────────────────────────────────────────────────────────┤
│ 5. 后端处理                                             │
│    ├─ 批量更新category_id                                 │
│    └─ 返回影响行数                                       │
├─────────────────────────────────────────────────────────┤
│ 6. 显示结果                                             │
│    "成功修改 X 个产品的分类"                              │
└─────────────────────────────────────────────────────────┘
```

### 7.6 导出流程

```
导出Excel流程:
┌─────────────────────────────────────────────────────────┐
│ 1. 点击"导出"按钮                                        │
├─────────────────────────────────────────────────────────┤
│ 2. 弹出导出选项对话框                                    │
│    ├─ 选择导出格式（Excel/CSV）                          │
│    ├─ 是否包含原始分类                                    │
│    └─ 是否应用当前筛选条件                                │
├─────────────────────────────────────────────────────────┤
│ 3. 确认导出                                             │
│    → POST /admin/products.php (action=export)          │
├─────────────────────────────────────────────────────────┤
│ 4. 后端处理                                             │
│    ├─ 根据筛选条件获取数据                                │
│    ├─ 使用PhpSpreadsheet生成Excel                        │
│    ├─ 设置HTTP响应头                                      │
│    └─ 输出文件流                                         │
├─────────────────────────────────────────────────────────┤
│ 5. 浏览器下载                                           │
│    "products_export_YYYYMMDD_HHIISS.xlsx"               │
└─────────────────────────────────────────────────────────┘
```

---

## 8. 安全设计

### 8.1 SQL注入防护

```php
// ❌ 错误示例: 直接拼接SQL
$sql = "SELECT * FROM products WHERE sku = '{$_POST['sku']}'";

// ✅ 正确示例: 使用PDO prepared statements
$stmt = $pdo->prepare("SELECT * FROM products WHERE sku = ?");
$stmt->execute([$_POST['sku']]);
```

### 8.2 XSS防护

```php
// ❌ 错误示例: 直接输出
echo $product['name'];

// ✅ 正确示例: 使用escapeHtml()
echo escapeHtml($product['name']);
```

### 8.3 CSRF防护

```php
// 在表单中添加CSRF token
$_SESSION['csrf_token'] = generateRandomString(32);

// 在表单中
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

// 验证
if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    throw new Exception('CSRF token验证失败');
}
```

### 8.4 权限验证

```php
// 检查用户登录状态
if (!$authService->isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

// 检查权限（如果需要）
// if (!$authService->hasPermission('product.manage')) {
//     throw new Exception('权限不足');
// }
```

### 8.5 输入验证

```php
// SKU格式验证
function validateSku($sku) {
    // SKU必须是大写字母、数字、横线组成
    if (!preg_match('/^[A-Z0-9\-]+$/', $sku)) {
        throw new Exception('SKU格式不正确');
    }
    
    // SKU长度限制
    if (strlen($sku) > 50 || strlen($sku) < 2) {
        throw new Exception('SKU长度必须在2-50字符之间');
    }
    
    return true;
}

// 产品名称验证
function validateProductName($name) {
    // 去除首尾空格
    $name = trim($name);
    
    // 长度限制
    if (strlen($name) > 200 || strlen($name) < 1) {
        throw new Exception('产品名称长度必须在1-200字符之间');
    }
    
    return $name;
}
```

---

## 9. 响应式设计

### 9.1 断点设计

```css
/* 移动设备（< 576px）*/
@media (max-width: 575.98px) {
    .card-body {
        padding: 1rem;
    }
    
    .table {
        font-size: 0.875rem;
    }
    
    .btn-sm {
        padding: 0.25rem 0.5rem;
    }
}

/* 平板设备（≥ 768px）- iPad目标设备 */
@media (min-width: 768px) {
    .container-fluid {
        max-width: 100%;
    }
    
    .table {
        font-size: 0.925rem;
    }
}

/* 桌面设备（≥ 1024px）*/
@media (min-width: 1024px) {
    .main-content {
        padding: 30px;
    }
}
```

### 9.2 表格响应式处理

```html
<!-- 移动端表格转为卡片视图 -->
<div class="table-responsive">
    <table class="table table-hover">
        <!-- 桌面端表格 -->
    </table>
</div>

<!-- 移动端卡片视图（可选） -->
<div class="d-md-none">
    <div class="row">
        <?php foreach ($products['data'] as $product): ?>
        <div class="col-12 mb-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><?php echo escapeHtml($product['name']); ?></h5>
                    <p class="card-text">
                        <strong>SKU:</strong> <?php echo escapeHtml($product['sku']); ?><br>
                        <strong>分类:</strong> <?php echo escapeHtml($product['category_name'] ?? '未分类'); ?>
                    </p>
                    <div class="btn-group">
                        <button class="btn btn-sm btn-warning">编辑</button>
                        <button class="btn btn-sm btn-danger">删除</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
```

### 9.3 iPad优化

```css
/* iPad横屏优化 */
@media (min-width: 1024px) and (max-width: 1366px) and (orientation: landscape) {
    .sidebar {
        width: 200px;
    }
    
    .main-content {
        padding: 15px;
    }
    
    .table {
        font-size: 0.875rem;
    }
    
    .btn {
        padding: 0.5rem 1rem;
    }
}

/* iPad竖屏优化 */
@media (min-width: 768px) and (max-width: 1023px) and (orientation: portrait) {
    .sidebar {
        width: 180px;
    }
    
    .col-md-6 {
        width: 100%;
    }
}
```

---

## 10. 代码规范

### 10.1 PHP编码标准（PSR-12）

```php
<?php
/**
 * 产品管理服务类
 * 
 * @author 系统架构师
 * @version 1.0.0
 */

declare(strict_types=1);

namespace ExpiryClean\Services;

use PDO;
use PDOException;

class ProductService
{
    private PDO $pdo;
    
    /**
     * 构造函数
     * 
     * @param PDO $pdo 数据库连接
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * 获取产品列表
     * 
     * @param int $page 页码
     * @param int $limit 每页数量
     * @param string|null $search 搜索关键词
     * @param int|null $categoryId 分类ID
     * @return array 产品列表和分页信息
     */
    public function getProducts(
        int $page = 1,
        int $limit = 10,
        ?string $search = null,
        ?int $categoryId = null
    ): array {
        // 实现代码
    }
}
```

### 10.2 JavaScript代码规范

```javascript
/**
 * 产品管理页面脚本
 */
(function($) {
    'use strict';
    
    // 页面加载完成后初始化
    $(document).ready(function() {
        initDataTable();
        initSearch();
        initModals();
    });
    
    /**
     * 初始化DataTable
     */
    function initDataTable() {
        $('#productsTable').DataTable({
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/zh.json'
            },
            columnDefs: [
                { orderable: false, targets: [0, 6] }
            ]
        });
    }
    
    /**
     * 初始化搜索功能
     */
    function initSearch() {
        let searchTimeout = null;
        
        $('#searchInput').on('input', function() {
            const keyword = $(this).val();
            
            // 防抖处理
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                performSearch(keyword);
            }, 500);
        });
    }
    
    /**
     * 执行搜索
     * @param {string} keyword 搜索关键词
     */
    function performSearch(keyword) {
        // 搜索逻辑
    }
    
    /**
     * 初始化模态框
     */
    function initModals() {
        // 添加产品模态框
        $('#addProductModal').on('show.bs.modal', function() {
            // 重置表单
            $('#addProductForm')[0].reset();
            
            // 清除之前的验证状态
            $('.form-control').removeClass('is-invalid');
        });
        
        // SKU实时验证
        $('#sku').on('blur', function() {
            const sku = $(this).val().toUpperCase();
            $(this).val(sku);
            
            if (sku) {
                checkSkuExists(sku);
            }
        });
        
        // 编辑按钮
        $('.edit-btn').on('click', function() {
            const data = $(this).data();
            
            $('#edit_id').val(data.id);
            $('#edit_sku_display').val(data.sku);
            $('#edit_name').val(data.name);
            $('#edit_category_id').val(data.category);
            $('#edit_company_category_raw').val(data.rawCategory);
            
            $('#editProductModal').modal('show');
        });
        
        // 删除按钮
        $('.delete-btn').on('click', function() {
            const id = $(this).data('id');
            const name = $(this).data('name');
            
            confirmAction(
                `确定要删除产品 "${name}" 吗？`,
                function() {
                    deleteProduct(id);
                }
            );
        });
    }
    
    /**
     * 检查SKU是否存在
     * @param {string} sku SKU编码
     */
    function checkSkuExists(sku) {
        $.ajax({
            url: 'admin/products.php',
            method: 'POST',
            data: {
                ajax: 'check_sku',
                sku: sku
            },
            dataType: 'json',
            success: function(response) {
                if (response.exists) {
                    $('#sku').addClass('is-invalid');
                    $('#skuFeedback').text('SKU已存在');
                } else {
                    $('#sku').removeClass('is-invalid');
                }
            },
            error: function() {
                console.error('检查SKU失败');
            }
        });
    }
    
    /**
     * 删除产品
     * @param {number} id 产品ID
     */
    function deleteProduct(id) {
        $.ajax({
            url: 'admin/products.php',
            method: 'POST',
            data: {
                action: 'delete',
                id: id
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showAlert('success', response.message);
                    location.reload();
                } else {
                    showAlert('danger', response.message);
                }
            },
            error: function() {
                showAlert('danger', '删除失败，请重试');
            }
        });
    }
    
    /**
     * 显示提示消息
     * @param {string} type 消息类型
     * @param {string} message 消息内容
     */
    function showAlert(type, message) {
        const alertHtml = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        
        $('#alertContainer').html(alertHtml);
    }
    
    /**
     * 确认操作
     * @param {string} message 确认消息
     * @param {Function} callback 确认后执行的回调
     */
    function confirmAction(message, callback) {
        if (confirm(message)) {
            callback();
        }
    }
    
    /**
     * 格式化日期
     * @param {string} date 日期字符串
     * @param {string} format 格式
     * @returns {string} 格式化后的日期
     */
    function formatDate(date, format = 'YYYY-MM-DD') {
        // 日期格式化逻辑
    }
    
})(jQuery);
```

### 10.3 SQL规范

```sql
-- 表名: 小写，复数形式
SELECT * FROM products;

-- 列名: 小写，下划线分隔
SELECT product_id, product_name, category_id FROM products;

-- 关键字: 大写
SELECT id, name FROM products WHERE category_id = 1 ORDER BY created_at DESC;

-- 缩进: 4个空格
SELECT 
    p.id,
    p.sku,
    p.name,
    c.name AS category_name
FROM 
    products p
LEFT JOIN 
    categories c ON p.category_id = c.id
WHERE 
    p.category_id IS NOT NULL
ORDER BY 
    p.id DESC;
```

### 10.4 命名规范

```php
// 类名: PascalCase
class ProductService {}

// 方法名: camelCase
public function getProducts() {}

// 变量名: camelCase
$productCount = 0;

// 常量: UPPER_SNAKE_CASE
const MAX_PRODUCTS_PER_PAGE = 100;

// 数据库表名: snake_case, 复数
products, categories, stocktake_sessions

// 数据库列名: snake_case
product_id, category_id, created_at
```

---

## 附录A: 数据字典

### products表

| 字段名 | 类型 | 长度 | 允许NULL | 默认值 | 说明 |
|--------|------|------|----------|--------|------|
| id | INT | 11 | NO | AUTO_INCREMENT | 主键 |
| sku | VARCHAR | 50 | NO | - | SKU编码（唯一） |
| name | VARCHAR | 200 | NO | - | 产品名称 |
| category_id | INT | 11 | YES | NULL | 分类ID（外键） |
| company_category_raw | VARCHAR | 200 | YES | NULL | 原始分类 |
| created_at | TIMESTAMP | - | NO | CURRENT_TIMESTAMP | 创建时间 |
| updated_at | TIMESTAMP | - | NO | CURRENT_TIMESTAMP | 更新时间 |

---

## 附录B: 错误码表

| 错误码 | 说明 | HTTP状态码 |
|--------|------|------------|
| 1001 | SKU不能为空 | 400 |
| 1002 | 产品名称不能为空 | 400 |
| 1003 | 必须选择分类 | 400 |
| 1004 | SKU已存在 | 409 |
| 1005 | 产品不存在 | 404 |
| 1006 | 产品被引用，无法删除 | 409 |
| 1007 | SKU格式不正确 | 400 |
| 1008 | 分类不存在 | 404 |
| 9999 | 系统错误 | 500 |

---

## 附录C: 测试用例

### 功能测试

| 用例ID | 测试场景 | 前置条件 | 操作步骤 | 预期结果 |
|--------|----------|----------|----------|----------|
| TC-01 | 添加产品（正常） | 分类存在 | 1. 输入SKU: TEST-001<br>2. 输入名称: 测试产品<br>3. 选择分类<br>4. 提交 | 添加成功，列表显示新产品 |
| TC-02 | 添加产品（SKU重复） | SKU已存在 | 1. 输入已存在的SKU<br>2. 输入名称<br>3. 选择分类<br>4. 提交 | 提示"SKU已存在" |
| TC-03 | 添加产品（SKU格式错误） | - | 1. 输入SKU: test-001（小写）<br>2. 提交 | 自动转换为大写或提示格式错误 |
| TC-04 | 编辑产品 | 产品存在 | 1. 点击编辑<br>2. 修改名称<br>3. 保存 | 修改成功，列表更新 |
| TC-05 | 删除产品（无引用） | 产品存在 | 1. 点击删除<br>2. 确认 | 删除成功 |
| TC-06 | 删除产品（有引用） | 产品被引用 | 1. 点击删除<br>2. 确认 | 提示"产品被引用，无法删除" |
| TC-07 | 搜索产品（SKU） | 产品存在 | 1. 输入SKU关键词<br>2. 点击搜索 | 显示匹配产品 |
| TC-08 | 搜索产品（名称） | 产品存在 | 1. 输入名称关键词<br>2. 点击搜索 | 显示匹配产品 |
| TC-09 | 分类筛选 | 产品存在 | 1. 选择分类<br>2. 应用筛选 | 显示该分类产品 |
| TC-10 | 批量删除 | 多个产品 | 1. 勾选多个产品<br>2. 批量删除<br>3. 确认 | 显示成功/失败数量 |
| TC-11 | 导出Excel | 产品存在 | 1. 点击导出<br>2. 选择格式<br>3. 确认 | 下载Excel文件 |

### 性能测试

| 用例ID | 测试场景 | 数据量 | 预期性能 |
|--------|----------|--------|----------|
| PT-01 | 列表加载 | 1000条 | < 500ms |
| PT-02 | 搜索（SKU） | 10000条 | < 300ms |
| PT-03 | 搜索（名称） | 10000条 | < 500ms |
| PT-04 | 导出Excel | 10000条 | < 5s |

### 安全测试

| 用例ID | 测试场景 | 操作步骤 | 预期结果 |
|--------|----------|----------|----------|
| ST-01 | SQL注入测试 | SKU输入: `'; DROP TABLE products; --` | 被转义，不执行注入 |
| ST-02 | XSS测试 | 名称输入: `<script>alert(1)</script>` | 被转义，不执行脚本 |
| ST-03 | CSRF测试 | 伪造POST请求 | 验证失败，拒绝请求 |
| ST-04 | 权限测试 | 未登录访问 | 重定向到登录页 |

---

## 附录D: 开发任务清单

### Phase 1: 基础功能（P0）
- [ ] 创建products.php页面
- [ ] 实现产品列表展示
- [ ] 实现添加产品功能
- [ ] 实现编辑产品功能
- [ ] 实现删除产品功能
- [ ] 实现SKU唯一性校验
- [ ] 实现基础搜索功能

### Phase 2: 增强功能（P1）
- [ ] 实现统计卡片
- [ ] 实现分类分布图
- [ ] 实现高级搜索（分类筛选）
- [ ] 实现批量删除
- [ ] 实现导出Excel功能
- [ ] 实现分页优化

### Phase 3: 高级功能（P2）
- [ ] 实现批量修改分类
- [ ] 实现SKU自动完成
- [ ] 实现产品导入功能
- [ ] 实现操作日志记录
- [ ] 实现产品图片上传

### Phase 4: 优化与测试
- [ ] 响应式设计优化
- [ ] 性能优化
- [ ] 安全测试
- [ ] 功能测试
- [ ] 用户验收测试

---

## 总结

本文档详细设计了星巴克门店智能效期管理系统的物料管理模块（admin/products.php）的架构。涵盖了从需求分析、页面设计、数据库操作、API接口、功能流程到安全设计的各个方面。

### 关键设计决策

1. **SKU唯一性**: SKU作为产品的唯一标识符，不允许重复
2. **分类关联**: 产品必须关联分类，但允许为NULL（数据兼容）
3. **原始分类保留**: 保留Excel导入时的原始分类信息，便于追溯
4. **引用检查**: 删除产品时检查是否被盘点记录引用
5. **批量操作**: 支持批量删除和批量修改分类，提高管理效率
6. **搜索优化**: 支持SKU和产品名称的模糊搜索，使用LIKE查询
7. **安全防护**: 使用PDO prepared statements防止SQL注入，使用escapeHtml()防止XSS
8. **响应式设计**: 适配iPad等移动设备，优化触摸操作体验

### 技术亮点

- 使用PDO进行数据库操作，确保安全性
- 使用Bootstrap 5实现响应式设计
- 使用DataTables实现表格排序、搜索、分页
- 使用Ajax实现异步操作，提升用户体验
- 遵循PSR-12编码标准，确保代码质量
- 完善的错误处理和日志记录

---

**文档结束**
