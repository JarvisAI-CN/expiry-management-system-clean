<?php
/**
 * ========================================
 * 保质期管理系统 - 邮箱API接口
 * 文件名: email_api.php
 * 版本: v1.0.0
 * ========================================
 */

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_functions.php';

// 设置JSON响应头
header('Content-Type: application/json; charset=utf-8');

// CORS支持
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// 处理OPTIONS预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * 权限检查
 */
function checkEmailAuth() {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse([
            'success' => false,
            'message' => '需要管理员权限'
        ], 401);
    }
}

/**
 * 解析请求体
 */
function getJsonInput() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    return $data ?: [];
}

// ========================================
// 获取数据库连接
// ========================================
$conn = getDBConnection();
if (!$conn) {
    jsonResponse([
        'success' => false,
        'message' => '数据库连接失败'
    ], 500);
}

// ========================================
// 路由分发
// ========================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ========================================
// 1. 添加邮箱账户
// ========================================
if ($action === 'add_account' && $method === 'POST') {
    checkEmailAuth();
    
    $data = getJsonInput();
    $qqNumber = trim($data['qq_number'] ?? '');
    $authCode = trim($data['auth_code'] ?? '');
    $priority = (int)($data['priority'] ?? 0);
    
    if (empty($qqNumber) || empty($authCode)) {
        jsonResponse([
            'success' => false,
            'message' => 'QQ号和授权码不能为空'
        ], 400);
    }
    
    $result = addEmailAccount($conn, $qqNumber, $authCode, $priority);
    jsonResponse($result, $result['success'] ? 200 : 400);
}

// ========================================
// 2. 列出所有邮箱账户
// ========================================
elseif ($action === 'list_accounts' && $method === 'GET') {
    checkEmailAuth();
    
    $result = listEmailAccounts($conn);
    jsonResponse($result);
}

// ========================================
// 3. 更新邮箱账户
// ========================================
elseif ($action === 'update_account' && $method === 'POST') {
    checkEmailAuth();
    
    $data = getJsonInput();
    $id = (int)($data['id'] ?? 0);
    
    if ($id <= 0) {
        jsonResponse([
            'success' => false,
            'message' => '无效的账户ID'
        ], 400);
    }
    
    $updates = [];
    if (isset($data['auth_code'])) {
        $updates['auth_code'] = trim($data['auth_code']);
    }
    if (isset($data['is_active'])) {
        $updates['is_active'] = (bool)$data['is_active'];
    }
    if (isset($data['priority'])) {
        $updates['priority'] = (int)$data['priority'];
    }
    
    if (empty($updates)) {
        jsonResponse([
            'success' => false,
            'message' => '没有要更新的字段'
        ], 400);
    }
    
    $result = updateEmailAccount($conn, $id, $updates);
    jsonResponse($result, $result['success'] ? 200 : 400);
}

// ========================================
// 4. 删除邮箱账户
// ========================================
elseif ($action === 'delete_account' && $method === 'POST') {
    checkEmailAuth();
    
    $data = getJsonInput();
    $id = (int)($data['id'] ?? 0);
    
    if ($id <= 0) {
        jsonResponse([
            'success' => false,
            'message' => '无效的账户ID'
        ], 400);
    }
    
    $result = deleteEmailAccount($conn, $id);
    jsonResponse($result, $result['success'] ? 200 : 400);
}

// ========================================
// 5. 测试发送邮件
// ========================================
elseif ($action === 'test_send' && $method === 'POST') {
    checkEmailAuth();
    
    $data = getJsonInput();
    $accountId = (int)($data['account_id'] ?? 0);
    $recipient = trim($data['recipient'] ?? '');
    
    if ($accountId <= 0) {
        jsonResponse([
            'success' => false,
            'message' => '无效的账户ID'
        ], 400);
    }
    
    if (empty($recipient)) {
        jsonResponse([
            'success' => false,
            'message' => '收件人邮箱不能为空'
        ], 400);
    }
    
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        jsonResponse([
            'success' => false,
            'message' => '收件人邮箱格式错误'
        ], 400);
    }
    
    $result = sendTestEmail($conn, $accountId, $recipient);
    
    if ($result['success']) {
        jsonResponse($result);
    } else {
        jsonResponse($result, 400);
    }
}

