<?php
/**
 * CSRF Token 中间件
 * 自动验证所有API请求
 */

require_once 'csrf_token.php';

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // 验证CSRF token
    if (!CSRFToken::validateRequest()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'CSRF token验证失败，请刷新页面重试'
        ]);
        exit;
    }
}
?>