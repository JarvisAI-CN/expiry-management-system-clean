<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 盘点数据导出API
 * 功能：导出盘点数据为Excel文件
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-03-06
 */

session_start();
header('Content-Type: application/json');

// 引入必要的类文件
require_once '../core/Database.php';
require_once '../core/AuthService.php';

// 加载数据库配置
$config = include '../config/database.php';

// 创建数据库连接
$database = new Database($config);
$pdo = $database->getConnection();

// 创建鉴权服务
$authService = new AuthService($pdo, [
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => isset($_SERVER['HTTPS'])
]);

// 检查用户登录状态
if (!$authService->isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => '请先登录'
    ]);
    exit;
}

// 检查是否是管理员
if (!$authService->isAdmin()) {
    echo json_encode([
        'success' => false,
        'message' => '权限不足，仅管理员可导出数据'
    ]);
    exit;
}

// 获取请求参数
$session_id = $_GET['session_id'] ?? null;

if (!$session_id) {
    echo json_encode([
        'success' => false,
        'message' => '缺少盘点单ID'
    ]);
    exit;
}

try {
    // 查询盘点数据
    $stmt = $pdo->prepare("
        SELECT
            si.session_code,
            si.description,
            si.status,
            si.created_at,
            p.product_name,
            p.sku,
            p.company_category,
            se.quantity,
            se.expiry_date,
            se.batch_number,
            se.notes,
            c.category_name,
            c.advance_scrap_days,
            c.advance_offline_days
        FROM stocktake_sessions si
        LEFT JOIN stocktake_entries se ON si.id = se.session_id
        LEFT JOIN products p ON se.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE si.id = ?
        ORDER BY se.expiry_date ASC
    ");

    $stmt->execute([$session_id]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($data)) {
        echo json_encode([
            'success' => false,
            'message' => '未找到盘点数据'
        ]);
        exit;
    }

    // 生成CSV数据
    $csvContent = "\xEF\xBB\xBF"; // UTF-8 BOM

    // CSV表头
    $csvContent .= "盘点单号,盘点描述,状态,创建时间,SKU,商品名称,公司分类,系统分类,数量,效期,批号,备注,提前报废天数,提前下架天数\n";

    // CSV数据行
    foreach ($data as $row) {
        $csvContent .= sprintf(
            "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
            $row['session_code'] ?? '',
            $row['description'] ?? '',
            $row['status'] ?? '',
            $row['created_at'] ?? '',
            $row['sku'] ?? '',
            $row['product_name'] ?? '',
            $row['company_category'] ?? '',
            $row['category_name'] ?? '',
            $row['quantity'] ?? 0,
            $row['expiry_date'] ?? '',
            $row['batch_number'] ?? '',
            $row['notes'] ?? '',
            $row['advance_scrap_days'] ?? 0,
            $row['advance_offline_days'] ?? 0
        );
    }

    // 生成文件名
    $filename = 'stocktake_' . $data[0]['session_code'] . '_' . date('YmdHis') . '.csv';

    // 保存到临时文件
    $tempFile = '../exports/' . $filename;
    if (!is_dir('../exports')) {
        mkdir('../exports', 0755, true);
    }

    file_put_contents($tempFile, $csvContent);

    // 返回下载链接
    echo json_encode([
        'success' => true,
        'message' => '导出成功',
        'data' => [
            'filename' => $filename,
            'download_url' => '/exports/' . $filename,
            'record_count' => count($data),
            'file_size' => filesize($tempFile)
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '导出失败：' . $e->getMessage()
    ]);
}
