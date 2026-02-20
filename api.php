<?php
/**
 * ========================================
 * 保质期管理系统 - API数据接口
 * 文件名: api.php
 * 版本: v1.0.0
 * ========================================
 */

require_once __DIR__ . '/db.php';

// 设置JSON响应头
header('Content-Type: application/json; charset=utf-8');

// CORS支持（如果需要）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

// 处理OPTIONS预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * 验证API密钥
 */
function validateApiKey($apiKey) {
    $conn = getDBConnection();
    if (!$conn) {
        return false;
    }

    $apiKeyHash = hash('sha256', $apiKey);

    $stmt = $conn->prepare("SELECT id, name, is_active, scopes, expires_at FROM api_keys WHERE api_key_hash = ? AND is_active = 1");
    $stmt->bind_param("s", $apiKeyHash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // 检查是否已过期
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            return false;
        }

        // 更新最后使用时间
        $updateStmt = $conn->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?");
        $updateStmt->bind_param("i", $row['id']);
        $updateStmt->execute();

        return $row;
    }

    return false;
}

/**
 * 记录API访问日志
 */
function logApiAccess($keyId, $endpoint, $params = null, $statusCode = 200) {
    $conn = getDBConnection();
    if (!$conn) return;

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $paramsJson = $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null;

    $stmt = $conn->prepare("INSERT INTO api_logs (api_key_id, endpoint, request_params, response_code, ip_address) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issis", $keyId, $endpoint, $paramsJson, $statusCode, $ip);
    $stmt->execute();
}

/**
 * 获取请求头中的API密钥
 */
function getApiKeyFromHeader() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';

    if (preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
        return $matches[1];
    }

    // 也支持从GET参数获取（用于测试）
    return $_GET['api_key'] ?? '';
}

// ========================================
// 主程序
// ========================================

// 获取API密钥
$apiKey = getApiKeyFromHeader();

if (empty($apiKey)) {
    jsonResponse([
        'success' => false,
        'message' => '缺少API密钥'
    ], 401);
}

// 验证API密钥
$keyInfo = validateApiKey($apiKey);

if (!$keyInfo) {
    jsonResponse([
        'success' => false,
        'message' => 'API密钥无效或已禁用'
    ], 403);
}

