<?php
/**
 * ========================================
 * 保质期管理系统 - 邮件发送测试脚本
 * 文件名: test_email_send.php
 * 用途: 快速测试邮件发送功能
 * ========================================
 */

header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

// 测试邮件发送
try {
    $conn = getDBConnection();
    
    if (!$conn) {
        throw new Exception("数据库连接失败");
    }
    
    require_once __DIR__ . '/email_functions.php';
    
    // 测试发送到默认收件邮箱
    $testSubject = "邮件发送功能测试 - " . date('Y-m-d H:i:s');
    $testBody = "<h1>邮件发送功能测试成功</h1><p>这是一封测试邮件，发送时间：" . date('Y-m-d H:i:s') . "</p>";
    
    $result = sendEmail($conn, '1667235636@qq.com', $testSubject, $testBody);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => '邮件发送成功',
            'subject' => $testSubject,
            'result' => $result
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '邮件发送失败',
            'error' => $result['message'],
            'result' => $result
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '测试过程中发生错误',
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
