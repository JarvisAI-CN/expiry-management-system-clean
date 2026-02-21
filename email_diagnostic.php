<?php
header('Content-Type: application/json');

$checks = [];

// 1. 检查email_functions.php是否存在
$checks['email_functions_exists'] = file_exists(__DIR__ . '/email_functions.php');

// 2. 检查smtp_mailer.php是否存在
$checks['smtp_mailer_exists'] = file_exists(__DIR__ . '/smtp_mailer.php');

// 3. 检查email_api.php是否存在
$checks['email_api_exists'] = file_exists(__DIR__ . '/email_api.php');

// 4. 检查upgrade_to_v2.14.php是否存在
$checks['upgrade_script_exists'] = file_exists(__DIR__ . '/upgrade_to_v2.14.php');

// 5. 检查db.php是否存在
$checks['db_php_exists'] = file_exists(__DIR__ . '/db.php');

// 6. 检查数据库连接
if ($checks['db_php_exists']) {
    require_once __DIR__ . '/db.php';
    $conn = getDBConnection();
    
    if ($conn === false) {
        $checks['db_connection_error'] = '无法获取数据库连接';
    } else {
        // 检查email_accounts表是否存在
        $result = $conn->query("SHOW TABLES LIKE 'email_accounts'");
        $checks['email_accounts_table_exists'] = $result && $result->num_rows > 0;
        
        if ($checks['email_accounts_table_exists']) {
            // 检查是否有活跃的邮箱账户
            $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM email_accounts WHERE is_active = 1");
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $checks['active_email_count'] = (int)$row['cnt'];
            
            // 获取邮箱账户列表
            $stmt = $conn->prepare("SELECT email, is_active, created_at FROM email_accounts");
            $stmt->execute();
            $result = $stmt->get_result();
            $checks['email_accounts'] = [];
            while ($row = $result->fetch_assoc()) {
                $checks['email_accounts'][] = $row;
            }
        }
        
        // 检查settings表中是否有默认收件邮箱
        $stmt = $conn->prepare("SELECT s_value FROM settings WHERE s_key = 'default_recipient_email'");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $checks['default_recipient_email'] = $row['s_value'];
        } else {
            $checks['default_recipient_email'] = null;
        }
    }
} else {
    $checks['email_accounts_table_exists'] = false;
    $checks['active_email_count'] = 0;
    $checks['default_recipient_email'] = null;
}

// 7. 检查PHP版本和扩展
$checks['php_version'] = phpversion();
$checks['openssl_loaded'] = extension_loaded('openssl');
$checks['mbstring_loaded'] = extension_loaded('mbstring');

echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