// 获取请求的endpoint
$endpoint = $_GET['endpoint'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// 支持的endpoint列表
$allowedEndpoints = [
    'products' => 'getProductsData',
    'batches' => 'getBatchesData',
    'expiring' => 'getExpiringData',
    'summary' => 'getSummaryData',
    'categories' => 'getCategoriesData',
    'all' => 'getAllData',
    // v2.9.0 新增 REST 接口
    'inventories' => 'getInventoriesData',
    'items' => 'getItemsData',
    'system.upgrade' => 'handleSystemUpgradeEndpoint',
    'system.update' => 'handleSystemUpdateEndpoint',

    // v2.9.3 写接口（AI 管理）
    'categories.upsert' => 'handleCategoriesUpsert',
    'categories.delete' => 'handleCategoriesDelete',
    'products.upsert' => 'handleProductsUpsert',
    'products.delete' => 'handleProductsDelete',
];

// endpoint 所需的最小 scope
$endpointScopes = [
    'products' => 'read:products',
    'batches' => 'read:batches',
    'expiring' => 'read:expiring',
    'summary' => 'read:summary',
    'categories' => 'read:categories',
    'all' => 'read:all',
    'inventories' => 'read:inventories',
    'items' => 'read:items',
    'system.upgrade' => 'system:upgrade',
    'system.update' => 'system:update',

    // v2.9.3 写接口
    'categories.upsert' => 'write:categories',
    'categories.delete' => 'write:categories',
    'products.upsert' => 'write:products',
    'products.delete' => 'write:products',
];

if (!isset($allowedEndpoints[$endpoint])) {
    logApiAccess($keyInfo['id'], $endpoint, $_GET, 400);
    jsonResponse([
        'success' => false,
        'message' => '无效的endpoint',
        'available_endpoints' => array_keys($allowedEndpoints)
    ], 400);
}

// 检查 scope 权限
$requiredScope = $endpointScopes[$endpoint] ?? null;
if ($requiredScope && !apiKeyHasScope($keyInfo, $requiredScope)) {
    logApiAccess($keyInfo['id'], $endpoint, $_GET, 403);
    jsonResponse([
        'success' => false,
        'message' => '当前API密钥权限不足',
        'required_scope' => $requiredScope
    ], 403);
}

// 调用对应的处理函数
$handlerFunction = $allowedEndpoints[$endpoint];

try {
    $result = $handlerFunction();
    logApiAccess($keyInfo['id'], $endpoint, $_GET, 200);
    jsonResponse($result);
} catch (Exception $e) {
    logApiAccess($keyInfo['id'], $endpoint, $_GET, 500);
    jsonResponse([
        'success' => false,
        'message' => '服务器错误: ' . $e->getMessage()
    ], 500);
}

// ========================================
// 辅助函数 - Scope 检查
// ========================================

/**
 * 当前 API Key 是否拥有指定 scope
 */
function apiKeyHasScope(array $keyInfo, string $requiredScope): bool {
    // 没有 scopes 字段时视为只读全开
    $scopesStr = trim($keyInfo['scopes'] ?? 'read:all');
    if ($scopesStr === '') {
        $scopesStr = 'read:all';
    }

    $scopes = array_filter(array_map('trim', explode(',', $scopesStr)));

    // admin 拥有全部权限
    if (in_array('admin', $scopes, true)) {
        return true;
    }

    // read:all 赋予所有只读 endpoint 权限
    if (strpos($requiredScope, 'read:') === 0 && in_array('read:all', $scopes, true)) {
        return true;
    }

    return in_array($requiredScope, $scopes, true);
}

// ========================================
// 数据处理函数
// ========================================

/**
 * 获取所有产品数据
 */
function getProductsData() {
    $conn = getDBConnection();
    if (!$conn) {
        throw new Exception('数据库连接失败');
    }

    $sql = "SELECT p.*, c.name as category_name, c.type as category_type
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            ORDER BY p.id";

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception('查询失败: ' . $conn->error);
    }

    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }

    return [
        'success' => true,
        'endpoint' => 'products',
        'count' => count($products),
        'data' => $products
    ];
}

/**
 * 获取所有批次数据
 */
function getBatchesData() {
    $conn = getDBConnection();
    if (!$conn) {
        throw new Exception('数据库连接失败');
    }

    $sql = "SELECT b.*, p.sku, p.name as product_name, c.name as category_name
            FROM batches b
            JOIN products p ON b.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            ORDER BY b.expiry_date ASC";

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception('查询失败: ' . $conn->error);
    }

    $batches = [];
    while ($row = $result->fetch_assoc()) {
        // 计算剩余天数
        $expiryDate = new DateTime($row['expiry_date']);
        $today = new DateTime();
        $interval = $today->diff($expiryDate);
        $row['days_remaining'] = $interval->format('%r%a'); // %r表示负数（已过期）

        // 计算状态
        if ($interval->invert) {
            $row['status'] = 'expired';
        } elseif ($interval->days <= 7) {
            $row['status'] = 'critical';
        } elseif ($interval->days <= 30) {
            $row['status'] = 'warning';
        } else {
            $row['status'] = 'normal';
        }

        $batches[] = $row;
    }

    return [
        'success' => true,
        'endpoint' => 'batches',
        'count' => count($batches),
        'data' => $batches
    ];
}

/**
 * 获取即将过期的产品
 */
