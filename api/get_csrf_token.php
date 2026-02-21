<?php
/**
 * 获取CSRF Token API
 * 
 * 这个API用于生成和获取CSRF token
 * 不需要鉴权，但会检查会话
 */

header('Content-Type: application/json');

if (!session_id()) {
    session_start();
}

require_once '../csrf_token.php';

// 生成新的CSRF token
$token = CSRFToken::generate();

echo json_encode([
    'success' => true,
    'token' => $token,
    'message' => 'CSRF token 已生成'
]);

// 可以选择设置token到响应头
// header('X-CSRF-Token: ' . $token);

// 添加CORS支持（如果需要）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
?>