# 保质期管理系统 - 未来改进架构方案

**文档版本**: v1.0
**创建日期**: 2026-02-21
**创建者**: The Architect (贾维斯)
**当前系统版本**: v2.15.0

---

## 📋 目录

1. [执行摘要](#执行摘要)
2. [安全性改进](#1-安全性改进)
3. [性能优化](#2-性能优化)
4. [功能增强](#3-功能增强)
5. [架构设计](#4-架构设计)
6. [实施计划](#实施计划)
7. [风险评估](#风险评估)
8. [资源估算](#资源估算)

---

## 执行摘要

### 当前状态
保质期管理系统已发展到v2.15.0，具备基础盘点、预警、管理后台等核心功能。系统采用PHP单体架构，代码集中在`index.php`和`api.php`中。

### 改进目标
将系统从"功能完整"升级为"企业级SaaS"，在安全性、性能、用户体验和可维护性上实现质的飞跃。

### 核心指标
| 指标 | 当前 | 目标 | 提升 |
|------|------|------|------|
| 安全等级 | C+ | A | 2级 |
| 页面加载 | ~2s | <500ms | 4x |
| 并发用户 | ~10 | 100+ | 10x |
| 代码覆盖率 | 0% | 80% | ∞ |

---

## 1. 安全性改进

### 1.1 CSRF Token验证机制 🔐

**优先级**: ⭐⭐⭐⭐⭐ (最高)
**复杂度**: 中等
**工作量**: 4-6小时

#### 问题分析
当前系统完全缺乏CSRF保护，所有POST请求都可以被跨站请求伪造攻击。

#### 解决方案

**实现方式**:
```php
// 1. 生成Token (bootstrap.php)
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_expire'] = time() + 3600; // 1小时过期
    }
    return $_SESSION['csrf_token'];
}

// 2. 验证Token
function validateCsrfToken($token) {
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_expire'])) {
        return false;
    }
    
    if (time() > $_SESSION['csrf_token_expire']) {
        unset($_SESSION['csrf_token']);
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

// 3. API中间件
function csrfGuard() {
    $method = $_SERVER['REQUEST_METHOD'];
    if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] 
              ?? $_POST['csrf_token'] 
              ?? json_decode(file_get_contents('php://input'), true)['csrf_token']
              ?? null;
        
        if (!validateCsrfToken($token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'CSRF token验证失败']);
            exit;
        }
    }
}
```

**前端集成**:
```javascript
// 在所有AJAX请求中自动添加CSRF token
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

fetch('/api/endpoint', {
    method: 'POST',
    headers: {
        'X-CSRF-Token': csrfToken,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify(data)
});
```

#### 验收标准
- [ ] 所有POST/PUT/DELETE请求都需要CSRF token
- [ ] Token有过期机制（1小时）
- [ ] Token一次性使用（可选，降低用户体验）
- [ ] 登录后自动刷新token
- [ ] 前端自动携带token

---

### 1.2 API接口安全性增强 🛡️

**优先级**: ⭐⭐⭐⭐⭐ (最高)
**复杂度**: 中等
**工作量**: 6-8小时

#### 问题分析
当前`api.php`虽然实现了API密钥验证，但缺少：
- Rate Limiting（速率限制）
- IP白名单
- 请求签名验证
- 敏感操作二次确认

#### 解决方案

**1. Rate Limiting实现**:
```php
class RateLimiter {
    private $redis;
    private $maxRequests = 100;  // 每小时最大请求数
    private $window = 3600;      // 时间窗口（秒）
    
    public function check($apiKey) {
        $key = "ratelimit:{$apiKey}";
        $current = $this->redis->get($key);
        
        if ($current === false) {
            $this->redis->setex($key, $this->window, 1);
            return true;
        }
        
        if ($current >= $this->maxRequests) {
            return false;
        }
        
        $this->redis->incr($key);
        return true;
    }
}

// 在api.php中应用
$rateLimiter = new RateLimiter();
if (!$rateLimiter->check($apiKey)) {
    jsonResponse([
        'success' => false,
        'message' => '请求过于频繁，请稍后再试'
    ], 429);
}
```

**2. 请求签名验证**:
```php
// 签名算法: HMAC-SHA256
// signature = HMAC-SHA256(api_secret, timestamp + method + endpoint + params)

function verifySignature($apiKey, $timestamp, $method, $endpoint, $params, $signature) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT api_secret FROM api_keys WHERE api_key_prefix = ?");
    $stmt->bind_param("s", substr($apiKey, 0, 8));
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if (!$result) return false;
    
    $payload = $timestamp . $method . $endpoint . json_encode($params);
    $expected = hash_hmac('sha256', $payload, $result['api_secret']);
    
    // 防重放攻击：时间戳必须在5分钟内
    if (abs(time() - $timestamp) > 300) {
        return false;
    }
    
    return hash_equals($expected, $signature);
}
```

**3. IP白名单**:
```sql
ALTER TABLE api_keys ADD COLUMN ip_whitelist TEXT;
```

```php
function verifyIpWhitelist($keyId, $clientIp) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT ip_whitelist FROM api_keys WHERE id = ?");
    $stmt->bind_param("i", $keyId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if (!$result || empty($result['ip_whitelist'])) {
        return true; // 未设置则不限制
    }
    
    $allowedIps = explode(',', $result['ip_whitelist']);
    return in_array($clientIp, $allowedIps);
}
```

#### 验收标准
- [ ] 每个API密钥有独立的速率限制
- [ ] 敏感操作需要签名验证
- [ ] 支持IP白名单配置
- [ ] 超限返回429状态码
- [ ] 日志记录所有失败的验证尝试

---

### 1.3 其他安全加固

**XSS防护**:
```php
// 所有输出都要转义
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// 设置CSP头
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'");
```

**SQL注入防护**（已部分实现）:
- 确保所有查询使用prepared statements
- 禁止直接拼接SQL

**文件上传安全**:
```php
function validateUpload($file) {
    // 1. 检查文件类型
    $allowedTypes = ['application/vnd.ms-excel', 'text/csv'];
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('只允许上传CSV文件');
    }
    
    // 2. 检查文件大小（最大5MB）
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('文件大小不能超过5MB');
    }
    
    // 3. 重命名文件
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $newName = uniqid('upload_', true) . '.' . $ext;
    
    // 4. 移动到隔离目录
    $targetDir = __DIR__ . '/uploads/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    
    move_uploaded_file($file['tmp_name'], $targetDir . $newName);
    return $targetDir . $newName;
}
```

---

## 2. 性能优化

### 2.1 数据库查询优化 🚀

**优先级**: ⭐⭐⭐⭐ (高)
**复杂度**: 低
**工作量**: 2-3小时

#### 当前问题
- 缺少关键索引
- N+1查询问题
- 大表全表扫描

#### 解决方案

**1. 添加关键索引**:
```sql
-- 批次表索引优化
ALTER TABLE batches ADD INDEX idx_session_expiry (session_id, expiry_date);
ALTER TABLE batches ADD INDEX idx_product_session (product_id, session_id);

-- 商品表索引优化
ALTER TABLE products ADD INDEX idx_category_cycle (category_id, inventory_cycle);
ALTER TABLE products ADD INDEX idx_last_inventory (last_inventory_at);

-- 盘点会话索引
ALTER TABLE inventory_sessions ADD INDEX idx_user_created (user_id, created_at DESC);

-- 复合索引（用于常见查询）
ALTER TABLE batches ADD INDEX idx_expiry_quantity (expiry_date, quantity);
```

**2. 查询优化示例**:

**优化前**（N+1问题）:
```php
// 获取所有盘点单及其商品
$sessions = $conn->query("SELECT * FROM inventory_sessions");
foreach ($sessions as $session) {
    $items = $conn->query("SELECT * FROM batches WHERE session_id = {$session['id']}");
    // N+1查询
}
```

**优化后**（一次查询）:
```php
$sql = "SELECT 
    s.id as session_id,
    s.session_key,
    s.item_count,
    s.created_at,
    b.id as batch_id,
    p.sku,
    p.name,
    b.expiry_date,
    b.quantity
FROM inventory_sessions s
LEFT JOIN batches b ON s.session_key = b.session_id
LEFT JOIN products p ON b.product_id = p.id
WHERE s.user_id = ?
ORDER BY s.created_at DESC, b.expiry_date ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

// 在PHP中组装数据
$sessions = [];
while ($row = $result->fetch_assoc()) {
    $sessionId = $row['session_id'];
    if (!isset($sessions[$sessionId])) {
        $sessions[$sessionId] = [
            'id' => $sessionId,
            'key' => $row['session_key'],
            'count' => $row['item_count'],
            'created' => $row['created_at'],
            'items' => []
        ];
    }
    if ($row['batch_id']) {
        $sessions[$sessionId]['items'][] = [
            'batch_id' => $row['batch_id'],
            'sku' => $row['sku'],
            'name' => $row['name'],
            'expiry_date' => $row['expiry_date'],
            'quantity' => $row['quantity']
        ];
    }
}
```

**3. 分页查询优化**:
```php
// 使用cursor-based pagination（更适合大数据集）
function getInventorySessions($userId, $lastId = null, $limit = 20) {
    $conn = getDBConnection();
    
    if ($lastId) {
        $sql = "SELECT * FROM inventory_sessions 
                WHERE user_id = ? AND id < ? 
                ORDER BY id DESC 
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $userId, $lastId, $limit);
    } else {
        $sql = "SELECT * FROM inventory_sessions 
                WHERE user_id = ? 
                ORDER BY id DESC 
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $userId, $limit);
    }
    
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
```

#### 性能提升预期
| 操作 | 优化前 | 优化后 | 提升 |
|------|--------|--------|------|
| 加载盘点单列表 | ~800ms | ~100ms | 8x |
| 扫描过期商品 | ~1200ms | ~150ms | 8x |
| 大盘点单详情 | ~2000ms | ~200ms | 10x |

---

### 2.2 前端加载速度优化 ⚡

**优先级**: ⭐⭐⭐⭐ (高)
**复杂度**: 低-中
**工作量**: 4-6小时

#### 解决方案

**1. 资源压缩与合并**:
```bash
# 使用构建工具
npm install -g gulp-cli
npm install gulp gulp-concat gulp-uglify gulp-clean-css gulp-htmlmin

# gulpfile.js
const gulp = require('gulp');
const concat = require('gulp-concat');
const uglify = require('gulp-uglify');
const cleanCss = require('gulp-clean-css');

gulp.task('scripts', () => {
    return gulp.src([
        'assets/js/vendor/jquery.min.js',
        'assets/js/vendor/bootstrap.bundle.min.js',
        'assets/js/vendor/html5-qrcode.min.js',
        'assets/js/app.js'
    ])
    .pipe(concat('bundle.min.js'))
    .pipe(uglify())
    .pipe(gulp.dest('dist/js'));
});

gulp.task('styles', () => {
    return gulp.src('assets/css/*.css')
    .pipe(concat('bundle.min.css'))
    .pipe(cleanCss())
    .pipe(gulp.dest('dist/css'));
});
```

**2. 懒加载实现**:
```javascript
// 图片懒加载
const imgObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const img = entry.target;
            img.src = img.dataset.src;
            img.classList.remove('lazy');
            imgObserver.unobserve(img);
        }
    });
});

document.querySelectorAll('img.lazy').forEach(img => {
    imgObserver.observe(img);
});

// 路由懒加载（如果使用SPA架构）
const Dashboard = () => import('./views/Dashboard.js');
const Inventory = () => import('./views/Inventory.js');
```

**3. 代码分割**:
```javascript
// 按需加载扫码库
async function initScanner() {
    if (!('Html5Qrcode' in window)) {
        await loadScript('https://unpkg.com/html5-qrcode');
    }
    const scanner = new Html5Qrcode("reader");
    // ...
}
```

**4. Service Worker缓存**:
```javascript
// sw.js
const CACHE_NAME = 'expiry-v1';
const urlsToCache = [
    '/',
    '/assets/css/bundle.min.css',
    '/assets/js/bundle.min.js',
    '/assets/icons/icon-192x192.png'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(urlsToCache))
    );
});

self.addEventListener('fetch', event => {
    event.respondWith(
        caches.match(event.request)
            .then(response => response || fetch(event.request))
    );
});
```

#### 性能指标目标
| 指标 | 当前 | 目标 | 工具 |
|------|------|------|------|
| First Contentful Paint | ~1.2s | <0.8s | Lighthouse |
| Time to Interactive | ~2.5s | <1.5s | Lighthouse |
| Lighthouse Score | 65 | 90+ | Lighthouse |
| 包大小 | ~850KB | <300KB | Bundle Analyzer |

---

### 2.3 缓存机制实现 💾

**优先级**: ⭐⭐⭐ (中)
**复杂度**: 中等
**工作量**: 6-8小时

#### 架构设计

**三层缓存架构**:
```
┌──────────────────────────────────────┐
│  1. 浏览器缓存 (Cache-Control)       │ ← 最快
│     - 静态资源：1年                   │
│     - API响应：5分钟                  │
└─────────────┬────────────────────────┘
              │ miss
              ▼
┌──────────────────────────────────────┐
│  2. Redis缓存 (应用层)                │ ← 快
│     - 商品信息：1小时                 │
│     - 分类数据：24小时                │
│     - 统计数据：15分钟                │
└─────────────┬────────────────────────┘
              │ miss
              ▼
┌──────────────────────────────────────┐
│  3. 数据库 (MySQL)                    │ ← 慢
│     - 持久化存储                      │
└──────────────────────────────────────┘
```

#### 实现方案

**1. Redis缓存类**:
```php
class Cache {
    private $redis;
    private $prefix = 'expiry:';
    
    public function __construct() {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
    }
    
    public function get($key, $default = null) {
        $value = $this->redis->get($this->prefix . $key);
        if ($value === false) return $default;
        return json_decode($value, true);
    }
    
    public function set($key, $value, $ttl = 3600) {
        return $this->redis->setex(
            $this->prefix . $key,
            $ttl,
            json_encode($value, JSON_UNESCAPED_UNICODE)
        );
    }
    
    public function remember($key, $ttl, $callback) {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }
    
    public function forget($key) {
        return $this->redis->del($this->prefix . $key);
    }
    
    public function flush() {
        $keys = $this->redis->keys($this->prefix . '*');
        return $this->redis->del($keys);
    }
}

// 使用示例
$cache = new Cache();

// 获取商品（自动缓存）
$products = $cache->remember('products:all', 3600, function() {
    $conn = getDBConnection();
    $result = $conn->query("SELECT * FROM products");
    return $result->fetch_all(MYSQLI_ASSOC);
});

// 更新后清除缓存
function updateProduct($id, $data) {
    // ... 更新数据库
    $cache->forget("products:{$id}");
    $cache->forget('products:all');
}
```

**2. 查询结果缓存**:
```php
// 缓存盘点单详情
function getSessionDetails($sessionId) {
    return $cache->remember("session:{$sessionId}", 300, function() use ($sessionId) {
        $conn = getDBConnection();
        $stmt = $conn->prepare("
            SELECT s.*, b.*, p.sku, p.name 
            FROM inventory_sessions s
            LEFT JOIN batches b ON s.session_key = b.session_id
            LEFT JOIN products p ON b.product_id = p.id
            WHERE s.session_key = ?
        ");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    });
}
```

**3. HTTP缓存头**:
```php
// 设置API响应缓存
function setCacheHeaders($maxAge = 300) {
    header('Cache-Control: public, max-age=' . $maxAge);
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
}

// 在GET端点使用
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    setCacheHeaders(300); // 5分钟
}
```

#### 缓存策略
| 数据类型 | TTL | 失效策略 |
|---------|-----|----------|
| 商品基础信息 | 1小时 | 更新时清除 |
| 分类数据 | 24小时 | 更新时清除 |
| 统计数据 | 15分钟 | 定时刷新 |
| 盘点单详情 | 5分钟 | 更新时清除 |
| 静态资源 | 1年 | 版本号更新 |

---

## 3. 功能增强

### 3.1 导出功能（Excel/PDF） 📊

**优先级**: ⭐⭐⭐⭐ (高)
**复杂度**: 中等
**工作量**: 8-10小时

#### 功能需求
- 导出盘点单为Excel格式
- 导出预警报告为PDF
- 支持自定义导出字段
- 批量导出多个盘点单

#### 技术方案

**方案A: 使用PhpSpreadsheet（推荐）**:
```bash
composer require phpoffice/phpspreadsheet
```

```php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

function exportInventoryToExcel($sessionId) {
    $cache = new Cache();
    $data = $cache->remember("session:{$sessionId}", 60, function() use ($sessionId) {
        // 获取数据
        $conn = getDBConnection();
        $stmt = $conn->prepare("
            SELECT p.sku, p.name, b.expiry_date, b.quantity, c.name as category
            FROM inventory_sessions s
            JOIN batches b ON s.session_key = b.session_id
            JOIN products p ON b.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE s.session_key = ?
            ORDER BY b.expiry_date ASC
        ");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    });
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // 设置标题
    $sheet->setCellValue('A1', '盘点单导出');
    $sheet->mergeCells('A1:E1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // 设置表头
    $headers = ['SKU', '商品名称', '分类', '到期日期', '数量'];
    $sheet->fromArray($headers, null, 'A3');
    $sheet->getStyle('A3:E3')->getFont()->setBold(true);
    $sheet->getStyle('A3:E3')->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB('E0E0E0');
    
    // 填充数据
    $row = 4;
    foreach ($data as $item) {
        $sheet->fromArray([
            $item['sku'],
            $item['name'],
            $item['category'],
            $item['expiry_date'],
            $item['quantity']
        ], null, "A{$row}");
        
        // 根据到期日期设置颜色
        $expiry = new DateTime($item['expiry_date']);
        $today = new DateTime();
        $days = $today->diff($expiry)->format('%r%a');
        
        if ($days < 0) {
            $sheet->getStyle("A{$row}:E{$row}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FFCDD2'); // 红色
        } elseif ($days <= 7) {
            $sheet->getStyle("A{$row}:E{$row}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FFF9C4'); // 黄色
        }
        
        $row++;
    }
    
    // 自动调整列宽
    foreach (range('A', 'E') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // 生成文件
    $filename = "inventory_{$sessionId}_" . date('YmdHis') . ".xlsx";
    $filepath = '/tmp/' . $filename;
    
    $writer = new Xlsx($spreadsheet);
    $writer->save($filepath);
    
    return $filepath;
}
```

**方案B: 使用TCPDF生成PDF**:
```bash
composer require tecnickcom/tcpdf
```

```php
require_once('vendor/tcpdf/tcpdf.php');

function exportWarningReportToPDF($days = 30) {
    $conn = getDBConnection();
    
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('保质期管理系统');
    $pdf->SetTitle('过期预警报告');
    
    // 添加页面
    $pdf->AddPage();
    
    // 设置标题
    $pdf->SetFont('stsongstdlight', 'B', 20);
    $pdf->Cell(0, 10, '过期预警报告', 0, 1, 'C');
    $pdf->SetFont('stsongstdlight', '', 10);
    $pdf->Cell(0, 5, '生成时间: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
    $pdf->Ln(10);
    
    // 获取数据
    $stmt = $conn->prepare("
        SELECT p.sku, p.name, b.expiry_date, b.quantity, c.name as category,
               DATEDIFF(b.expiry_date, CURDATE()) as days_remaining
        FROM batches b
        JOIN products p ON b.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ORDER BY b.expiry_date ASC
    ");
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // 表格数据
    $header = ['SKU', '商品名称', '分类', '到期日期', '剩余天数', '数量'];
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            $row['sku'],
            $row['name'],
            $row['category'],
            $row['expiry_date'],
            $row['days_remaining'],
            $row['quantity']
        ];
    }
    
    // 输出表格
    $pdf->writeHTMLTable($header, $data);
    
    // 输出文件
    $filename = "warning_report_" . date('YmdHis') . ".pdf";
    $filepath = '/tmp/' . $filename;
    $pdf->Output($filepath, 'F');
    
    return $filepath;
}
```

**API端点**:
```php
// 在api.php中添加
if ($action === 'export_excel') {
    $sessionId = $_GET['session_id'] ?? null;
    if (!$sessionId) {
        echo json_encode(['success' => false, 'message' => '缺少session_id']);
        exit;
    }
    
    try {
        $filepath = exportInventoryToExcel($sessionId);
        $filename = basename($filepath);
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        unlink($filepath); // 删除临时文件
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

if ($action === 'export_pdf') {
    $days = intval($_GET['days'] ?? 30);
    try {
        $filepath = exportWarningReportToPDF($days);
        $filename = basename($filepath);
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        unlink($filepath);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}
```

#### 验收标准
- [ ] Excel导出包含所有商品信息
- [ ] 根据到期日期自动着色
- [ ] PDF报告格式正确
- [ ] 支持选择导出字段
- [ ] 文件命名规范（含时间戳）

---

### 3.2 批量导入SKU 📥

**优先级**: ⭐⭐⭐ (中)
**复杂度**: 低-中
**工作量**: 4-6小时

#### 功能需求
- 支持CSV/Excel批量导入
- 字段映射配置
- 错误数据提示
- 导入进度显示

#### 技术方案

**1. CSV解析器**:
```php
class CsvImporter {
    private $filepath;
    private $requiredFields = ['sku', 'name'];
    private $optionalFields = ['category', 'removal_buffer', 'inventory_cycle'];
    
    public function __construct($filepath) {
        $this->filepath = $filepath;
    }
    
    public function validate() {
        if (!file_exists($this->filepath)) {
            throw new Exception('文件不存在');
        }
        
        $handle = fopen($this->filepath, 'r');
        $headers = fgetcsv($handle);
        fclose($handle);
        
        $missing = array_diff($this->requiredFields, $headers);
        if (!empty($missing)) {
            throw new Exception('缺少必需字段: ' . implode(', ', $missing));
        }
        
        return true;
    }
    
    public function import($userId) {
        $handle = fopen($this->filepath, 'r');
        $headers = fgetcsv($handle); // 跳过表头
        
        $conn = getDBConnection();
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        
        $conn->begin_transaction();
        
        try {
            $rowNumber = 2; // 从第2行开始（第1行是表头）
            while (($row = fgetcsv($handle)) !== false) {
                $data = array_combine($headers, $row);
                
                try {
                    $this->importRow($conn, $data, $userId);
                    $successCount++;
                } catch (Exception $e) {
                    $errorCount++;
                    $errors[] = [
                        'row' => $rowNumber,
                        'data' => $data,
                        'error' => $e->getMessage()
                    ];
                }
                
                $rowNumber++;
            }
            
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
        
        fclose($handle);
        
        return [
            'success' => true,
            'imported' => $successCount,
            'failed' => $errorCount,
            'errors' => $errors
        ];
    }
    
    private function importRow($conn, $data, $userId) {
        // 验证SKU
        if (empty($data['sku'])) {
            throw new Exception('SKU不能为空');
        }
        
        // 验证名称
        if (empty($data['name'])) {
            throw new Exception('商品名称不能为空');
        }
        
        // 查找分类ID
        $categoryId = 0;
        if (!empty($data['category'])) {
            $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
            $stmt->bind_param("s", $data['category']);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            if ($result) {
                $categoryId = $result['id'];
            }
        }
        
        // 插入或更新
        $stmt = $conn->prepare("
            INSERT INTO products (sku, name, category_id, removal_buffer, inventory_cycle)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                name = VALUES(name),
                category_id = VALUES(category_id),
                removal_buffer = VALUES(removal_buffer),
                inventory_cycle = VALUES(inventory_cycle)
        ");
        
        $removalBuffer = intval($data['removal_buffer'] ?? 0);
        $inventoryCycle = $data['inventory_cycle'] ?? 'none';
        
        $stmt->bind_param(
            "sisis",
            $data['sku'],
            $data['name'],
            $categoryId,
            $removalBuffer,
            $inventoryCycle
        );
        
        if (!$stmt->execute()) {
            throw new Exception('数据库错误: ' . $stmt->error);
        }
    }
}

// API端点
if ($action === 'import_csv') {
    if (!isset($_FILES['file'])) {
        echo json_encode(['success' => false, 'message' => '未上传文件']);
        exit;
    }
    
    try {
        $filepath = validateUpload($_FILES['file']);
        
        $importer = new CsvImporter($filepath);
        $importer->validate();
        $result = $importer->import($_SESSION['user_id']);
        
        unlink($filepath); // 删除临时文件
        
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
```

**2. 前端上传界面**:
```html
<div class="import-panel" style="display:none;">
    <h5>批量导入商品</h5>
    
    <!-- 下载模板 -->
    <a href="/templates/import_template.csv" class="btn btn-sm btn-outline-primary mb-3">
        <i class="bi bi-download"></i> 下载CSV模板
    </a>
    
    <!-- 上传区域 -->
    <div class="upload-area border rounded p-4 text-center" 
         id="uploadArea"
         ondrop="handleDrop(event)"
         ondragover="handleDragOver(event)"
         ondragleave="handleDragLeave(event)">
        <i class="bi bi-cloud-upload display-4 text-muted"></i>
        <p class="mt-2">拖拽CSV文件到这里，或点击选择文件</p>
        <input type="file" id="csvFile" accept=".csv" style="display:none" onchange="handleFileSelect(event)">
        <button class="btn btn-primary" onclick="document.getElementById('csvFile').click()">
            选择文件
        </button>
    </div>
    
    <!-- 进度条 -->
    <div class="progress mt-3" style="display:none" id="importProgress">
        <div class="progress-bar" role="progressbar" style="width: 0%"></div>
    </div>
    
    <!-- 结果 -->
    <div class="import-result mt-3" style="display:none" id="importResult"></div>
</div>

<script>
function handleFileSelect(event) {
    const file = event.target.files[0];
    uploadFile(file);
}

function handleDrop(event) {
    event.preventDefault();
    event.target.classList.remove('border-primary');
    
    const file = event.dataTransfer.files[0];
    if (file && file.name.endsWith('.csv')) {
        uploadFile(file);
    } else {
        alert('请上传CSV文件');
    }
}

function handleDragOver(event) {
    event.preventDefault();
    event.target.classList.add('border-primary');
}

function handleDragLeave(event) {
    event.target.classList.remove('border-primary');
}

function uploadFile(file) {
    const formData = new FormData();
    formData.append('file', file);
    
    const progressDiv = document.getElementById('importProgress');
    const progressBar = progressDiv.querySelector('.progress-bar');
    const resultDiv = document.getElementById('importResult');
    
    progressDiv.style.display = 'block';
    progressBar.style.width = '10%';
    
    fetch('/index.php?api=import_csv', {
        method: 'POST',
        body: formData,
        xhr: () => {
            const xhr = new XMLHttpRequest();
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percent = (e.loaded / e.total) * 100;
                    progressBar.style.width = percent + '%';
                }
            });
            return xhr;
        }
    })
    .then(response => response.json())
    .then(data => {
        progressBar.style.width = '100%';
        resultDiv.style.display = 'block';
        
        if (data.success) {
            resultDiv.innerHTML = `
                <div class="alert alert-success">
                    <h6>导入完成</h6>
                    <p>成功导入 ${data.imported} 条数据</p>
                    ${data.failed > 0 ? `<p class="text-danger">失败 ${data.failed} 条</p>` : ''}
                </div>
                ${data.errors.length > 0 ? `
                    <div class="alert alert-warning">
                        <h6>错误详情</h6>
                        <ul class="small">
                            ${data.errors.map(e => `<li>第${e.row}行: ${e.error}</li>`).join('')}
                        </ul>
                    </div>
                ` : ''}
            `;
        } else {
            resultDiv.innerHTML = `
                <div class="alert alert-danger">
                    ${data.message}
                </div>
            `;
        }
    })
    .catch(error => {
        resultDiv.innerHTML = `
            <div class="alert alert-danger">
                上传失败: ${error.message}
            </div>
        `;
    });
}
</script>
```

**3. 导入模板** (`templates/import_template.csv`):
```csv
sku,name,category,removal_buffer,inventory_cycle
6901234567890,可口可乐500ml,小食品,7,weekly
6901234567891,康师傅红烧牛肉面,小食品,15,monthly
6901234567892,星巴克咖啡豆,咖啡豆,30,monthly
```

#### 验收标准
- [ ] 支持CSV格式导入
- [ ] 提供标准模板下载
- [ ] 实时显示上传进度
- [ ] 详细显示错误信息
- [ ] 支持增量更新（SKU重复时更新）

---

### 3.3 移动端适配 📱

**优先级**: ⭐⭐⭐⭐ (高)
**复杂度**: 中等
**工作量**: 10-12小时

#### 功能需求
- 响应式布局适配
- 移动端专用UI组件
- 触摸手势优化
- PWA支持（离线使用）

#### 技术方案

**1. 响应式断点**:
```css
/* Bootstrap已有断点 */
/* xs: <576px */
/* sm: ≥576px */
/* md: ≥768px */
/* lg: ≥992px */
/* xl: ≥1200px */

/* 自定义移动端优化 */
@media (max-width: 768px) {
    /* 隐藏非关键元素 */
    .desktop-only { display: none !important; }
    
    /* 调整布局 */
    .container { padding: 0 15px; }
    .card { margin-bottom: 1rem; }
    
    /* 表格响应式 */
    .table-responsive table {
        font-size: 0.875rem;
    }
    .table-responsive th,
    .table-responsive td {
        padding: 0.5rem;
    }
    
    /* 按钮增大 */
    .btn {
        padding: 0.75rem 1rem;
        font-size: 1rem;
    }
    
    /* 导航栏调整 */
    .navbar-brand { font-size: 1.25rem; }
    .navbar-nav { font-size: 1rem; }
}

/* 超小屏幕优化 */
@media (max-width: 375px) {
    .container-fluid { padding: 0; }
    .card { border-radius: 0; }
}
```

**2. 移动端专用组件**:

**底部导航栏**:
```html
<div class="mobile-bottom-nav d-md-none fixed-bottom bg-white border-top">
    <div class="d-flex justify-content-around py-2">
        <a href="/index.php" class="nav-item text-center">
            <i class="bi bi-house-door fs-4"></i>
            <div class="small">首页</div>
        </a>
        <a href="/index.php#scan" class="nav-item text-center">
            <i class="bi bi-qr-code-scan fs-4"></i>
            <div class="small">扫码</div>
        </a>
        <a href="/index.php#inventory" class="nav-item text-center">
            <i class="bi bi-list-check fs-4"></i>
            <div class="small">盘点</div>
        </a>
        <a href="/dashboard.php" class="nav-item text-center">
            <i class="bi bi-graph-up fs-4"></i>
            <div class="small">统计</div>
        </a>
    </div>
</div>

<style>
.mobile-bottom-nav {
    padding-bottom: env(safe-area-inset-bottom);
}

.mobile-bottom-nav .nav-item {
    color: #6c757d;
    text-decoration: none;
}

.mobile-bottom-nav .nav-item.active {
    color: #0d6efd;
}

/* 给主内容添加底部padding，避免被导航栏遮挡 */
main {
    padding-bottom: 70px;
}

@media (min-width: 768px) {
    main { padding-bottom: 0; }
}
</style>
```

**触摸优化的表格**:
```html
<!-- 卡片式表格（移动端更友好） -->
<div class="d-block d-md-none">
    <!-- 每行一个卡片 -->
    <div class="mobile-table">
        <?php foreach ($items as $item): ?>
            <div class="card mb-2">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1"><?= e($item['name']) ?></h6>
                            <small class="text-muted"><?= e($item['sku']) ?></small>
                        </div>
                        <div class="text-end">
                            <div class="h5 mb-0"><?= $item['quantity'] ?></div>
                            <small class="<?= $item['days_remaining'] < 7 ? 'text-danger' : 'text-muted' ?>">
                                <?= $item['expiry_date'] ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- 桌面端保持表格 -->
<div class="d-none d-md-block table-responsive">
    <table class="table table-hover">
        <!-- 原有表格 -->
    </table>
</div>
```

**3. PWA支持**:

**Manifest文件** (`manifest.json`):
```json
{
    "name": "保质期管理系统",
    "short_name": "保质期",
    "description": "智能库存预警与盘点工具",
    "start_url": "/",
    "display": "standalone",
    "background_color": "#ffffff",
    "theme_color": "#0d6efd",
    "orientation": "portrait",
    "icons": [
        {
            "src": "/assets/icons/icon-72x72.png",
            "sizes": "72x72",
            "type": "image/png"
        },
        {
            "src": "/assets/icons/icon-96x96.png",
            "sizes": "96x96",
            "type": "image/png"
        },
        {
            "src": "/assets/icons/icon-128x128.png",
            "sizes": "128x128",
            "type": "image/png"
        },
        {
            "src": "/assets/icons/icon-144x144.png",
            "sizes": "144x144",
            "type": "image/png"
        },
        {
            "src": "/assets/icons/icon-152x152.png",
            "sizes": "152x152",
            "type": "image/png"
        },
        {
            "src": "/assets/icons/icon-192x192.png",
            "sizes": "192x192",
            "type": "image/png"
        },
        {
            "src": "/assets/icons/icon-384x384.png",
            "sizes": "384x384",
            "type": "image/png"
        },
        {
            "src": "/assets/icons/icon-512x512.png",
            "sizes": "512x512",
            "type": "image/png"
        }
    ]
}
```

**在HTML中引用** (`index.php` head部分):
```html
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#0d6efd">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="/assets/icons/icon-152x152.png">
```

**Service Worker** (`sw.js`):
```javascript
const CACHE_NAME = 'expiry-v1';
const urlsToCache = [
    '/',
    '/index.php',
    '/dashboard.php',
    '/assets/css/bootstrap.min.css',
    '/assets/js/bootstrap.bundle.min.js',
    '/assets/icons/icon-192x192.png'
];

// 安装事件
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('Opened cache');
                return cache.addAll(urlsToCache);
            })
    );
});

// 拦截请求
self.addEventListener('fetch', event => {
    event.respondWith(
        caches.match(event.request)
            .then(response => {
                // 缓存命中，返回缓存的版本
                if (response) {
                    return response;
                }
                
                // 缓存未命中，请求网络
                return fetch(event.request).then(response => {
                    // 检查是否是有效响应
                    if (!response || response.status !== 200 || response.type !== 'basic') {
                        return response;
                    }
                    
                    // 克隆响应
                    const responseToCache = response.clone();
                    
                    caches.open(CACHE_NAME)
                        .then(cache => {
                            cache.put(event.request, responseToCache);
                        });
                    
                    return response;
                });
            })
    );
});

// 激活事件（清理旧缓存）
self.addEventListener('activate', event => {
    const cacheWhitelist = [CACHE_NAME];
    
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheWhitelist.indexOf(cacheName) === -1) {
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
});
```

**注册Service Worker** (`app.js`):
```javascript
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then(registration => {
                console.log('ServiceWorker registered: ', registration);
            })
            .catch(registrationError => {
                console.log('ServiceWorker registration failed: ', registrationError);
            });
    });
}
```

#### 移动端性能优化
```javascript
// 图片懒加载
const lazyImages = document.querySelectorAll('img[data-src]');

const imageObserver = new IntersectionObserver((entries, observer) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const img = entry.target;
            img.src = img.dataset.src;
            img.classList.remove('lazy');
            observer.unobserve(img);
        }
    });
});

lazyImages.forEach(img => imageObserver.observe(img));

// 虚拟滚动（长列表优化）
class VirtualList {
    constructor(options) {
        this.itemHeight = options.itemHeight;
        this.items = options.items;
        this.renderItem = options.renderItem;
        this.container = options.container;
        this.viewportHeight = options.viewportHeight || window.innerHeight;
        
        this.init();
    }
    
    init() {
        this.visibleCount = Math.ceil(this.viewportHeight / this.itemHeight);
        this.container.style.height = `${this.items.length * this.itemHeight}px`;
        this.render();
        
        this.container.addEventListener('scroll', () => {
            requestAnimationFrame(() => this.render());
        });
    }
    
    render() {
        const scrollTop = this.container.scrollTop;
        const startIndex = Math.floor(scrollTop / this.itemHeight);
        const endIndex = Math.min(startIndex + this.visibleCount, this.items.length - 1);
        
        // 清空容器
        this.container.innerHTML = '';
        
        // 渲染可见项
        for (let i = startIndex; i <= endIndex; i++) {
            const item = this.items[i];
            const element = this.renderItem(item, i);
            element.style.position = 'absolute';
            element.style.top = `${i * this.itemHeight}px`;
            element.style.height = `${this.itemHeight}px`;
            this.container.appendChild(element);
        }
    }
}

// 使用示例
const virtualList = new VirtualList({
    itemHeight: 60,
    items: inventoryItems,
    renderItem: (item, index) => {
        const div = document.createElement('div');
        div.className = 'inventory-item';
        div.textContent = item.name;
        return div;
    },
    container: document.querySelector('.inventory-list')
});
```

#### 验收标准
- [ ] 在iPhone/Android上正常显示
- [ ] 触摸操作流畅
- [ ] 支持添加到主屏幕
- [ ] 离线可浏览已有数据
- [ ] 页面加载时间 < 3s（4G网络）

---

## 4. 架构设计

### 4.1 模块化架构设计 🏗️

**优先级**: ⭐⭐⭐⭐⭐ (最高)
**复杂度**: 高
**工作量**: 16-20小时

#### 当前问题
- 所有代码集中在单文件（index.php 2500+行）
- 业务逻辑与UI混合
- 难以维护和测试
- 无法复用

#### 目标架构

**分层架构**:
```
┌─────────────────────────────────────────────────────┐
│                  Presentation Layer                  │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────┐ │
│  │   Views      │  │   Templates  │  │   Assets   │ │
│  │ (HTML/JS)    │  │   (PHP)      │  │ (CSS/IMG)  │ │
│  └──────────────┘  └──────────────┘  └────────────┘ │
└─────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────┐
│                  Application Layer                   │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────┐ │
│  │ Controllers  │  │   Services   │  │  Helpers   │ │
│  │ (路由/验证)  │  │ (业务逻辑)   │  │ (工具函数) │ │
│  └──────────────┘  └──────────────┘  └────────────┘ │
└─────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────┐
│                    Domain Layer                      │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────┐ │
│  │   Models     │  │  Validators  │  │ Exceptions │ │
│  │ (数据实体)   │  │ (数据验证)   │  │ (异常处理) │ │
│  └──────────────┘  └──────────────┘  └────────────┘ │
└─────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────┐
│                  Infrastructure Layer                │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────┐ │
│  │   Database   │  │    Cache     │  │    Log     │ │
│  │ (MySQL/PDO)  │  │   (Redis)    │  │ (Monolog)  │ │
│  └──────────────┘  └──────────────┘  └────────────┘ │
└─────────────────────────────────────────────────────┘
```

#### 目录结构重构

**新目录结构**:
```
expiry-clean/
├── public/                    # Web根目录
│   ├── index.php             # 入口文件（路由）
│   ├── .htaccess             # URL重写规则
│   └── assets/               # 静态资源
│       ├── css/
│       ├── js/
│       └── images/
├── app/                      # 应用代码
│   ├── Controllers/          # 控制器
│   │   ├── AuthController.php
│   │   ├── InventoryController.php
│   │   ├── ProductController.php
│   │   ├── DashboardController.php
│   │   └── ApiController.php
│   ├── Services/             # 业务服务
│   │   ├── InventoryService.php
│   │   ├── ProductService.php
│   │   ├── AlertService.php
│   │   ├── ExportService.php
│   │   └── ImportService.php
│   ├── Models/               # 数据模型
│   │   ├── User.php
│   │   ├── Product.php
│   │   ├── Batch.php
│   │   ├── InventorySession.php
│   │   └── Category.php
│   ├── Validators/           # 验证器
│   │   ├── ProductValidator.php
│   │   └── InventoryValidator.php
│   ├── Exceptions/           # 自定义异常
│   │   ├── ValidationException.php
│   │   └── AuthException.php
│   └── Helpers/              # 辅助函数
│       ├── Auth.php
│       ├── Csrf.php
│       ├── Response.php
│       └── View.php
├── config/                   # 配置文件
│   ├── app.php
│   ├── database.php
│   └── cache.php
├── database/                 # 数据库
│   ├── migrations/           # 迁移文件
│   └── seeds/               # 测试数据
├── storage/                  # 存储目录
│   ├── logs/
│   ├── cache/
│   └── uploads/
├── tests/                    # 测试文件
│   ├── Unit/
│   └── Integration/
├── vendor/                   # Composer依赖
├── composer.json
└── .env.example
```

#### 核心组件实现

**1. 路由系统** (`public/index.php`):
```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

// 加载环境变量
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// 启动会话
session_start();

// 简单路由器
$router = new App\Core\Router();

// 公开路由（不需要认证）
$router->get('/', 'HomeController@index');
$router->get('/login', 'AuthController@showLogin');
$router->post('/login', 'AuthController@login');
$router->post('/logout', 'AuthController@logout');

// 认证路由
$router->group(['middleware' => ['auth']], function($router) {
    $router->get('/dashboard', 'DashboardController@index');
    $router->get('/inventory', 'InventoryController@index');
    $router->get('/inventory/create', 'InventoryController@create');
    $router->post('/inventory', 'InventoryController@store');
    $router->get('/inventory/:id', 'InventoryController@show');
    $router->post('/inventory/:id', 'InventoryController@update');
    $router->delete('/inventory/:id', 'InventoryController@delete');
    
    // API路由
    $router->group(['prefix' => 'api'], function($router) {
        $router->get('/products', 'Api\ProductController@index');
        $router->post('/products', 'Api\ProductController@store');
        $router->get('/batches', 'Api\BatchController@index');
        $router->post('/batches', 'Api\BatchController@store');
        $router->get('/export', 'Api\ExportController@export');
        $router->post('/import', 'Api\ImportController@import');
    });
});

// 管理员路由
$router->group(['middleware' => ['auth', 'admin']], function($router) {
    $router->get('/admin', 'AdminController@index');
    $router->get('/admin/users', 'Admin\UserController@index');
    $router->get('/admin/categories', 'Admin\CategoryController@index');
});

// 分发请求
try {
    $router->dispatch();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '服务器错误: ' . $e->getMessage()
    ]);
}
```

**Router类** (`app/Core/Router.php`):
```php
<?php
namespace App\Core;

class Router {
    private $routes = [];
    private $middlewares = [];
    
    public function get($path, $handler) {
        $this->addRoute('GET', $path, $handler);
    }
    
    public function post($path, $handler) {
        $this->addRoute('POST', $path, $handler);
    }
    
    public function delete($path, $handler) {
        $this->addRoute('DELETE', $path, $handler);
    }
    
    public function group($options, $callback) {
        $oldMiddlewares = $this->middlewares;
        
        if (isset($options['middleware'])) {
            $this->middlewares = array_merge(
                $this->middlewares,
                (array) $options['middleware']
            );
        }
        
        $callback($this);
        
        $this->middlewares = $oldMiddlewares;
    }
    
    private function addRoute($method, $path, $handler) {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'middlewares' => $this->middlewares
        ];
    }
    
    public function dispatch() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            
            // 将路由参数转换为正则
            $pattern = preg_replace('/:([a-zA-Z0-9_]+)/', '([^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';
            
            if (preg_match($pattern, $path, $matches)) {
                // 执行中间件
                foreach ($route['middlewares'] as $middleware) {
                    $this->executeMiddleware($middleware);
                }
                
                // 执行控制器
                return $this->executeHandler(
                    $route['handler'],
                    array_slice($matches, 1)
                );
            }
        }
        
        // 未找到路由
        http_response_code(404);
        echo '404 Not Found';
    }
    
    private function executeMiddleware($middleware) {
        switch ($middleware) {
            case 'auth':
                if (!isset($_SESSION['user_id'])) {
                    header('Location: /login');
                    exit;
                }
                break;
            case 'admin':
                if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
                    http_response_code(403);
                    echo '403 Forbidden';
                    exit;
                }
                break;
            case 'csrf':
                // CSRF验证逻辑
                break;
        }
    }
    
    private function executeHandler($handler, $params = []) {
        list($controller, $action) = explode('@', $handler);
        $controllerClass = "App\\Controllers\\{$controller}";
        
        if (!class_exists($controllerClass)) {
            throw new Exception("Controller not found: {$controllerClass}");
        }
        
        $instance = new $controllerClass();
        
        if (!method_exists($instance, $action)) {
            throw new Exception("Action not found: {$controller}::{$action}");
        }
        
        return call_user_func_array([$instance, $action], $params);
    }
}
```

**2. 控制器示例** (`app/Controllers/InventoryController.php`):
```php
<?php
namespace App\Controllers;

use App\Services\InventoryService;
use App\Validators\InventoryValidator;
use App\Helpers\Response;

class InventoryController {
    private $inventoryService;
    
    public function __construct() {
        $this->inventoryService = new InventoryService();
    }
    
    public function index() {
        $userId = $_SESSION['user_id'];
        $sessions = $this->inventoryService->getUserSessions($userId);
        
        return View::render('inventory/index', [
            'sessions' => $sessions
        ]);
    }
    
    public function create() {
        $categories = $this->inventoryService->getCategories();
        
        return View::render('inventory/create', [
            'categories' => $categories
        ]);
    }
    
    public function store() {
        $validator = new InventoryValidator();
        $errors = $validator->validate($_POST);
        
        if (!empty($errors)) {
            return Response::json([
                'success' => false,
                'errors' => $errors
            ], 400);
        }
        
        try {
            $session = $this->inventoryService->createSession(
                $_SESSION['user_id'],
                $_POST
            );
            
            return Response::json([
                'success' => true,
                'data' => $session
            ]);
        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    public function show($sessionId) {
        $session = $this->inventoryService->getSessionDetails($sessionId);
        
        if (!$session) {
            http_response_code(404);
            echo '盘点单不存在';
            return;
        }
        
        return View::render('inventory/show', [
            'session' => $session
        ]);
    }
}
```

**3. 服务层** (`app/Services/InventoryService.php`):
```php
<?php
namespace App\Services;

use App\Models\Product;
use App\Models\Batch;
use App\Models\InventorySession;
use App\Exceptions\ValidationException;

class InventoryService {
    private $cache;
    
    public function __construct() {
        $this->cache = new \Cache();
    }
    
    public function createSession($userId, $data) {
        $conn = \getDBConnection();
        $conn->begin_transaction();
        
        try {
            // 生成session_key
            $sessionKey = 'S' . time() . rand(1000, 9999);
            
            // 创建盘点单
            $session = new InventorySession();
            $session->session_key = $sessionKey;
            $session->user_id = $userId;
            $session->item_count = 0;
            $session->save();
            
            // 添加商品
            if (isset($data['items'])) {
                foreach ($data['items'] as $item) {
                    $this->addBatch($sessionKey, $item);
                }
            }
            
            $conn->commit();
            
            // 清除缓存
            $this->cache->forget("sessions:user:{$userId}");
            
            return $session;
        } catch (\Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }
    
    public function addBatch($sessionKey, $item) {
        $conn = \getDBConnection();
        
        // 查找或创建商品
        $product = Product::findBySku($item['sku']);
        
        if (!$product) {
            $product = new Product();
            $product->sku = $item['sku'];
            $product->name = $item['name'];
            $product->category_id = $item['category_id'] ?? 0;
            $product->save();
        }
        
        // 创建批次
        $batch = new Batch();
        $batch->product_id = $product->id;
        $batch->session_id = $sessionKey;
        $batch->expiry_date = $item['expiry_date'];
        $batch->quantity = $item['quantity'];
        $batch->save();
        
        // 更新盘点单计数
        $stmt = $conn->prepare("
            UPDATE inventory_sessions 
            SET item_count = item_count + 1 
            WHERE session_key = ?
        ");
        $stmt->bind_param("s", $sessionKey);
        $stmt->execute();
        
        return $batch;
    }
    
    public function getSessionDetails($sessionId) {
        return $this->cache->remember("session:{$sessionId}", 300, function() use ($sessionId) {
            $conn = \getDBConnection();
            $stmt = $conn->prepare("
                SELECT 
                    s.*,
                    b.id as batch_id,
                    p.sku,
                    p.name,
                    b.expiry_date,
                    b.quantity,
                    c.name as category_name
                FROM inventory_sessions s
                LEFT JOIN batches b ON s.session_key = b.session_id
                LEFT JOIN products p ON b.product_id = p.id
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE s.session_key = ?
                ORDER BY b.expiry_date ASC
            ");
            $stmt->bind_param("s", $sessionId);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        });
    }
}
```

**4. 模型层** (`app/Models/Product.php`):
```php
<?php
namespace App\Models;

class Product {
    public $id;
    public $sku;
    public $name;
    public $category_id;
    public $removal_buffer;
    public $inventory_cycle;
    public $last_inventory_at;
    public $created_at;
    public $updated_at;
    
    private static $tableName = 'products';
    
    public function save() {
        $conn = \getDBConnection();
        
        if ($this->id) {
            // 更新
            $stmt = $conn->prepare("
                UPDATE products 
                SET sku = ?, name = ?, category_id = ?, 
                    removal_buffer = ?, inventory_cycle = ?
                WHERE id = ?
            ");
            $stmt->bind_param(
                "sisisi",
                $this->sku,
                $this->name,
                $this->category_id,
                $this->removal_buffer,
                $this->inventory_cycle,
                $this->id
            );
            return $stmt->execute();
        } else {
            // 插入
            $stmt = $conn->prepare("
                INSERT INTO products (sku, name, category_id, removal_buffer, inventory_cycle)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "sisii",
                $this->sku,
                $this->name,
                $this->category_id,
                $this->removal_buffer,
                $this->inventory_cycle
            );
            
            if ($stmt->execute()) {
                $this->id = $conn->insert_id;
                return true;
            }
            return false;
        }
    }
    
    public static function findBySku($sku) {
        $conn = \getDBConnection();
        $stmt = $conn->prepare("SELECT * FROM products WHERE sku = ?");
        $stmt->bind_param("s", $sku);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result) {
            $product = new self();
            foreach ($result as $key => $value) {
                $product->$key = $value;
            }
            return $product;
        }
        return null;
    }
    
    public static function find($id) {
        $conn = \getDBConnection();
        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result) {
            $product = new self();
            foreach ($result as $key => $value) {
                $product->$key = $value;
            }
            return $product;
        }
        return null;
    }
    
    public function delete() {
        if (!$this->id) return false;
        
        $conn = \getDBConnection();
        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $stmt->bind_param("i", $this->id);
        return $stmt->execute();
    }
    
    public function batches() {
        $conn = \getDBConnection();
        $stmt = $conn->prepare("
            SELECT b.* 
            FROM batches b
            WHERE b.product_id = ?
            ORDER BY b.expiry_date ASC
        ");
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $batches = [];
        while ($row = $result->fetch_assoc()) {
            $batch = new Batch();
            foreach ($row as $key => $value) {
                $batch->$key = $value;
            }
            $batches[] = $batch;
        }
        return $batches;
    }
}
```

---

### 4.2 接口契约 📜

#### RESTful API规范

**基础URL**: `https://your-domain.com/api/v1`

**通用响应格式**:
```json
{
    "success": true,
    "data": {},
    "message": "操作成功",
    "errors": [],
    "meta": {
        "timestamp": "2026-02-21T12:00:00Z",
        "request_id": "uuid",
        "pagination": {
            "current_page": 1,
            "per_page": 20,
            "total": 100,
            "total_pages": 5
        }
    }
}
```

**错误响应格式**:
```json
{
    "success": false,
    "error": {
        "code": "VALIDATION_ERROR",
        "message": "数据验证失败",
        "details": [
            {
                "field": "sku",
                "message": "SKU不能为空"
            }
        ]
    }
}
```

#### API端点清单

| 资源 | 方法 | 端点 | 描述 |
|------|------|------|------|
| **商品** | GET | `/products` | 获取商品列表 |
| | POST | `/products` | 创建商品 |
| | GET | `/products/:id` | 获取商品详情 |
| | PUT | `/products/:id` | 更新商品 |
| | DELETE | `/products/:id` | 删除商品 |
| **批次** | GET | `/batches` | 获取批次列表 |
| | POST | `/batches` | 创建批次 |
| | GET | `/batches/:id` | 获取批次详情 |
| | PUT | `/batches/:id` | 更新批次 |
| | DELETE | `/batches/:id` | 删除批次 |
| **盘点单** | GET | `/inventory-sessions` | 获取盘点单列表 |
| | POST | `/inventory-sessions` | 创建盘点单 |
| | GET | `/inventory-sessions/:id` | 获取盘点单详情 |
| | PUT | `/inventory-sessions/:id` | 更新盘点单 |
| | DELETE | `/inventory-sessions/:id` | 删除盘点单 |
| **预警** | GET | `/alerts` | 获取预警列表 |
| | GET | `/alerts/summary` | 获取预警统计 |
| **导出** | GET | `/export/excel` | 导出Excel |
| | GET | `/export/pdf` | 导出PDF |
| **导入** | POST | `/import/csv` | 导入CSV |

#### 详细接口定义

**1. 获取商品列表**
```http
GET /api/v1/products?page=1&per_page=20&category=1&search=可乐
Authorization: Bearer {api_key}
```

响应 (200):
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "sku": "6901234567890",
            "name": "可口可乐 500ml",
            "category": {
                "id": 1,
                "name": "小食品"
            },
            "removal_buffer": 7,
            "inventory_cycle": "weekly",
            "last_inventory_at": "2026-02-20T10:00:00Z",
            "created_at": "2026-02-15T08:00:00Z",
            "updated_at": "2026-02-20T10:00:00Z"
        }
    ],
    "meta": {
        "pagination": {
            "current_page": 1,
            "per_page": 20,
            "total": 100,
            "total_pages": 5
        }
    }
}
```

**2. 创建盘点单**
```http
POST /api/v1/inventory-sessions
Authorization: Bearer {api_key}
Content-Type: application/json

{
    "items": [
        {
            "sku": "6901234567890",
            "name": "可口可乐 500ml",
            "category_id": 1,
            "batches": [
                {
                    "expiry_date": "2026-12-31",
                    "quantity": 100
                }
            ]
        }
    ]
}
```

响应 (201):
```json
{
    "success": true,
    "data": {
        "id": 123,
        "session_key": "S17384640001234",
        "item_count": 1,
        "created_at": "2026-02-21T12:00:00Z"
    }
}
```

**3. 获取预警列表**
```http
GET /api/v1/alerts?days=30&severity=critical
Authorization: Bearer {api_key}
```

响应 (200):
```json
{
    "success": true,
    "data": {
        "expired": [
            {
                "product": {
                    "sku": "6901234567890",
                    "name": "可口可乐 500ml"
                },
                "batch": {
                    "expiry_date": "2026-02-01",
                    "quantity": 50,
                    "days_overdue": 20
                }
            }
        ],
        "critical": [
            {
                "product": {
                    "sku": "6901234567891",
                    "name": "康师傅红烧牛肉面"
                },
                "batch": {
                    "expiry_date": "2026-02-25",
                    "quantity": 200,
                    "days_remaining": 4
                }
            }
        ]
    },
    "meta": {
        "generated_at": "2026-02-21T12:00:00Z"
    }
}
```

---

### 4.3 依赖关系图 🔗

```
┌─────────────────────────────────────────────────────────────┐
│                         前端层                               │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐ │
│  │ index.php   │  │dashboard.php│  │  admin.php          │ │
│  │ (主界面)    │  │ (看板)      │  │  (管理后台)          │ │
│  └──────┬──────┘  └──────┬──────┘  └──────────┬──────────┘ │
│         │                │                     │             │
│         └────────────────┼─────────────────────┘             │
│                          │                                   │
│              ┌───────────┴───────────┐                       │
│              │    JavaScript APIs    │                       │
│              │  - fetch()            │                       │
│              │  - WebSocket (future) │                       │
│              └───────────┬───────────┘                       │
└──────────────────────────┼───────────────────────────────────┘
                           │ HTTP/HTTPS
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                      应用层 (PHP)                            │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  ┌─────────────────────────────────────────────────────┐   │
│  │                  路由层                              │   │
│  │            Router (public/index.php)                │   │
│  └────────────────────┬────────────────────────────────┘   │
│                       │                                     │
│       ┌───────────────┼───────────────┐                   │
│       ▼               ▼               ▼                   │
│  ┌─────────┐    ┌─────────┐    ┌──────────┐              │
│  │Controllers│   │Services │   │Validators│              │
│  │- Auth    │    │-Inventory│   │- Product │              │
│  │- Product │    │- Product │   │- Batch   │              │
│  │- Inventory│   │- Alert  │    │- Session │              │
│  │- Admin   │    │- Export │    └──────────┘              │
│  │- API     │    │- Import │                              │
│  └────┬─────┘    └────┬─────┘                              │
│       │               │                                     │
│       └───────┬───────┘                                     │
│               ▼                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │                     中间件层                         │   │
│  │ - AuthMiddleware      (身份验证)                     │   │
│  │ - CsrfMiddleware      (CSRF保护)                     │   │
│  │ - RateLimitMiddleware (速率限制)                     │   │
│  │ - AdminMiddleware     (管理员权限)                   │   │
│  └────────────────────┬────────────────────────────────┘   │
└───────────────────────┼────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────────┐
│                      领域层                                  │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌──────────────┐  │
│  │ Models  │  │ Helpers │  │Exceptions│ │   Events     │  │
│  │- User   │  │- Auth   │  │- Validation│ |- ProductCreated│ │
│  │- Product│  │- Csrf   │  │- Auth      │ |- BatchAdded  │  │
│  │- Batch  │  │- Cache  │  │- NotFound  │ |- AlertTriggered││
│  │- Session│  │- View   │  └─────────────┘ └──────────────┘  │
│  │- Category│  │- Response│                                  │
│  └────┬────┘  └────┬─────┘                                  │
└───────┼────────────┼──────────────────────────────────────────┘
        │            │
        └────────┬───┘
                 ▼
┌─────────────────────────────────────────────────────────────┐
│                   基础设施层                                 │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐           │
│  │  Database  │  │   Cache    │  │    Log     │           │
│  │  (MySQL)   │  │  (Redis)   │  │  (Monolog) │           │
│  │            │  │            │  │            │           │
│  │ - PDO      │  │ - Cache    │  │ - File     │           │
│  │ - Query    │  │ - Session  │  │ - Syslog   │           │
│  └────────────┘  └────────────┘  └────────────┘           │
│                                                               │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐           │
│  │   File     │  │   Email    │  │  External  │           │
│  │  Storage   │  │  (SMTP)    │  │   APIs     │           │
│  │            │  │            │  │            │           │
│  │ - Uploads  │  │ - PHPMailer│  │ - AI OCR   │           │
│  │ - Exports  │  │ - Swift    │  │ - SMS      │           │
│  └────────────┘  └────────────┘  └────────────┘           │
└─────────────────────────────────────────────────────────────┘
```

**依赖说明**:

1. **前端层 → 应用层**: 通过HTTP/HTTPS通信，使用REST API
2. **应用层 → 领域层**: 直接调用Models和Helpers
3. **领域层 → 基础设施层**: 通过抽象接口访问数据库、缓存、日志
4. **应用层内部**: Controllers依赖Services，Services依赖Models
5. **中间件层**: 横切关注点，拦截所有请求

**解耦原则**:
- 上层不直接依赖下层实现
- 通过依赖注入降低耦合
- 使用接口而非具体实现
- 事件驱动实现松散耦合

---

## 实施计划

### 分阶段实施路线图

#### 🎯 Phase 1: 安全加固 (Week 1-2)
**目标**: 建立企业级安全基础

| 任务 | 优先级 | 工作量 | 负责人 |
|------|--------|--------|--------|
| CSRF Token实现 | ⭐⭐⭐⭐⭐ | 4-6h | Security Expert |
| API安全增强 | ⭐⭐⭐⭐⭐ | 6-8h | Security Expert |
| XSS防护 | ⭐⭐⭐⭐ | 2-3h | Security Expert |
| 文件上传安全 | ⭐⭐⭐⭐ | 3-4h | Security Expert |
| 安全测试 | ⭐⭐⭐⭐⭐ | 4-6h | QA Team |

**里程碑**: 所有安全测试通过，无高危漏洞

---

#### 🚀 Phase 2: 性能优化 (Week 3-4)
**目标**: 页面加载 < 500ms

| 任务 | 优先级 | 工作量 | 负责人 |
|------|--------|--------|--------|
| 数据库索引优化 | ⭐⭐⭐⭐ | 2-3h | DBA |
| 查询优化 | ⭐⭐⭐⭐ | 4-6h | Backend Dev |
| Redis缓存实现 | ⭐⭐⭐ | 6-8h | Backend Dev |
| 前端资源优化 | ⭐⭐⭐⭐ | 4-6h | Frontend Dev |
| 性能测试 | ⭐⭐⭐⭐ | 3-4h | QA Team |

**里程碑**: Lighthouse Score > 90

---

#### 🌟 Phase 3: 功能增强 (Week 5-8)
**目标**: 核心功能完善

| 任务 | 优先级 | 工作量 | 负责人 |
|------|--------|--------|--------|
| Excel导出 | ⭐⭐⭐⭐ | 4-5h | Backend Dev |
| PDF报告 | ⭐⭐⭐ | 4-5h | Backend Dev |
| 批量导入 | ⭐⭐⭐ | 4-6h | Backend Dev |
| 移动端适配 | ⭐⭐⭐⭐ | 10-12h | Frontend Dev |
| 功能测试 | ⭐⭐⭐⭐ | 8-10h | QA Team |

**里程碑**: 所有新功能上线，用户验收通过

---

#### 🏗️ Phase 4: 架构重构 (Week 9-12)
**目标**: 可维护性提升

| 任务 | 优先级 | 工作量 | 负责人 |
|------|--------|--------|--------|
| 目录结构重构 | ⭐⭐⭐⭐⭐ | 8-10h | Architect |
| 路由系统实现 | ⭐⭐⭐⭐⭐ | 6-8h | Backend Dev |
| 服务层抽取 | ⭐⭐⭐⭐⭐ | 12-16h | Backend Dev |
| 模型层实现 | ⭐⭐⭐⭐ | 8-10h | Backend Dev |
| 单元测试 | ⭐⭐⭐⭐ | 16-20h | QA Team |
| 集成测试 | ⭐⭐⭐⭐ | 8-10h | QA Team |

**里程碑**: 代码覆盖率 > 80%，所有测试通过

---

#### 📚 Phase 5: 文档与部署 (Week 13-14)
**目标**: 生产就绪

| 任务 | 优先级 | 工作量 | 负责人 |
|------|--------|--------|--------|
| API文档生成 | ⭐⭐⭐ | 4-6h | Tech Writer |
| 用户手册更新 | ⭐⭐⭐ | 3-4h | Tech Writer |
| 部署自动化 | ⭐⭐⭐⭐ | 6-8h | DevOps |
| 监控告警 | ⭐⭐⭐⭐ | 4-6h | DevOps |
| 上线准备 | ⭐⭐⭐⭐⭐ | 4-6h | All |

**里程碑**: 生产环境稳定运行

---

### 总体时间线

```
Week 1-2:  ████████████░░░░░░░░░░░░░░░░░░░░░░  安全加固
Week 3-4:  ░░░░░░░░░░░░████████████░░░░░░░░░░  性能优化
Week 5-8:  ░░░░░░░░░░░░░░░░░░░░████████████████  功能增强
Week 9-12: ░░░░░░░░░░░░░░░░░░░░░░░░░░████████████  架构重构
Week 13-14:░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░████████  文档部署

Total: 14 weeks (3.5 months)
```

---

## 风险评估

### 技术风险

| 风险 | 概率 | 影响 | 缓解措施 |
|------|------|------|----------|
| Redis依赖 | 中 | 中 | 使用文件缓存作为降级方案 |
| 架构重构破坏现有功能 | 高 | 高 | 充分测试 + 灰度发布 |
| 移动端兼容性 | 中 | 中 | 多设备测试 + 渐进增强 |
| 导入性能瓶颈 | 低 | 中 | 分批处理 + 异步队列 |

### 业务风险

| 风险 | 概率 | 影响 | 缓解措施 |
|------|------|------|----------|
| 用户不适应新界面 | 中 | 中 | 用户培训 + 渐进式发布 |
| 数据迁移失败 | 低 | 高 | 完整备份 + 回滚方案 |
| 并发问题 | 中 | 中 | 事务隔离 + 乐观锁 |

### 应对策略
1. **技术预研**: 在正式实施前验证关键技术
2. **增量迭代**: 小步快跑，每个阶段独立交付
3. **充分测试**: 单元测试 + 集成测试 + 用户测试
4. **备份方案**: 每个阶段都有回滚能力
5. **监控告警**: 实时监控系统健康状态

---

## 资源估算

### 人力资源

| 角色 | 人数 | 工期 | 人天 |
|------|------|------|------|
| 架构师 | 1 | 14周 | 70 |
| 后端开发 | 2 | 12周 | 120 |
| 前端开发 | 1 | 8周 | 40 |
| QA工程师 | 1 | 10周 | 50 |
| DevOps | 1 | 4周 | 20 |
| 技术文档 | 1 | 2周 | 10 |
| **总计** | - | - | **310人天** |

### 技术资源

| 资源 | 用途 | 成本 |
|------|------|------|
| Redis服务器 | 缓存 | ¥200/月 |
| 对象存储 | 文件存储 | ¥100/月 |
| 监控服务 | 性能监控 | ¥300/月 |
| SSL证书 | HTTPS | ¥0 (Let's Encrypt) |
| 域名 | 访问入口 | ¥50/年 |
| **月度成本** | - | **~¥600/月** |

### 开发工具

| 工具 | 用途 | 成本 |
|------|------|------|
| PHPStorm | IDE | ¥1299/年 |
| Datadog | APM | ¥500/月 |
| GitHub | 代码管理 | ¥0 |
| Jira | 项目管理 | ¥0 (免费版) |

---

## 成功指标

### 技术指标
- [ ] Lighthouse Performance Score > 90
- [ ] 页面加载时间 < 500ms (4G网络)
- [ ] API响应时间 < 100ms (P95)
- [ ] 代码覆盖率 > 80%
- [ ] 零高危安全漏洞

### 业务指标
- [ ] 用户留存率提升 20%
- [ ] 盘点效率提升 50%
- [ ] 移动端使用占比 > 60%
- [ ] 用户满意度 > 4.5/5

### 可维护性指标
- [ ] 代码复杂度降低 30%
- [ ] 新功能开发速度提升 2x
- [ ] Bug修复时间降低 50%
- [ ] 文档完整性 > 90%

---

## 总结

本架构方案为保质期管理系统提供了全面的升级路径，涵盖安全性、性能、功能和架构四个维度。通过分阶段实施，可以在3.5个月内将系统从当前的功能完整状态升级为企业级SaaS平台。

**核心价值**:
1. **安全**: 企业级安全防护，数据无忧
2. **性能**: 秒级响应，极致体验
3. **功能**: 移动端、导出导入，全面覆盖
4. **架构**: 模块化设计，易于扩展

**下一步行动**:
1. 评审本架构方案
2. 确定优先级和资源
3. 启动Phase 1: 安全加固
4. 建立项目看板跟踪进度

---

**文档结束**

*Created by The Architect (贾维斯)*
*Date: 2026-02-21*
*Version: 1.0*
