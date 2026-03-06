<?php
/**
 * 产品搜索API
 * 根据条形码或产品名称搜索产品信息
 */

header('Content-Type: application/json');

// 引入必要的类文件和函数库
require_once '../includes/functions.php';
require_once '../core/Database.php';
require_once '../core/AuthService.php';

// 加载数据库配置
$config = include '../config/database.php';

// 创建数据库连接
$database = new Database($config);
$pdo = $database->getConnection();

// 创建鉴权服务
$authConfig = [
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => isset($_SERVER['HTTPS'])
];

$authService = new AuthService($pdo, $authConfig);

// 检查用户登录状态
if (!$authService->isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => '用户未登录'
    ]);
    exit;
}

// 获取搜索参数
$barcode = $_GET['barcode'] ?? '';
$searchTerm = $_GET['search'] ?? '';

// 验证参数
if (empty($barcode) && empty($searchTerm)) {
    echo json_encode([
        'success' => false,
        'message' => '请提供搜索参数'
    ]);
    exit;
}

try {
    $query = "
        SELECT 
            p.id,
            p.name,
            p.description,
            p.category_id,
            c.name as category_name,
            p.sku,
            p.barcode,
            p.expiry_days,
            p.minimum_stock,
            p.price
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE 1=1
    ";
    
    $params = [];
    
    // 根据条形码搜索
    if (!empty($barcode)) {
        $query .= " AND (p.barcode LIKE ? OR p.sku LIKE ?)";
        $params[] = "%{$barcode}%";
        $params[] = "%{$barcode}%";
    }
    
    // 根据搜索术语搜索
    if (!empty($searchTerm)) {
        $query .= " AND (p.name LIKE ? OR p.description LIKE ? OR p.sku LIKE ?)";
        $params[] = "%{$searchTerm}%";
        $params[] = "%{$searchTerm}%";
        $params[] = "%{$searchTerm}%";
    }
    
    $query .= " ORDER BY p.name ASC LIMIT 5";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 如果找到产品，返回第一个产品信息
    if (!empty($products)) {
        $product = $products[0];
        
        // 格式化产品信息
        $response = [
            'success' => true,
            'product' => [
                'id' => $product['id'],
                'name' => $product['name'],
                'description' => $product['description'],
                'category_id' => $product['category_id'],
                'category_name' => $product['category_name'],
                'sku' => $product['sku'],
                'barcode' => $product['barcode'],
                'expiry_days' => $product['expiry_days'],
                'minimum_stock' => $product['minimum_stock'],
                'price' => $product['price']
            ]
        ];
    } else {
        $response = [
            'success' => false,
            'message' => '未找到对应的产品'
        ];
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '服务器错误: ' . $e->getMessage()
    ]);
    exit;
}