function getExpiringData() {
    $conn = getDBConnection();
    if (!$conn) {
        throw new Exception('数据库连接失败');
    }

    $days = intval($_GET['days'] ?? 30); // 默认30天

    $sql = "SELECT b.*, p.sku, p.name as product_name, p.removal_buffer,
                   c.name as category_name, c.type as category_type,
                   DATEDIFF(b.expiry_date, CURDATE()) as days_remaining
            FROM batches b
            JOIN products p ON b.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
            ORDER BY b.expiry_date ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result) {
        throw new Exception('查询失败: ' . $conn->error);
    }

    $expiring = [];
    while ($row = $result->fetch_assoc()) {
        // 计算状态
        $daysRemaining = intval($row['days_remaining']);
        if ($daysRemaining < 0) {
            $row['status'] = 'expired';
        } elseif ($daysRemaining <= 7) {
            $row['status'] = 'critical';
        } elseif ($daysRemaining <= 15) {
            $row['status'] = 'warning';
        } else {
            $row['status'] = 'attention';
        }

        $expiring[] = $row;
    }

    return [
        'success' => true,
        'endpoint' => 'expiring',
        'days_threshold' => $days,
        'count' => count($expiring),
        'data' => $expiring
    ];
}

/**
 * 获取汇总统计数据
 */
function getSummaryData() {
    $conn = getDBConnection();
    if (!$conn) {
        throw new Exception('数据库连接失败');
    }

    // 总商品数
    $totalProducts = $conn->query("SELECT COUNT(*) as count FROM products")->fetch_assoc()['count'];

    // 总批次数
    $totalBatches = $conn->query("SELECT COUNT(*) as count FROM batches")->fetch_assoc()['count'];

    // 已过期
    $expiredCount = $conn->query("SELECT COUNT(*) as count FROM batches WHERE expiry_date < CURDATE()")->fetch_assoc()['count'];

    // 7天内过期
    $criticalCount = $conn->query("SELECT COUNT(*) as count FROM batches WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['count'];

    // 30天内过期
    $warningCount = $conn->query("SELECT COUNT(*) as count FROM batches WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetch_assoc()['count'];

    // 总库存
    $totalStock = $conn->query("SELECT SUM(quantity) as count FROM batches")->fetch_assoc()['count'] ?? 0;

    // 分类统计
    $categoryStats = [];
    $catResult = $conn->query("SELECT c.name, COUNT(DISTINCT p.id) as product_count
                               FROM categories c
                               LEFT JOIN products p ON c.id = p.category_id
                               GROUP BY c.id, c.name");
    while ($row = $catResult->fetch_assoc()) {
        $categoryStats[] = $row;
    }

    return [
        'success' => true,
        'endpoint' => 'summary',
        'generated_at' => date('Y-m-d H:i:s'),
        'statistics' => [
            'total_products' => intval($totalProducts),
            'total_batches' => intval($totalBatches),
            'total_stock' => intval($totalStock),
            'expired' => intval($expiredCount),
            'critical' => intval($criticalCount), // 7天内
            'warning' => intval($warningCount),  // 30天内
        ],
        'category_stats' => $categoryStats
    ];
}

/**
 * 获取分类数据
 */
function getCategoriesData() {
    $conn = getDBConnection();
    if (!$conn) {
        throw new Exception('数据库连接失败');
    }

    $sql = "SELECT c.*, COUNT(p.id) as product_count
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id
            GROUP BY c.id
            ORDER BY c.id";

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception('查询失败: ' . $conn->error);
    }

    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }

    return [
        'success' => true,
        'endpoint' => 'categories',
        'count' => count($categories),
        'data' => $categories
    ];
}

/**
 * 获取所有数据（完整导出）
 */
function getAllData() {
    $conn = getDBConnection();
    if (!$conn) {
        throw new Exception('数据库连接失败');
    }

    return [
        'success' => true,
        'endpoint' => 'all',
        'generated_at' => date('Y-m-d H:i:s'),
        'products' => getProductsData()['data'],
        'batches' => getBatchesData()['data'],
        'categories' => getCategoriesData()['data'],
        'summary' => getSummaryData()['statistics']
    ];
}

/**
 * v2.9.0 - 获取盘点会话列表
 * endpoint: inventories
 */
function getInventoriesData() {
    $conn = getDBConnection();
    if (!$conn) {
        throw new Exception('数据库连接失败');
    }

    $limit = intval($_GET['limit'] ?? 50);
    if ($limit <= 0 || $limit > 200) {
        $limit = 50;
    }

    $sql = "SELECT id, session_key, user_id, username, item_count, created_at
            FROM inventory_sessions
            ORDER BY created_at DESC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $sessions = [];
    while ($row = $result->fetch_assoc()) {
        $sessions[] = $row;
    }

    return [
        'success' => true,
        'endpoint' => 'inventories',
        'count' => count($sessions),
        'data' => $sessions
    ];
}

/**
 * v2.9.0 - 获取盘点明细 / 当前库存
 * endpoint: items
 *
 * 用法：
 *   - 按盘点会话查询:  items?session_key=xxx
 *   - 按SKU聚合库存:   items?mode=stock
 */
function getItemsData() {
    $conn = getDBConnection();
    if (!$conn) {
        throw new Exception('数据库连接失败');
    }

    // 1) 按盘点会话查询明细
    $sessionKey = $_GET['session_key'] ?? ($_GET['session_id'] ?? '');
    if (!empty($sessionKey)) {
        $sql = "SELECT p.sku, p.name, b.expiry_date, b.quantity, p.removal_buffer
                FROM batches b
                JOIN products p ON b.product_id = p.id
                WHERE b.session_id = ?
                ORDER BY DATE_SUB(b.expiry_date, INTERVAL p.removal_buffer DAY) ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $sessionKey);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }

        return [
            'success' => true,
            'endpoint' => 'items',
            'mode' => 'session',
            'session_key' => $sessionKey,
            'count' => count($items),
            'data' => $items
        ];
    }

    // 2) 默认：按SKU聚合当前库存
    $sql = "SELECT p.id, p.sku, p.name,
                   COALESCE(SUM(b.quantity), 0) AS total_quantity,
                   MIN(b.expiry_date) AS nearest_expiry
            FROM products p
            LEFT JOIN batches b ON p.id = b.product_id
            GROUP BY p.id, p.sku, p.name
            ORDER BY p.id ASC";

    $result = $conn->query($sql);
    if (!$result) {
        throw new Exception('查询失败: ' . $conn->error);
    }

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $row['total_quantity'] = (int)($row['total_quantity'] ?? 0);
        $items[] = $row;
    }

    return [
        'success' => true,
        'endpoint' => 'items',
        'mode' => 'stock',
        'count' => count($items),
        'data' => $items
    ];
}