// ========================================
// 6. 获取邮件发送日志
// ========================================
elseif ($action === 'get_logs' && $method === 'GET') {
    checkEmailAuth();
    
    $filters = [
        'account_id' => (int)($_GET['account_id'] ?? 0) ?: null,
        'status' => $_GET['status'] ?? null,
        'limit' => (int)($_GET['limit'] ?? 20),
        'offset' => (int)($_GET['offset'] ?? 0)
    ];
    
    // 移除空值
    $filters = array_filter($filters, function($v) {
        return $v !== null && $v !== '';
    });
    
    $result = getEmailLogs($conn, $filters);
    jsonResponse($result);
}

// ========================================
// 7. 发送预警邮件（轮换调用）
// ========================================
elseif ($action === 'send_warning' && $method === 'POST') {
    checkEmailAuth();
    
    $data = getJsonInput();
    $recipient = trim($data['recipient'] ?? '');
    $subject = trim($data['subject'] ?? '保质期预警通知');
    $body = $data['body'] ?? '';
    
    if (empty($recipient)) {
        jsonResponse([
            'success' => false,
            'message' => '收件人邮箱不能为空'
        ], 400);
    }
    
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        jsonResponse([
            'success' => false,
            'message' => '收件人邮箱格式错误'
        ], 400);
    }
    
    if (empty($body)) {
        jsonResponse([
            'success' => false,
            'message' => '邮件内容不能为空'
        ], 400);
    }
    
    $result = sendEmail($conn, $recipient, $subject, $body);
    
    if ($result['success']) {
        jsonResponse($result);
    } else {
        jsonResponse($result, 400);
    }
}

// ========================================
// 8. 批量发送预警邮件（给多个收件人）
// ========================================
elseif ($action === 'send_warning_batch' && $method === 'POST') {
    checkEmailAuth();
    
    $data = getJsonInput();
    $recipients = $data['recipients'] ?? [];
    $subject = trim($data['subject'] ?? '保质期预警通知');
    $body = $data['body'] ?? '';
    
    if (empty($recipients) || !is_array($recipients)) {
        jsonResponse([
            'success' => false,
            'message' => '收件人列表不能为空'
        ], 400);
    }
    
    if (empty($body)) {
        jsonResponse([
            'success' => false,
            'message' => '邮件内容不能为空'
        ], 400);
    }
    
    $results = [];
    $successCount = 0;
    $failCount = 0;
    
    foreach ($recipients as $recipient) {
        $recipient = trim($recipient);
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $results[$recipient] = [
                'success' => false,
                'message' => '邮箱格式错误'
            ];
            $failCount++;
            continue;
        }
        
        $result = sendEmail($conn, $recipient, $subject, $body);
        $results[$recipient] = $result;
        
        if ($result['success']) {
            $successCount++;
        } else {
            $failCount++;
        }
    }
    
    jsonResponse([
        'success' => true,
        'message' => "批量发送完成: 成功{$successCount}封, 失败{$failCount}封",
        'data' => [
            'total' => count($recipients),
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'results' => $results
        ]
    ]);
}

// ========================================
// 404 - 未知的action
// ========================================
else {
    jsonResponse([
        'success' => false,
        'message' => '无效的操作',
        'available_actions' => [
            'add_account' => 'POST - 添加邮箱账户',
            'list_accounts' => 'GET - 列出所有邮箱账户',
            'update_account' => 'POST - 更新邮箱账户',
            'delete_account' => 'POST - 删除邮箱账户',
            'test_send' => 'POST - 测试发送邮件',
            'get_logs' => 'GET - 获取邮件发送日志',
            'send_warning' => 'POST - 发送预警邮件（单封）',
            'send_warning_batch' => 'POST - 批量发送预警邮件'
        ]
    ], 404);
}
