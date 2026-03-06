<?php
declare(strict_types=1);

/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 盘点数据导出API（安全增强版）
 * 功能：导出盘点数据为CSV文件
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-03-06
 * 安全改进：POST+CSRF、CSV注入防护、流式写入、权限强化
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../core/Database.php';
require_once '../core/AuthService.php';

$config = include '../config/database.php';
$database = new Database($config);
$pdo = $database->getConnection();

$authService = new AuthService($pdo, [
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
]);

function jsonResponse(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitizeCsvCell($value): string {
    $str = (string)($value ?? '');
    // 防 CSV/Excel 公式注入
    if ($str !== '' && preg_match('/^[=\-+@]/', $str)) {
        $str = "'" . $str;
    }
    return $str;
}

// 仅允许 POST，避免被简单链接触发
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

// CSRF 校验（前端需传 X-CSRF-Token）
$csrfFromHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$csrfFromSession = $_SESSION['csrf_token'] ?? '';
if (!$csrfFromHeader || !$csrfFromSession || !hash_equals($csrfFromSession, $csrfFromHeader)) {
    jsonResponse(['success' => false, 'message' => 'CSRF校验失败'], 403);
}

if (!$authService->isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => '请先登录'], 401);
}
if (!$authService->isAdmin()) {
    jsonResponse(['success' => false, 'message' => '权限不足，仅管理员可导出数据'], 403);
}

/**
 * 导出频率限制检查
 * 
 * 防止用户过于频繁地导出数据，避免：
 * - 服务器资源耗尽
 * - 磁盘空间被大量占用
 * - 恶意下载攻击
 * 
 * 规则：
 * - 每小时最多10次导出
 * - 使用SESSION存储计数器
 * - 每小时自动重置
 * 
 * @return void
 */
function checkExportRateLimit(): void {
    // 基于当前小时的key（每小时自动重置）
    $key = 'export_count_' . date('YmdH');
    
    // 初始化计数器
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = 0;
    }
    
    // 检查是否超过限制
    $maxExportsPerHour = 10;
    if ($_SESSION[$key] >= $maxExportsPerHour) {
        // 计算下次可导出时间
        $nextHour = strtotime(date('Y-m-d H:00:00') . '+1 hour');
        $waitMinutes = ceil(($nextHour - time()) / 60);
        
        jsonResponse([
            'success' => false,
            'message' => "导出过于频繁，每小时最多{$maxExportsPerHour}次",
            'data' => [
                'limit' => $maxExportsPerHour,
                'current' => $_SESSION[$key],
                'retry_after' => $waitMinutes * 60,
                'retry_after_minutes' => $waitMinutes
            ]
        ], 429); // HTTP 429 Too Many Requests
    }
    
    // 增加计数
    $_SESSION[$key]++;
}

// 执行频率限制检查
checkExportRateLimit();

// 参数校验
$sessionId = filter_input(INPUT_POST, 'session_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$sessionId) {
    jsonResponse(['success' => false, 'message' => '缺少或非法盘点单ID'], 400);
}

try {
    // 先校验盘点单状态
    $checkStmt = $pdo->prepare("SELECT session_code, status FROM stocktake_sessions WHERE id = :id LIMIT 1");
    $checkStmt->execute([':id' => $sessionId]);
    $sessionInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$sessionInfo) {
        jsonResponse(['success' => false, 'message' => '盘点单不存在'], 404);
    }
    if (($sessionInfo['status'] ?? '') !== 'completed') {
        jsonResponse(['success' => false, 'message' => '仅可导出已完成的盘点单'], 400);
    }

    // 文件准备
    $safeSessionCode = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$sessionInfo['session_code']);
    $filename = 'stocktake_' . $safeSessionCode . '_' . date('YmdHis') . '.csv';
    $exportDir = dirname(__DIR__) . '/exports';

    if (!is_dir($exportDir) && !mkdir($exportDir, 0700, true) && !is_dir($exportDir)) {
        throw new RuntimeException('无法创建导出目录');
    }

    $tempFile = $exportDir . '/' . $filename;
    $fp = fopen($tempFile, 'wb');
    if ($fp === false) {
        throw new RuntimeException('无法创建导出文件');
    }

    // UTF-8 BOM
    fwrite($fp, "\xEF\xBB\xBF");

    // 表头
    fputcsv($fp, [
        '盘点单号','盘点描述','状态','创建时间','SKU','商品名称','公司分类',
        '系统分类','数量','效期','批号','备注','提前报废天数','提前下架天数'
    ]);

    // 流式查询/写入，避免 fetchAll 占内存
    $sql = "
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
        INNER JOIN stocktake_entries se ON si.id = se.session_id
        LEFT JOIN products p ON se.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE si.id = :session_id
        ORDER BY se.expiry_date ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':session_id' => $sessionId]);

    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($fp, [
            sanitizeCsvCell($row['session_code'] ?? ''),
            sanitizeCsvCell($row['description'] ?? ''),
            sanitizeCsvCell($row['status'] ?? ''),
            sanitizeCsvCell($row['created_at'] ?? ''),
            sanitizeCsvCell($row['sku'] ?? ''),
            sanitizeCsvCell($row['product_name'] ?? ''),
            sanitizeCsvCell($row['company_category'] ?? ''),
            sanitizeCsvCell($row['category_name'] ?? ''),
            (int)($row['quantity'] ?? 0),
            sanitizeCsvCell($row['expiry_date'] ?? ''),
            sanitizeCsvCell($row['batch_number'] ?? ''),
            sanitizeCsvCell($row['notes'] ?? ''),
            (int)($row['advance_scrap_days'] ?? 0),
            (int)($row['advance_offline_days'] ?? 0),
        ]);
        $count++;
    }

    fclose($fp);

    if ($count === 0) {
        @unlink($tempFile);
        jsonResponse(['success' => false, 'message' => '未找到可导出的盘点明细'], 404);
    }

    jsonResponse([
        'success' => true,
        'message' => '导出成功',
        'data' => [
            'filename' => $filename,
            'download_url' => '/exports/' . rawurlencode($filename),
            'record_count' => $count,
            'file_size' => filesize($tempFile) ?: 0
        ]
    ]);

} catch (Throwable $e) {
    error_log('[export_stocktake] ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => '导出失败，请联系管理员'], 500);
}
