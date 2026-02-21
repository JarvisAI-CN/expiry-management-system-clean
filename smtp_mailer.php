<?php
/**
 * ========================================
 * 保质期管理系统 - SMTP邮件发送封装
 * 文件名: smtp_mailer.php
 * 版本: v2.14.0
 * 创建日期: 2026-02-21
 * ========================================
 * 
 * 这是一个简化的邮件发送接口，供系统其他部分调用
 * 内部使用 email_functions.php 的智能轮换功能
 * 
 */

// 确保已加载必要文件
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_functions.php';

/**
 * 发送单封邮件（使用轮换账户）
 * 
 * @param string $recipient 收件人邮箱
 * @param string $subject 邮件主题
 * @param string $body 邮件正文（支持HTML）
 * @param int|null $accountId 指定账户ID（可选，不指定则自动轮换）
 * @return array ['success'=>bool, 'message'=>string]
 */
function sendSmtpEmail($recipient, $subject, $body, $accountId = null) {
    $conn = getDBConnection();
    if (!$conn) {
        return [
            'success' => false,
            'message' => '数据库连接失败'
        ];
    }
    
    return sendEmail($conn, $recipient, $subject, $body, $accountId);
}

/**
 * 批量发送邮件（自动轮换账户）
 * 
 * @param array $recipients 收件人邮箱数组
 * @param string $subject 邮件主题
 * @param string $body 邮件正文（支持HTML）
 * @return array ['success'=>bool, 'total'=>int, 'success_count'=>int, 'fail_count'=>int, 'results'=>array]
 */
function sendBulkSmtpEmail($recipients, $subject, $body) {
    $conn = getDBConnection();
    if (!$conn) {
        return [
            'success' => false,
            'message' => '数据库连接失败'
        ];
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
    
    return [
        'success' => true,
        'total' => count($recipients),
        'success_count' => $successCount,
        'fail_count' => $failCount,
        'results' => $results
    ];
}

/**
 * 发送保质期预警邮件
 * 
 * @param array $expiringProducts 临期商品列表
 * @param string $recipient 收件人邮箱
 * @return array 发送结果
 */
function sendExpiryAlert($expiringProducts, $recipient) {
    // 生成邮件内容
    $subject = '保质期预警通知 - ' . date('Y-m-d');
    
    $body = '
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 800px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #007AFF, #0051D5); color: white; padding: 30px; border-radius: 10px 10px 0 0; }
            .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
            .product-item { background: white; padding: 15px; margin: 10px 0; border-radius: 8px; border-left: 4px solid #007AFF; }
            .urgent { border-left-color: #FF3B30; }
            .warning { border-left-color: #FF9500; }
            .info { border-left-color: #34C759; }
            .footer { text-align: center; margin-top: 30px; color: #666; font-size: 12px; }
            .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; }
            .badge-urgent { background: #FF3B30; color: white; }
            .badge-warning { background: #FF9500; color: white; }
            .badge-info { background: #34C759; color: white; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>📦 保质期预警通知</h2>
                <p>以下是即将过期的商品清单，请及时处理</p>
            </div>
            <div class="content">
                <p><strong>生成时间：</strong>' . date('Y-m-d H:i:s') . '</p>
                <p><strong>预警商品数量：</strong>' . count($expiringProducts) . ' 件</p>
                <hr>
    ';
    
    foreach ($expiringProducts as $product) {
        $daysLeft = $product['days_left'] ?? 0;
        $badgeClass = $daysLeft <= 3 ? 'badge-urgent' : ($daysLeft <= 7 ? 'badge-warning' : 'badge-info');
        $itemClass = $daysLeft <= 3 ? 'urgent' : ($daysLeft <= 7 ? 'warning' : 'info');
        $badgeText = $daysLeft <= 3 ? '紧急' : ($daysLeft <= 7 ? '警告' : '提醒');
        
        $body .= '
                <div class="product-item ' . $itemClass . '">
                    <span class="badge ' . $badgeClass . '">' . $badgeText . '</span>
                    <h3>' . htmlspecialchars($product['name'] ?? 'Unknown') . '</h3>
                    <p><strong>SKU：</strong>' . htmlspecialchars($product['sku'] ?? 'N/A') . '</p>
                    <p><strong>批次：</strong>' . htmlspecialchars($product['batch_id'] ?? 'N/A') . '</p>
                    <p><strong>到期日期：</strong>' . ($product['expiry_date'] ?? 'N/A') . '</p>
                    <p><strong>剩余天数：</strong><strong style="color: ' . ($daysLeft <= 3 ? '#FF3B30' : '#FF9500') . ';">' . $daysLeft . ' 天</strong></p>
                    <p><strong>数量：</strong>' . ($product['quantity'] ?? 0) . '</p>
                </div>
        ';
    }
    
    $body .= '
            </div>
            <div class="footer">
                <p>此邮件由保质期管理系统自动发送</p>
                <p>发送时间: ' . date('Y-m-d H:i:s') . '</p>
            </div>
        </div>
    </body>
    </html>
    ';
    
    return sendSmtpEmail($recipient, $subject, $body);
}

/**
 * 检查邮箱配置状态
 * 
 * @return array ['configured'=>bool, 'active_accounts'=>int, 'message'=>string]
 */
function checkEmailConfig() {
    $conn = getDBConnection();
    if (!$conn) {
        return [
            'configured' => false,
            'active_accounts' => 0,
            'message' => '数据库连接失败'
        ];
    }
    
    // 检查email_accounts表是否存在
    $result = $conn->query("SHOW TABLES LIKE 'email_accounts'");
    if ($result->num_rows == 0) {
        return [
            'configured' => false,
            'active_accounts' => 0,
            'message' => '邮箱功能未安装，请运行升级脚本'
        ];
    }
    
    // 统计启用的账户数量
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM email_accounts WHERE is_active = 1");
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['count'];
    
    if ($count == 0) {
        return [
            'configured' => false,
            'active_accounts' => 0,
            'message' => '没有启用的邮箱账户，请在管理后台添加'
        ];
    }
    
    return [
        'configured' => true,
        'active_accounts' => $count,
        'message' => "邮箱配置正常，共 {$count} 个可用账户"
    ];
}

// 如果直接访问此文件，显示配置状态
if (basename($_SERVER['PHP_SELF']) === 'smtp_mailer.php') {
    header('Content-Type: application/json');
    echo json_encode(checkEmailConfig());
}
