# 保质期管理系统 - 技术选型与实现指南

**文档版本**: v1.0
**创建日期**: 2026-02-21
**关联文档**: FUTURE_IMPROVEMENTS_ARCHITECTURE.md

---

## 📋 目录

1. [安全类技术选型](#1-安全类技术选型)
2. [性能类技术选型](#2-性能类技术选型)
3. [功能类技术选型](#3-功能类技术选型)
4. [架构类技术选型](#4-架构类技术选型)
5. [部署运维选型](#5-部署运维选型)

---

## 1. 安全类技术选型

### 1.1 CSRF防护

**技术方案**: 同步令牌模式 (Synchronizer Token Pattern)

**实现要点**:
```php
// config/csrf.php
<?php
return [
    'token_name' => 'csrf_token',
    'header_name' => 'X-CSRF-Token',
    'token_length' => 32,
    'token_ttl' => 3600, // 1小时
    'regenerate_on_login' => true,
];
```

**库依赖**:
- 无需额外库，PHP原生实现

**优点**:
- ✅ 实现简单
- ✅ 安全性高
- ✅ 无外部依赖

**缺点**:
- ⚠️ 需要Session支持

**替代方案**:
- Double Submit Cookie (适用于无Session场景)
- JWT存储Token (适用于分布式系统)

---

### 1.2 API安全

#### Rate Limiting

**技术方案**: Redis + 滑动窗口算法

**实现要点**:
```php
// app/Services/RateLimiterService.php
<?php
namespace App\Services;

use Redis;

class RateLimiterService {
    private Redis $redis;
    private int $window; // 时间窗口（秒）
    private int $maxRequests;
    
    public function __construct(int $maxRequests = 100, int $window = 3600) {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->maxRequests = $maxRequests;
        $this->window = $window;
    }
    
    public function check(string $identifier): bool {
        $key = "ratelimit:{$identifier}";
        $now = time();
        
        // 使用ZADD记录每次请求的时间戳
        $this->redis->zAdd($key, $now, $now);
        
        // 删除窗口外的记录
        $this->redis->zRemRangeByScore($key, 0, $now - $this->window);
        
        // 统计当前窗口内的请求数
        $count = $this->redis->zCard($key);
        
        if ($count > $this->maxRequests) {
            return false;
        }
        
        // 设置过期时间
        $this->redis->expire($key, $this->window);
        return true;
    }
    
    public function getRemaining(string $identifier): int {
        $key = "ratelimit:{$identifier}";
        $count = $this->redis->zCard($key);
        return max(0, $this->maxRequests - $count);
    }
}
```

**库依赖**:
- `phpredis` 扩展

**优点**:
- ✅ 精确的速率限制
- ✅ 支持分布式
- ✅ 性能优异

**缺点**:
- ⚠️ 依赖Redis

**替代方案**:
- 基于文件的速率限制 (适用于小规模)
- Nginx限流模块 (适用于反向代理场景)

---

#### 请求签名

**技术方案**: HMAC-SHA256 签名

**实现要点**:
```php
// app/Services/SignatureService.php
<?php
namespace App\Services;

class SignatureService {
    public function generate(string $apiSecret, string $method, string $endpoint, array $params, int $timestamp): string {
        // 1. 按字母顺序排序参数
        ksort($params);
        
        // 2. 构建待签名字符串
        $payload = $timestamp . $method . $endpoint . http_build_query($params);
        
        // 3. 使用HMAC-SHA256签名
        return hash_hmac('sha256', $payload, $apiSecret);
    }
    
    public function verify(string $apiKey, string $signature, string $method, string $endpoint, array $params, int $timestamp): bool {
        // 1. 查找API密钥
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT api_secret FROM api_keys WHERE api_key = ?");
        $stmt->bind_param("s", $apiKey);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if (!$result) {
            return false;
        }
        
        // 2. 防重放攻击：时间戳必须在5分钟内
        if (abs(time() - $timestamp) > 300) {
            return false;
        }
        
        // 3. 计算期望的签名
        $expected = $this->generate($result['api_secret'], $method, $endpoint, $params, $timestamp);
        
        // 4. 使用hash_equals防止时序攻击
        return hash_equals($expected, $signature);
    }
}
```

**库依赖**:
- PHP原生函数

**优点**:
- ✅ 安全性高
- ✅ 无外部依赖
- ✅ 防篡改

**缺点**:
- ⚠️ 需要管理密钥

---

### 1.3 XSS防护

**技术方案**: 输出转义 + CSP头

**实现要点**:
```php
// app/Helpers/SecurityHelper.php
<?php
namespace App\Helpers;

class SecurityHelper {
    /**
     * HTML转义
     */
    public static function e(?string $string): string {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * HTML属性转义
     */
    public static function attr(?string $string): string {
        return htmlspecialchars($string ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * JS转义
     */
    public static function js(?string $string): string {
        $json = json_encode($string ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return substr($json, 1, -1); // 去掉引号
    }
    
    /**
     * URL转义
     */
    public static function url(?string $string): string {
        return rawurlencode($string ?? '');
    }
    
    /**
     * 设置CSP头
     */
    public static function setCspHeaders(): void {
        header("Content-Security-Policy: "
            . "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; "
            . "font-src 'self'; "
            . "connect-src 'self'; "
            . "frame-ancestors 'none';"
        );
    }
}
```

**库依赖**:
- 无需额外库

**优点**:
- ✅ 简单有效
- ✅ 防御深度

**替代方案**:
- HTML Purifier (更严格的HTML过滤)

---

## 2. 性能类技术选型

### 2.1 缓存系统

**技术方案**: Redis + 本地缓存（二级缓存）

**架构设计**:
```
┌─────────────────────────────────────────┐
│  L1: 本地缓存 (APCu)                    │ ← 最快（内存）
│     - 配置数据: 1小时                    │
│     - 分类数据: 24小时                   │
└──────────────┬──────────────────────────┘
               │ miss
               ▼
┌─────────────────────────────────────────┐
│  L2: Redis缓存                          │ ← 快（网络）
│     - 商品数据: 1小时                    │
│     - 盘点单: 5分钟                      │
│     - 统计数据: 15分钟                   │
└──────────────┬──────────────────────────┘
               │ miss
               ▼
┌─────────────────────────────────────────┐
│  L3: 数据库 (MySQL)                     │ ← 慢（持久化）
│     - 持久化存储                         │
└─────────────────────────────────────────┘
```

**实现要点**:
```php
// app/Services/CacheService.php
<?php
namespace App\Services;

use Redis;
use APCuIterator;

class CacheService {
    private Redis $redis;
    private bool $useLocal;
    private string $prefix = 'expiry:';
    
    public function __construct() {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->useLocal = extension_loaded('apcu');
    }
    
    /**
     * 获取缓存（二级缓存）
     */
    public function get(string $key, $default = null) {
        // L1: 本地缓存
        if ($this->useLocal) {
            $localKey = $this->prefix . 'local:' . $key;
            $value = apcu_fetch($localKey);
            if ($value !== false) {
                return $value;
            }
        }
        
        // L2: Redis缓存
        $redisKey = $this->prefix . 'redis:' . $key;
        $value = $this->redis->get($redisKey);
        
        if ($value !== false) {
            $data = json_decode($value, true);
            
            // 回写到L1
            if ($this->useLocal) {
                $localKey = $this->prefix . 'local:' . $key;
                apcu_store($localKey, $data, 300); // L1默认5分钟
            }
            
            return $data;
        }
        
        return $default;
    }
    
    /**
     * 设置缓存
     */
    public function set(string $key, $value, int $ttl = 3600): bool {
        $data = json_encode($value, JSON_UNESCAPED_UNICODE);
        
        // 同时写入L1和L2
        if ($this->useLocal) {
            $localKey = $this->prefix . 'local:' . $key;
            apcu_store($localKey, $value, min($ttl, 300)); // L1最多5分钟
        }
        
        $redisKey = $this->prefix . 'redis:' . $key;
        return $this->redis->setex($redisKey, $ttl, $data);
    }
    
    /**
     * 删除缓存
     */
    public function forget(string $key): bool {
        if ($this->useLocal) {
            $localKey = $this->prefix . 'local:' . $key;
            apcu_delete($localKey);
        }
        
        $redisKey = $this->prefix . 'redis:' . $key;
        return $this->redis->del($redisKey) > 0;
    }
    
    /**
     * 记忆化缓存
     */
    public function remember(string $key, int $ttl, callable $callback) {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }
}
```

**库依赖**:
- `phpredis` 扩展
- `apcu` 扩展（可选）

**优点**:
- ✅ 二级缓存，命中率高
- ✅ 性能优异
- ✅ 支持分布式

**缺点**:
- ⚠️ 依赖Redis

**替代方案**:
- Memcached (替代Redis)
- 文件缓存 (无Redis时降级)

---

### 2.2 数据库优化

**技术方案**: 索引优化 + 查询重写 + 连接池

**索引策略**:
```sql
-- 复合索引优化
-- 1. 批次查询优化（最常用）
ALTER TABLE batches 
ADD INDEX idx_product_expiry (product_id, expiry_date, quantity);

-- 2. 盘点单查询优化
ALTER TABLE batches 
ADD INDEX idx_session_expiry (session_id, expiry_date);

-- 3. 预警查询优化
ALTER TABLE batches 
ADD INDEX idx_expiry_alert (expiry_date, quantity);

-- 4. 商品分类查询
ALTER TABLE products 
ADD INDEX idx_category_cycle (category_id, inventory_cycle);

-- 5. 覆盖索引（包含所有查询字段）
ALTER TABLE batches 
ADD INDEX idx_covering (session_id, expiry_date, quantity, product_id);
```

**查询优化示例**:
```php
// ❌ N+1问题（优化前）
function getSessionsWithItems($userId) {
    $sessions = $conn->query("SELECT * FROM inventory_sessions WHERE user_id = $userId");
    
    foreach ($sessions as &$session) {
        $sessionId = $session['session_key'];
        // N+1查询
        $items = $conn->query("SELECT * FROM batches WHERE session_id = '$sessionId'");
        $session['items'] = $items->fetch_all(MYSQLI_ASSOC);
    }
    
    return $sessions;
}

// ✅ 一次查询（优化后）
function getSessionsWithItems($userId) {
    $sql = "SELECT 
        s.id as session_id,
        s.session_key,
        s.item_count,
        s.created_at,
        b.id as batch_id,
        b.product_id,
        b.expiry_date,
        b.quantity,
        p.sku,
        p.name,
        c.name as category_name
    FROM inventory_sessions s
    LEFT JOIN batches b ON s.session_key = b.session_id
    LEFT JOIN products p ON b.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
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
                'product_id' => $row['product_id'],
                'sku' => $row['sku'],
                'name' => $row['name'],
                'expiry_date' => $row['expiry_date'],
                'quantity' => $row['quantity'],
                'category' => $row['category_name']
            ];
        }
    }
    
    return array_values($sessions);
}
```

**性能提升**:
- 查询次数: N+1 → 1
- 查询时间: ~800ms → ~100ms
- 提升: 8x

---

### 2.3 前端优化

**技术方案**: 资源打包 + 懒加载 + Service Worker

**构建工具**: Gulp

**实现要点**:
```javascript
// gulpfile.js
const gulp = require('gulp');
const concat = require('gulp-concat');
const uglify = require('gulp-uglify');
const cleanCss = require('gulp-clean-css');
const htmlmin = require('gulp-htmlmin');
const imagemin = require('gulp-imagemin');

// 打包JavaScript
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

// 打包CSS
gulp.task('styles', () => {
    return gulp.src([
        'assets/css/vendor/bootstrap.min.css',
        'assets/css/app.css'
    ])
    .pipe(concat('bundle.min.css'))
    .pipe(cleanCss())
    .pipe(gulp.dest('dist/css'));
});

// 压缩HTML
gulp.task('html', () => {
    return gulp.src('*.php')
    .pipe(htmlmin({
        collapseWhitespace: true,
        removeComments: true,
        minifyJS: true,
        minifyCSS: true
    }))
    .pipe(gulp.dest('dist'));
});

// 压缩图片
gulp.task('images', () => {
    return gulp.src('assets/images/**/*')
    .pipe(imagemin())
    .pipe(gulp.dest('dist/images'));
});

// 默认任务
gulp.task('default', gulp.parallel('scripts', 'styles', 'html', 'images'));
```

**懒加载实现**:
```javascript
// IntersectionObserver懒加载
const lazyLoadObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const element = entry.target;
            
            // 图片懒加载
            if (element.tagName === 'IMG' && element.dataset.src) {
                element.src = element.dataset.src;
                element.classList.remove('lazy');
            }
            
            // 组件懒加载
            if (element.dataset.component) {
                loadComponent(element.dataset.component)
                    .then(html => {
                        element.outerHTML = html;
                    });
            }
            
            lazyLoadObserver.unobserve(element);
        }
    });
}, {
    rootMargin: '50px 0px' // 提前50px加载
});

// 观察所有懒加载元素
document.querySelectorAll('.lazy, [data-lazy]').forEach(el => {
    lazyLoadObserver.observe(el);
});
```

**Service Worker缓存**:
```javascript
// sw.js
const CACHE_VERSION = 'v1';
const CACHE_PREFIX = 'expiry-';
const CACHE_NAME = CACHE_PREFIX + CACHE_VERSION;

// 静态资源列表
const STATIC_ASSETS = [
    '/',
    '/index.php',
    '/dashboard.php',
    '/dist/css/bundle.min.css',
    '/dist/js/bundle.min.js',
    '/assets/icons/icon-192x192.png'
];

// 安装事件
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('[SW] Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => self.skipWaiting()) // 立即激活
    );
});

// 激活事件（清理旧缓存）
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(cacheNames => {
                return Promise.all(
                    cacheNames
                        .filter(cacheName => {
                            return cacheName.startsWith(CACHE_PREFIX) &&
                                   cacheName !== CACHE_NAME;
                        })
                        .map(cacheName => {
                            console.log('[SW] Deleting old cache:', cacheName);
                            return caches.delete(cacheName);
                        })
                );
            })
            .then(() => self.clients.claim())
    );
});

// 拦截请求
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);
    
    // 只缓存同源请求
    if (url.origin !== location.origin) {
        return;
    }
    
    // 网络优先策略（动态内容）
    if (request.url.includes('.php')) {
        event.respondWith(
            fetch(request)
                .then(response => {
                    // 克隆响应
                    const responseClone = response.clone();
                    // 缓存5分钟
                    caches.open(CACHE_NAME).then(cache => {
                        cache.put(request, responseClone);
                    });
                    return response;
                })
                .catch(() => {
                    // 网络失败，尝试缓存
                    return caches.match(request);
                })
        );
        return;
    }
    
    // 缓存优先策略（静态资源）
    event.respondWith(
        caches.match(request)
            .then(response => {
                if (response) {
                    return response;
                }
                return fetch(request).then(response => {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then(cache => {
                        cache.put(request, responseClone);
                    });
                    return response;
                });
            })
    );
});
```

**库依赖**:
- gulp
- gulp-concat
- gulp-uglify
- gulp-clean-css
- gulp-htmlmin
- gulp-imagemin

**优点**:
- ✅ 自动化构建
- ✅ 资源压缩
- ✅ 离线支持

---

## 3. 功能类技术选型

### 3.1 Excel导出

**技术方案**: PhpSpreadsheet

**安装**:
```bash
composer require phpoffice/phpspreadsheet
```

**实现示例**:
```php
// app/Services/ExportService.php
<?php
namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class ExportService {
    /**
     * 导出盘点单为Excel
     */
    public function exportInventory(string $sessionId): string {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // 设置标题
        $sheet->setCellValue('A1', '盘点单');
        $sheet->mergeCells('A1:E1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);
        
        // 设置表头
        $headers = ['SKU', '商品名称', '分类', '到期日期', '数量'];
        $sheet->fromArray($headers, null, 'A3');
        $sheet->getStyle('A3:E3')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E0E0']
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN]
            ]
        ]);
        
        // 获取数据
        $data = $this->getInventoryData($sessionId);
        
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
            $days = $this->getDaysUntilExpiry($item['expiry_date']);
            $color = $this->getExpiryColor($days);
            
            $sheet->getStyle("A{$row}:E{$row}")->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => $color]
                ],
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN]
                ]
            ]);
            
            $row++;
        }
        
        // 自动调整列宽
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // 保存文件
        $filename = "inventory_{$sessionId}_" . date('YmdHis') . ".xlsx";
        $filepath = sys_get_temp_dir() . '/' . $filename;
        
        $writer = new Xlsx($spreadsheet);
        $writer->save($filepath);
        
        return $filepath;
    }
    
    private function getExpiryColor(int $days): string {
        if ($days < 0) return 'FFCDD2'; // 已过期 - 红色
        if ($days <= 7) return 'FFF9C4'; // 7天内 - 黄色
        if ($days <= 30) return 'E1BEE7'; // 30天内 - 紫色
        return 'FFFFFF'; // 正常 - 白色
    }
    
    private function getDaysUntilExpiry(string $expiryDate): int {
        $expiry = new DateTime($expiryDate);
        $today = new DateTime();
        return (int)$today->diff($expiry)->format('%r%a');
    }
}
```

**库依赖**:
- `phpoffice/phpspreadsheet`

**优点**:
- ✅ 功能强大
- ✅ 格式丰富
- ✅ 兼容性好

**缺点**:
- ⚠️ 内存占用较大

**替代方案**:
- Spout (性能更好，功能较少)

---

### 3.2 PDF生成

**技术方案**: TCPDF

**安装**:
```bash
composer require tecnickcom/tcpdf
```

**实现示例**:
```php
// app/Services/PdfService.php
<?php
namespace App\Services;

use TCPDF;

class PdfService {
    /**
     * 生成预警报告PDF
     */
    public function generateWarningReport(int $days = 30): string {
        // 创建PDF
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // 设置文档信息
        $pdf->SetCreator('保质期管理系统');
        $pdf->SetTitle('过期预警报告');
        $pdf->SetSubject('库存预警');
        
        // 添加页面
        $pdf->AddPage();
        
        // 设置字体（支持中文）
        $pdf->SetFont('stsongstdlight', 'B', 20);
        $pdf->Cell(0, 10, '过期预警报告', 0, 1, 'C');
        
        $pdf->SetFont('stsongstdlight', '', 10);
        $pdf->Cell(0, 5, '生成时间: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
        $pdf->Ln(10);
        
        // 获取数据
        $data = $this->getExpiringData($days);
        
        // 统计信息
        $pdf->SetFont('stsongstdlight', 'B', 14);
        $pdf->Cell(0, 10, '统计摘要', 0, 1, 'L');
        
        $pdf->SetFont('stsongstdlight', '', 12);
        $pdf->Cell(0, 7, "已过期: {$data['expired']} 件", 0, 1, 'L');
        $pdf->Cell(0, 7, "7天内过期: {$data['critical']} 件", 0, 1, 'L');
        $pdf->Cell(0, 7, "30天内过期: {$data['warning']} 件", 0, 1, 'L');
        $pdf->Ln(10);
        
        // 表格
        $pdf->SetFont('stsongstdlight', 'B', 14);
        $pdf->Cell(0, 10, '详细列表', 0, 1, 'L');
        
        // 表格数据
        $tableHeader = ['SKU', '商品名称', '分类', '到期日期', '剩余天数', '数量'];
        $tableData = [];
        
        foreach ($data['items'] as $item) {
            $tableData[] = [
                $item['sku'],
                $item['name'],
                $item['category'],
                $item['expiry_date'],
                $item['days_remaining'],
                $item['quantity']
            ];
        }
        
        // 输出表格
        $pdf->writeHTMLTable($tableHeader, $tableData);
        
        // 保存文件
        $filename = "warning_report_" . date('YmdHis') . ".pdf";
        $filepath = sys_get_temp_dir() . '/' . $filename;
        $pdf->Output($filepath, 'F');
        
        return $filepath;
    }
    
    private function writeHTMLTable($pdf, $header, $data) {
        $html = '<table border="1" cellpadding="3">';
        
        // 表头
        $html .= '<tr style="background-color:#E0E0E0;font-weight:bold;">';
        foreach ($header as $cell) {
            $html .= "<td>{$cell}</td>";
        }
        $html .= '</tr>';
        
        // 数据行
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= "<td>{$cell}</td>";
            }
            $html .= '</tr>';
        }
        
        $html .= '</table>';
        $pdf->writeHTML($html);
    }
}
```

**库依赖**:
- `tecnickcom/tcpdf`

**优点**:
- ✅ 开源免费
- ✅ 支持中文
- ✅ 功能完整

**缺点**:
- ⚠️ 性能一般

**替代方案**:
- DomPDF (基于HTML)
- wkhtmltopdf (命令行工具)

---

### 3.3 CSV导入

**技术方案**: 原生PHP + League\Csv

**安装**:
```bash
composer require league/csv
```

**实现示例**:
```php
// app/Services/ImportService.php
<?php
namespace App\Services;

use League\Csv\Reader;
use League\Csv\Statement;

class ImportService {
    private array $errors = [];
    private int $successCount = 0;
    private int $failureCount = 0;
    
    /**
     * 导入CSV文件
     */
    public function importCsv(string $filepath, int $userId): array {
        // 打开CSV文件
        $csv = Reader::createFromPath($filepath, 'r');
        $csv->setHeaderOffset(0); // 第一行是表头
        
        // 验证必需字段
        $header = $csv->getHeader();
        $required = ['sku', 'name'];
        $missing = array_diff($required, $header);
        
        if (!empty($missing)) {
            throw new \Exception('缺少必需字段: ' . implode(', ', $missing));
        }
        
        // 获取记录
        $stmt = (new Statement());
        $records = $stmt->process($csv);
        
        // 导入数据
        $conn = getDBConnection();
        $conn->begin_transaction();
        
        try {
            $rowNumber = 2; // 从第2行开始
            
            foreach ($records as $record) {
                try {
                    $this->importRow($conn, $record, $userId);
                    $this->successCount++;
                } catch (\Exception $e) {
                    $this->failureCount++;
                    $this->errors[] = [
                        'row' => $rowNumber,
                        'data' => $record,
                        'error' => $e->getMessage()
                    ];
                }
                
                $rowNumber++;
            }
            
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollback();
            throw $e;
        }
        
        return [
            'success' => true,
            'imported' => $this->successCount,
            'failed' => $this->failureCount,
            'errors' => $this->errors
        ];
    }
    
    private function importRow($conn, array $data, int $userId): void {
        // 验证数据
        if (empty($data['sku'])) {
            throw new \Exception('SKU不能为空');
        }
        
        if (empty($data['name'])) {
            throw new \Exception('商品名称不能为空');
        }
        
        // 查找分类
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
        
        $removalBuffer = (int)($data['removal_buffer'] ?? 0);
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
            throw new \Exception('数据库错误: ' . $stmt->error);
        }
    }
}
```

**库依赖**:
- `league/csv`

**优点**:
- ✅ 功能完整
- ✅ 错误处理完善
- ✅ 性能好

**替代方案**:
- 原生fgetcsv() (功能较少)

---

### 3.4 移动端适配

**技术方案**: 响应式设计 + PWA

**CSS框架**: Bootstrap 5 (已有)

**响应式断点**:
```css
/* 自定义断点 */
:root {
    --mobile-breakpoint: 768px;
    --small-mobile-breakpoint: 375px;
}

/* 移动端优化 */
@media (max-width: 768px) {
    /* 隐藏桌面元素 */
    .desktop-only {
        display: none !important;
    }
    
    /* 调整间距 */
    .container {
        padding-left: 15px;
        padding-right: 15px;
    }
    
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
        min-height: 44px; /* 触摸最小尺寸 */
        padding: 0.75rem 1rem;
    }
    
    /* 输入框增大 */
    .form-control {
        min-height: 44px;
    }
    
    /* 卡片阴影减少 */
    .card {
        border: none;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        margin-bottom: 1rem;
    }
}

/* 超小屏幕 */
@media (max-width: 375px) {
    .container-fluid {
        padding: 0;
    }
    
    .card {
        border-radius: 0;
    }
    
    /* 隐藏非关键元素 */
    .hide-on-small {
        display: none !important;
    }
}
```

**PWA Manifest**:
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
    "scope": "/",
    "icons": [
        {
            "src": "/assets/icons/icon-72x72.png",
            "sizes": "72x72",
            "type": "image/png",
            "purpose": "any"
        },
        {
            "src": "/assets/icons/icon-96x96.png",
            "sizes": "96x96",
            "type": "image/png",
            "purpose": "any"
        },
        {
            "src": "/assets/icons/icon-128x128.png",
            "sizes": "128x128",
            "type": "image/png",
            "purpose": "any"
        },
        {
            "src": "/assets/icons/icon-144x144.png",
            "sizes": "144x144",
            "type": "image/png",
            "purpose": "any"
        },
        {
            "src": "/assets/icons/icon-152x152.png",
            "sizes": "152x152",
            "type": "image/png",
            "purpose": "any maskable"
        },
        {
            "src": "/assets/icons/icon-192x192.png",
            "sizes": "192x192",
            "type": "image/png",
            "purpose": "any maskable"
        },
        {
            "src": "/assets/icons/icon-384x384.png",
            "sizes": "384x384",
            "type": "image/png",
            "purpose": "any"
        },
        {
            "src": "/assets/icons/icon-512x512.png",
            "sizes": "512x512",
            "type": "image/png",
            "purpose": "any maskable"
        }
    ],
    "splash_pages": null
}
```

**库依赖**:
- Bootstrap 5 (已有)
- Workbox (PWA工具，可选)

---

## 4. 架构类技术选型

### 4.1 模块化架构

**技术方案**: PSR-4自动加载 + MVC模式

**目录结构**:
```
expiry-clean/
├── public/
│   └── index.php          # 入口文件
├── app/
│   ├── Controllers/       # 控制器
│   ├── Services/          # 业务逻辑
│   ├── Models/            # 数据模型
│   ├── Validators/        # 验证器
│   ├── Exceptions/        # 异常
│   └── Helpers/           # 辅助函数
├── config/                # 配置
├── vendor/                # 依赖
├── composer.json
└── .env
```

**composer.json**:
```json
{
    "name": "expiry/expiry-system",
    "description": "保质期管理系统",
    "type": "project",
    "require": {
        "php": ">=7.4",
        "ext-pdo": "*",
        "ext-json": "*",
        "ext-mbstring": "*",
        "league/csv": "^9.0",
        "phpoffice/phpspreadsheet": "^1.0",
        "tecnickcom/tcpdf": "^6.0",
        "vlucas/phpdotenv": "^5.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.0",
        "squizlabs/php_codesniffer": "^3.0"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        },
        "files": [
            "app/Helpers/functions.php"
        ]
    },
    "config": {
        "optimize-autoloader": true,
        "preferred-install": "dist",
        "sort-packages": true
    }
}
```

**优点**:
- ✅ 标准化
- ✅ 自动加载
- ✅ 易于维护

---

### 4.2 依赖注入

**技术方案**: 简单的DI容器

**实现**:
```php
// app/Core/Container.php
<?php
namespace App\Core;

class Container {
    private array $bindings = [];
    private array $instances = [];
    
    /**
     * 绑定服务
     */
    public function bind(string $abstract, callable $concrete): void {
        $this->bindings[$abstract] = $concrete;
    }
    
    /**
     * 获取服务实例
     */
    public function get(string $abstract) {
        // 如果已实例化，直接返回
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }
        
        // 如果没有绑定，尝试自动实例化
        if (!isset($this->bindings[$abstract])) {
            return $this->build($abstract);
        }
        
        // 实例化并缓存
        $instance = call_user_func($this->bindings[$abstract], $this);
        $this->instances[$abstract] = $instance;
        
        return $instance;
    }
    
    /**
     * 自动构建实例
     */
    private function build(string $abstract) {
        $reflection = new \ReflectionClass($abstract);
        
        if (!$reflection->isInstantiable()) {
            throw new \Exception("{$abstract} 不可实例化");
        }
        
        $constructor = $reflection->getConstructor();
        
        if (is_null($constructor)) {
            return new $abstract;
        }
        
        $dependencies = [];
        
        foreach ($constructor->getParameters() as $parameter) {
            $dependency = $parameter->getType()->getName();
            $dependencies[] = $this->get($dependency);
        }
        
        return $reflection->newInstanceArgs($dependencies);
    }
}

// 使用示例
$container = new Container();

// 绑定服务
$container->bind(\App\Services\CacheService::class, function($c) {
    return new \App\Services\CacheService();
});

$container->bind(\App\Services\InventoryService::class, function($c) {
    return new \App\Services\InventoryService($c->get(\App\Services\CacheService::class));
});

// 获取服务
$inventoryService = $container->get(\App\Services\InventoryService::class);
```

**优点**:
- ✅ 解耦合
- ✅ 易测试
- ✅ 灵活

---

### 4.3 测试框架

**技术方案**: PHPUnit

**phpunit.xml**:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.5/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         verbose="true"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">app</directory>
        </include>
        <exclude>
            <directory>vendor</directory>
        </exclude>
    </coverage>
</phpunit>
```

**测试示例**:
```php
// tests/Unit/Services/InventoryServiceTest.php
<?php
namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\InventoryService;
use App\Services\CacheService;

class InventoryServiceTest extends TestCase {
    private InventoryService $service;
    
    protected function setUp(): void {
        parent::setUp();
        
        $cacheMock = $this->createMock(CacheService::class);
        $this->service = new InventoryService($cacheMock);
    }
    
    public function testCreateSession() {
        $result = $this->service->createSession(1, [
            'items' => [
                [
                    'sku' => '6901234567890',
                    'name' => '测试商品',
                    'batches' => [
                        ['expiry_date' => '2026-12-31', 'quantity' => 100]
                    ]
                ]
            ]
        ]);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('session_key', $result);
    }
    
    public function testAddBatchWithInvalidData() {
        $this->expectException(\InvalidArgumentException::class);
        
        $this->service->addBatch('S123', [
            'sku' => '' // 空SKU
        ]);
    }
}
```

---

## 5. 部署运维选型

### 5.1 服务器环境

**推荐配置**:
- CPU: 2核
- 内存: 4GB
- 硬盘: 40GB SSD
- 操作系统: Ubuntu 22.04 LTS

**软件栈**:
- PHP: 8.1+
- MySQL: 8.0+
- Redis: 7.0+
- Nginx: 1.24+

---

### 5.2 监控告警

**技术方案**: Prometheus + Grafana

**优点**:
- ✅ 开源免费
- ✅ 功能强大
- ✅ 社区活跃

---

### 5.3 日志管理

**技术方案**: Monolog

**安装**:
```bash
composer require monolog/monolog
```

**实现**:
```php
// app/Services/LoggerService.php
<?php
namespace App\Services;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

class LoggerService {
    private Logger $logger;
    
    public function __construct() {
        $this->logger = new Logger('expiry');
        
        // 日志文件（按日期轮转）
        $this->logger->pushHandler(
            new RotatingFileHandler(
                __DIR__ . '/../../storage/logs/app.log',
                30, // 保留30天
                Logger::DEBUG
            )
        );
        
        // 错误日志单独记录
        $this->logger->pushHandler(
            new RotatingFileHandler(
                __DIR__ . '/../../storage/logs/error.log',
                60, // 保留60天
                Logger::ERROR
            )
        );
    }
    
    public function debug(string $message, array $context = []): void {
        $this->logger->debug($message, $context);
    }
    
    public function info(string $message, array $context = []): void {
        $this->logger->info($message, $context);
    }
    
    public function warning(string $message, array $context = []): void {
        $this->logger->warning($message, $context);
    }
    
    public function error(string $message, array $context = []): void {
        $this->logger->error($message, $context);
    }
}
```

---

## 总结

### 技术栈汇总

| 类别 | 技术选型 | 版本 |
|------|---------|------|
| **语言** | PHP | 8.1+ |
| **Web服务器** | Nginx | 1.24+ |
| **数据库** | MySQL | 8.0+ |
| **缓存** | Redis | 7.0+ |
| **依赖管理** | Composer | 2.x |
| **代码规范** | PSR-12 | - |
| **Excel** | PhpSpreadsheet | 1.x |
| **PDF** | TCPDF | 6.x |
| **CSV** | League CSV | 9.x |
| **测试** | PHPUnit | 9.x |
| **日志** | Monolog | 3.x |
| **环境变量** | vlucas/phpdotenv | 5.x |

### 成本估算

| 项目 | 月度成本 |
|------|---------|
| 服务器 (2C4G) | ¥200 |
| Redis | 已包含在服务器 |
| 域名 | ¥50/年 |
| SSL证书 | ¥0 (Let's Encrypt) |
| 监控 | ¥0 (自建) |
| **总计** | **~¥200/月** |

---

**文档结束**

*Created by The Architect (贾维斯)*
*Date: 2026-02-21*
*Version: 1.0*