/**
 * v2.9.0 - 系统升级接口封装
 * endpoint: system.upgrade
 *
 * GET  -> 返回升级状态
 * POST -> 执行升级（调用 upgrade_v2.9.0.php）
 */
function handleSystemUpgradeEndpoint() {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // 当前版本
    $currentVersion = 'unknown';
    $versionFile = __DIR__ . '/VERSION.txt';
    if (is_readable($versionFile)) {
        $currentVersion = trim(file_get_contents($versionFile));
    }

    if ($method !== 'POST') {
        // 仅返回基础状态信息
        return [
            'success' => true,
            'endpoint' => 'system.upgrade',
            'mode' => 'status',
            'current_version' => $currentVersion,
            'target_version' => '2.9.0'
        ];
    }

    // POST: 执行升级脚本
    require_once __DIR__ . '/upgrade_v2.9.0.php';
    if (function_exists('run_upgrade_v2_9_0')) {
        $result = run_upgrade_v2_9_0(true);
        $result['endpoint'] = 'system.upgrade';
        $result['mode'] = 'execute';
        return $result;
    }

    return [
        'success' => false,
        'endpoint' => 'system.upgrade',
        'mode' => 'execute',
        'message' => '升级脚本不存在或不可用'
    ];
}



// ========================================
// v2.9.3 写接口（AI 管理）
// ========================================

function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function requireMethod(string $method) {
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        throw new Exception('HTTP method not allowed');
    }
}

function normalizeInt($v, $default = 0): int {
    if ($v === null || $v === '') return (int)$default;
    return (int)$v;
}

function normalizeStr($v, $maxLen = 255): string {
    $s = trim((string)$v);
    if (mb_strlen($s, 'UTF-8') > $maxLen) {
        $s = mb_substr($s, 0, $maxLen, 'UTF-8');
    }
    return $s;
}

