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

// 获取请求的endpoint
$endpoint = $_GET['endpoint'] ?? '';

// 特殊处理：categories endpoint 允许已登录用户访问（不需要API密钥）
if ($endpoint === 'categories') {
    session_start();
    if (!isset($_SESSION['user_id'])) {
        jsonResponse([
            'success' => false,
            'message' => '需要登录'
        ], 401);
    }
    // 已登录用户，跳过API密钥验证
    $keyInfo = ['id' => $_SESSION['user_id'], 'name' => 'internal_user'];
} else {
    // 其他endpoint需要API密钥
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
    'all' => 'getAllData'
];

if (!isset($allowedEndpoints[$endpoint])) {
    logApiAccess($keyInfo['id'], $endpoint, $_GET, 400);
    jsonResponse([
        'success' => false,
        'message' => '无效的endpoint',
        'available_endpoints' => array_keys($allowedEndpoints)
    ], 400);
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
        'categories' => $categories  // 前端期望的字段名
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
