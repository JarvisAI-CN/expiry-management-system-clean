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
header('Access-Control-Allow-Methods: GET, POST');
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
    'system.upgrade' => 'handleSystemUpgradeEndpoint'
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
    'system.upgrade' => 'system:upgrade'
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