/**
 * POST /api.php?endpoint=categories.upsert
 * Body: { name, type, rule }
 */
function handleCategoriesUpsert() {
    requireMethod('POST');
    $conn = getDBConnection();
    if (!$conn) throw new Exception('数据库连接失败');

    $data = readJsonBody();
    $name = normalizeStr($data['name'] ?? '', 50);
    $type = normalizeStr($data['type'] ?? '', 20);
    $rule = $data['rule'] ?? null;

    if ($name === '' || $type === '') {
        throw new Exception('缺少必填字段 name/type');
    }

    if ($rule !== null && !is_string($rule)) {
        // allow object/array rule and serialize
        $rule = json_encode($rule, JSON_UNESCAPED_UNICODE);
    }

    $stmt = $conn->prepare("INSERT INTO categories (name, type, rule) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE type=VALUES(type), rule=VALUES(rule)");
    $stmt->bind_param('sss', $name, $type, $rule);
    $ok = $stmt->execute();

    if (!$ok) {
        throw new Exception('写入失败: ' . $conn->error);
    }

    return ['success' => true, 'endpoint' => 'categories.upsert', 'name' => $name];
}

/**
 * POST /api.php?endpoint=categories.delete
 * Body: { id?, name?, force? }
 * - If referenced by products, reject unless force=true; then set products.category_id=0
 */
