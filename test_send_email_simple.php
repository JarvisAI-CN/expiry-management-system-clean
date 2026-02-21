<?php
/**
 * 简单邮件发送测试脚本
 */

require_once 'db.php';
require_once 'smtp_mailer.php';

header('Content-Type: application/json');

try {
    // 获取POST数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    $to = $input['to'] ?? '';
    $subject = $input['subject'] ?? '测试邮件';
    $body = $input['body'] ?? '这是一封测试邮件';
    
    if (empty($to)) {
        echo json_encode(['success' => false, 'message' => '缺少收件人地址']);
        exit;
    }
    
    // 发送邮件
    $result = sendSmtpEmail($to, $subject, $body);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => '邮件发送成功',
            'data' => $result['data'] ?? null
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? '发送失败',
            'error' => $result['error'] ?? ''
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '发生异常',
        'error' => $e->getMessage()
    ]);
}
?>
