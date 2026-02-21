<?php
/**
 * ========================================
 * 保质期管理系统 - 前端发送邮件测试脚本
 * 文件名: test_send_email_frontend.php
 * 用途: 模拟前端发送盘点单邮件，检查失败原因
 * ========================================
 */

header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

// 测试发送邮件的函数
function testSendEmail($subject, $body) {
    $conn = getDBConnection();
    
    if (!$conn) {
        return [
            'success' => false,
            'message' => '数据库连接失败'
        ];
    }
    
    // 获取默认收件邮箱
    $stmt = $conn->prepare("SELECT s_value FROM settings WHERE s_key = 'default_recipient_email' LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $to = '';
    if ($row = $result->fetch_assoc()) {
        $to = $row['s_value'];
    }
    
    if (empty($to)) {
        return [
            'success' => false,
            'message' => '未设置默认收件邮箱'
        ];
    }
    
    // 检查可用邮箱账户数量
    $checkStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM email_accounts WHERE is_active = 1");
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $row = $checkResult->fetch_assoc();
    $emailCount = (int)$row['cnt'];
    
    if ($emailCount === 0) {
        return [
            'success' => false,
            'message' => '没有可用的邮箱账户'
        ];
    }
    
    // 引入邮件发送功能
    require_once __DIR__ . '/smtp_mailer.php';
    
    $result = sendSmtpEmail($to, $subject, $body);
    
    return $result;
}

// 模拟前端发送的HTML表格
$mockBody = '
<table style="border-collapse: collapse; width: 100%; font-family: Arial, sans-serif;">
    <tr style="background-color: #f2f2f2;">
        <th style="border: 1px solid #dddddd; text-align: left; padding: 8px;">SKU</th>
        <th style="border: 1px solid #dddddd; text-align: left; padding: 8px;">商品名称</th>
        <th style="border: 1px solid #dddddd; text-align: left; padding: 8px;">到期日期</th>
    </tr>
    <tr>
        <td style="border: 1px solid #dddddd; text-align: left; padding: 8px;">123456</td>
        <td style="border: 1px solid #dddddd; text-align: left; padding: 8px;">测试商品</td>
        <td style="border: 1px solid #dddddd; text-align: left; padding: 8px;">2026-12-31</td>
    </tr>
</table>
';

// 测试发送
$testResult = testSendEmail('测试盘点单邮件', $mockBody);

// 输出结果
echo json_encode([
    'test_time' => date('Y-m-d H:i:s'),
    'subject' => '测试盘点单邮件',
    'body_length' => strlen($mockBody),
    'result' => $testResult
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