function handleCategoriesDelete() {
    requireMethod('POST');
    $conn = getDBConnection();
    if (!$conn) throw new Exception('数据库连接失败');

    $data = readJsonBody();
    $id = normalizeInt($data['id'] ?? 0, 0);
    $name = normalizeStr($data['name'] ?? '', 50);
    $force = (bool)($data['force'] ?? false);

    if ($id <= 0 && $name === '') {
        throw new Exception('必须提供 id 或 name');
    }

    if ($id <= 0) {
        $stmt = $conn->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        if (!$row) throw new Exception('分类不存在');
        $id = (int)$row['id'];
    }

    $stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM products WHERE category_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $cnt = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

    if ($cnt > 0 && !$force) {
        return [
            'success' => false,
            'endpoint' => 'categories.delete',
            'message' => '该分类仍被商品引用，禁止删除。可先迁移或传 force=true 自动解除引用',
            'referenced_products' => $cnt,
        ];
    }

    $conn->begin_transaction();
    try {
        if ($cnt > 0 && $force) {
            $stmt = $conn->prepare('UPDATE products SET category_id = 0 WHERE category_id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
        }

        $stmt = $conn->prepare('DELETE FROM categories WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    return ['success' => true, 'endpoint' => 'categories.delete', 'id' => $id, 'detached_products' => $cnt];
}

/**
 * POST /api.php?endpoint=products.upsert
 * Body: { id?, sku, name, category_id?, removal_buffer?, inventory_cycle? }
 */
function handleProductsUpsert() {
    requireMethod('POST');
    $conn = getDBConnection();
    if (!$conn) throw new Exception('数据库连接失败');

    $data = readJsonBody();
    $id = normalizeInt($data['id'] ?? 0, 0);
    $sku = normalizeStr($data['sku'] ?? '', 100);
    $name = normalizeStr($data['name'] ?? '', 200);
    $categoryId = normalizeInt($data['category_id'] ?? 0, 0);
    $removalBuffer = normalizeInt($data['removal_buffer'] ?? 0, 0);
    $inventoryCycle = normalizeStr($data['inventory_cycle'] ?? 'none', 20);

    if ($sku === '' || $name === '') {
        throw new Exception('缺少必填字段 sku/name');
    }

    $allowedCycles = ['daily','weekly','monthly','yearly','none'];
    if (!in_array($inventoryCycle, $allowedCycles, true)) {
        $inventoryCycle = 'none';
    }

    if ($id > 0) {
        $stmt = $conn->prepare('UPDATE products SET sku=?, name=?, category_id=?, removal_buffer=?, inventory_cycle=? WHERE id=?');
        $stmt->bind_param('ssiisi', $sku, $name, $categoryId, $removalBuffer, $inventoryCycle, $id);
        $ok = $stmt->execute();
        if (!$ok) throw new Exception('更新失败: ' . $conn->error);
        return ['success'=>true,'endpoint'=>'products.upsert','mode'=>'update','id'=>$id];
    }

    $stmt = $conn->prepare('INSERT INTO products (sku, name, category_id, removal_buffer, inventory_cycle) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('ssiis', $sku, $name, $categoryId, $removalBuffer, $inventoryCycle);
    $ok = $stmt->execute();
    if (!$ok) throw new Exception('创建失败: ' . $conn->error);

    return ['success'=>true,'endpoint'=>'products.upsert','mode'=>'create','id'=>$conn->insert_id];
}

/**
 * POST /api.php?endpoint=products.delete
 * Body: { id? , sku? , force? }
 * - If batches exist, reject unless force=true (then delete product, batches cascade)
 */
function handleProductsDelete() {
    requireMethod('POST');
    $conn = getDBConnection();
    if (!$conn) throw new Exception('数据库连接失败');

    $data = readJsonBody();
    $id = normalizeInt($data['id'] ?? 0, 0);
    $sku = normalizeStr($data['sku'] ?? '', 100);
    $force = (bool)($data['force'] ?? false);

    if ($id <= 0 && $sku === '') {
        throw new Exception('必须提供 id 或 sku');
    }

    if ($id <= 0) {
        $stmt = $conn->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
        $stmt->bind_param('s', $sku);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) throw new Exception('商品不存在');
        $id = (int)$row['id'];
    }

    $stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM batches WHERE product_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $cnt = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

    if ($cnt > 0 && !$force) {
        return [
            'success'=>false,
            'endpoint'=>'products.delete',
            'message'=>'该商品存在批次数据，禁止删除。可传 force=true 强制删除（批次将级联删除）',
            'batches' => $cnt,
        ];
    }

    $stmt = $conn->prepare('DELETE FROM products WHERE id = ?');
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    if (!$ok) throw new Exception('删除失败: ' . $conn->error);

    return ['success'=>true,'endpoint'=>'products.delete','id'=>$id,'deleted_batches'=>$cnt];
}


// ========================================
// 高风险：通过 API 执行代码更新（仅管理员/特定 scope）
// ========================================

/**
 * POST /api.php?endpoint=system.update
 * Body(JSON): { "version": "v2.9.3" }  // 可选，默认 latest
 *
 * 行为：
 * - 仅允许从官方 GitHub Release 下载并覆盖当前目录（保留 config.php）
 * - 先打包备份当前目录到 /tmp
 *
 * 风险：拥有该权限的 key 一旦泄露，等同于远程改站点代码。
 */
function handleSystemUpdateEndpoint() {
    requireMethod('POST');

    $data = readJsonBody();
    $version = normalizeStr($data['version'] ?? 'latest', 32);

    // Allow-list: only our repo releases
    $repo = 'JarvisAI-CN/expiry-management-system-clean';

    // Build download URL
    // Prefer explicit version tag; allow 'latest'
    if ($version === '' || $version === 'latest') {
        $url = "https://github.com/{$repo}/releases/latest/download/expiry-system-latest.tar.gz";
        // We'll fallback to tag-based name if latest asset not present
    } else {
        // normalize like v2.9.3
        if (!preg_match('/^v\d+\.\d+\.\d+(?:\.\d+)?$/', $version)) {
            throw new Exception('version 格式非法，示例: v2.9.3');
        }
        $url = "https://github.com/{$repo}/releases/download/{$version}/expiry-system-{$version}.tar.gz";
    }

    $targetDir = realpath(__DIR__);
    if (!$targetDir) {
        throw new Exception('无法定位目标目录');
    }

    // permissions check
    if (!is_writable($targetDir)) {
        return [
            'success' => false,
            'endpoint' => 'system.update',
            'message' => '目标目录不可写（Web 用户无权限覆盖文件）。请用宝塔/SSH 提升目录权限或手动部署。',
            'target_dir' => $targetDir,
        ];
    }

    $tmpDir = sys_get_temp_dir();
    $ts = date('Ymd-His');
    $backupPath = $tmpDir . '/expiry-backup-' . $ts . '.tar.gz';
    $downloadPath = $tmpDir . '/expiry-update-' . $ts . '.tar.gz';
    $extractDir = $tmpDir . '/expiry-update-' . $ts;

    // 1) Backup current code (exclude runtime logs if present)
    $cmdBackup = 'tar -czf ' . escapeshellarg($backupPath) . ' -C ' . escapeshellarg($targetDir) . ' .';
    $out = []; $code = 0;
    exec($cmdBackup . ' 2>&1', $out, $code);
    if ($code !== 0) {
        throw new Exception('备份失败: ' . implode("\n", $out));
    }

    // 2) Download release tarball
    $cmdDl = 'curl -L --fail --max-time 60 -o ' . escapeshellarg($downloadPath) . ' ' . escapeshellarg($url);
    $out = []; $code = 0;
    exec($cmdDl . ' 2>&1', $out, $code);
    if ($code !== 0) {
        // fallback: if latest asset not found, try tag asset name used by our releases
        if ($version === 'latest') {
            // Try GitHub API to resolve latest tag + standard filename
            $api = "https://api.github.com/repos/{$repo}/releases/latest";
            $json = @file_get_contents($api);
            $tag = null;
            if ($json) {
                $r = json_decode($json, true);
                $tag = $r['tag_name'] ?? null;
            }
            if ($tag && preg_match('/^v\d+\.\d+\.\d+(?:\.\d+)?$/', $tag)) {
                $url2 = "https://github.com/{$repo}/releases/download/{$tag}/expiry-system-{$tag}.tar.gz";
                $cmdDl2 = 'curl -L --fail --max-time 60 -o ' . escapeshellarg($downloadPath) . ' ' . escapeshellarg($url2);
                $out2 = []; $code2 = 0;
                exec($cmdDl2 . ' 2>&1', $out2, $code2);
                if ($code2 !== 0) {
                    throw new Exception('下载失败: ' . implode("\n", $out) . "\n" . implode("\n", $out2));
                }
                $url = $url2;
                $version = $tag;
            } else {
                throw new Exception('下载失败: ' . implode("\n", $out));
            }
        } else {
            throw new Exception('下载失败: ' . implode("\n", $out));
        }
    }

    // size guard
    $size = filesize($downloadPath);
    if ($size === false || $size > 10 * 1024 * 1024) {
        throw new Exception('下载包大小异常，已中止');
    }

    // 3) Extract
    @mkdir($extractDir, 0777, true);
    $cmdEx = 'tar -xzf ' . escapeshellarg($downloadPath) . ' -C ' . escapeshellarg($extractDir);
    $out = []; $code = 0;
    exec($cmdEx . ' 2>&1', $out, $code);
    if ($code !== 0) {
        throw new Exception('解压失败: ' . implode("\n", $out));
    }

    // 4) Basic validation: must contain api.php + index.php
    if (!file_exists($extractDir . '/api.php') || !file_exists($extractDir . '/index.php')) {
        throw new Exception('包内容校验失败（缺少关键文件），已中止');
    }

    // 5) Copy over (preserve config.php)
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($extractDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $rel = substr($item->getPathname(), strlen($extractDir) + 1);
        if ($rel === 'config.php') {
            continue; // keep local config
        }
        $dest = $targetDir . DIRECTORY_SEPARATOR . $rel;

        if ($item->isDir()) {
            if (!is_dir($dest)) {
                @mkdir($dest, 0777, true);
            }
        } else {
            @copy($item->getPathname(), $dest);
        }
    }

    return [
        'success' => true,
        'endpoint' => 'system.update',
        'version' => $version,
        'download_url' => $url,
        'backup_path' => $backupPath,
        'message' => '更新完成（已覆盖代码，保留 config.php）。如出现异常可用 backup_path 回滚。'
    ];
}
