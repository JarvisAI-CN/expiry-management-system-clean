<?php
/**
 * ========================================
 * 邮箱配置功能 - 快速测试脚本
 * 文件名: test_email_config.php
 * 版本: v2.14.0
 * ========================================
 * 
 * 用途: 验证邮箱配置功能是否正常安装
 * 使用: 直接访问此文件进行测试
 * 
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_functions.php';

// 检查登录状态
if (!isset($_SESSION['user_id'])) {
    die("<h1>❌ 需要登录</h1><p>请先<a href='index.php'>登录</a>后再运行测试</p>");
}

$tests = [];
$errors = [];

echo "<!DOCTYPE html>
<html lang='zh-CN'>
<head>
    <meta charset='UTF-8'>
    <title>邮箱配置功能测试</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        h1 { color: #333; border-bottom: 3px solid #007AFF; padding-bottom: 10px; }
        .test-item { margin: 20px 0; padding: 15px; border-radius: 8px; }
        .success { background: #d4edda; border-left: 4px solid #28a745; }
        .error { background: #f8d7da; border-left: 4px solid #dc3545; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; }
        .info { background: #d1ecf1; border-left: 4px solid #17a2b8; }
        h2 { color: #555; margin-top: 0; }
        code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>🧪 邮箱配置功能测试</h1>
    <p>测试时间: " . date('Y-m-d H:i:s') . "</p>
    <hr>";

// ========================================
// 测试1: 数据库表是否存在
// ========================================
echo "<h2>测试1: 数据库表检查</h2>";

$conn = getDBConnection();
if (!$conn) {
    echo "<div class='test-item error'>❌ 数据库连接失败</div>";
} else {
    echo "<div class='test-item success'>✅ 数据库连接成功</div>";
    
    // 检查email_accounts表
    $result = $conn->query("SHOW TABLES LIKE 'email_accounts'");
    if ($result->num_rows > 0) {
        echo "<div class='test-item success'>✅ email_accounts 表存在</div>";
        
        // 检查表结构
        $columns = $conn->query("DESC email_accounts");
        $requiredColumns = ['id', 'qq_number', 'email_address', 'auth_code_encrypted', 'is_active', 'priority', 'send_count', 'last_sent_at'];
        $existingColumns = [];
        while ($row = $columns->fetch_assoc()) {
            $existingColumns[] = $row['Field'];
        }
        
        $missingColumns = array_diff($requiredColumns, $existingColumns);
        if (empty($missingColumns)) {
            echo "<div class='test-item success'>✅ email_accounts 表结构完整</div>";
        } else {
            echo "<div class='test-item error'>❌ email_accounts 表缺少字段: " . implode(', ', $missingColumns) . "</div>";
        }
    } else {
        echo "<div class='test-item error'>❌ email_accounts 表不存在，请运行升级脚本</div>";
    }
    
    // 检查email_logs表
    $result = $conn->query("SHOW TABLES LIKE 'email_logs'");
    if ($result->num_rows > 0) {
        echo "<div class='test-item success'>✅ email_logs 表存在</div>";
    } else {
        echo "<div class='test-item error'>❌ email_logs 表不存在，请运行升级脚本</div>";
    }
}

// ========================================
// 测试2: 配置文件检查
// ========================================
echo "<h2>测试2: 配置文件检查</h2>";

if (defined('EMAIL_ENCRYPTION_KEY')) {
    $keyLength = strlen(EMAIL_ENCRYPTION_KEY);
    if ($keyLength >= 32) {
        echo "<div class='test-item success'>✅ EMAIL_ENCRYPTION_KEY 已定义 (长度: {$keyLength})</div>";
    } else {
        echo "<div class='test-item warning'>⚠️ EMAIL_ENCRYPTION_KEY 长度过短 (当前: {$keyLength}, 建议: 32+)</div>";
    }
} else {
    echo "<div class='test-item error'>❌ EMAIL_ENCRYPTION_KEY 未定义，请运行升级脚本或重新安装</div>";
}

// 检查系统设置
$settings = [
    'email_smtp_host',
    'email_smtp_port',
    'email_smtp_encryption',
    'email_cooldown_seconds'
];

foreach ($settings as $key) {
    $value = getSetting($key);
    if ($value) {
        echo "<div class='test-item success'>✅ {$key} = {$value}</div>";
    } else {
        echo "<div class='test-item warning'>⚠️ {$key} 未设置</div>";
    }
}

// ========================================
// 测试3: 加密/解密功能测试
// ========================================
echo "<h2>测试3: 加密/解密功能测试</h2>";

$testAuthCode = 'test_auth_code_12345';
try {
    $encrypted = encryptAuthCode($testAuthCode);
    $decrypted = decryptAuthCode($encrypted);
    
    if ($decrypted === $testAuthCode) {
        echo "<div class='test-item success'>✅ 加密/解密功能正常</div>";
        echo "<div class='test-item info'>📝 原文: <code>{$testAuthCode}</code></div>";
        echo "<div class='test-item info'>📝 密文: <code>" . substr($encrypted, 0, 50) . "...</code></div>";
    } else {
        echo "<div class='test-item error'>❌ 加密/解密失败 (原文: {$testAuthCode}, 解密: {$decrypted})</div>";
    }
} catch (Exception $e) {
    echo "<div class='test-item error'>❌ 加密/解密异常: " . $e->getMessage() . "</div>";
}

// ========================================
// 测试4: 轮换算法测试
// ========================================
echo "<h2>测试4: 轮换算法测试</h2>";

if ($conn) {
    // 检查是否有邮箱账户
    $result = $conn->query("SELECT COUNT(*) as count FROM email_accounts");
    $count = $result->fetch_assoc()['count'];
    
    if ($count > 0) {
        echo "<div class='test-item success'>✅ 已有 {$count} 个邮箱账户</div>";
        
        // 测试选择下一个账户
        $account = selectNextAccount($conn);
        if ($account) {
            echo "<div class='test-item success'>✅ 轮换算法正常工作</div>";
            echo "<div class='test-item info'>📝 选中的账户: <code>{$account['email_address']}</code></div>";
            echo "<div class='test-item info'>📝 优先级: {$account['priority']}</div>";
            echo "<div class='test-item info'>📝 发送次数: {$account['send_count']}</div>";
        } else {
            echo "<div class='test-item warning'>⚠️ 没有可用的邮箱账户（可能全部被禁用）</div>";
        }
    } else {
        echo "<div class='test-item info'>ℹ️ 还没有邮箱账户，请先添加</div>";
    }
}

// ========================================
// 测试5: PHPMailer检查
// ========================================
echo "<h2>测试5: PHPMailer检查</h2>";

if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    echo "<div class='test-item success'>✅ PHPMailer 已安装</div>";
} else {
    echo "<div class='test-item warning'>⚠️ PHPMailer 未安装</div>";
    echo "<div class='test-item info'>📝 安装命令: <code>composer require phpmailer/phpmailer</code></div>";
}

// ========================================
// 测试6: API接口检查
// ========================================
echo "<h2>测试6: API接口检查</h2>";

$apiFiles = [
    'email_api.php' => '邮箱API接口',
    'email_functions.php' => '邮箱功能函数',
    'smtp_mailer.php' => 'SMTP发送封装'
];

foreach ($apiFiles as $file => $desc) {
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "<div class='test-item success'>✅ {$file} ({$desc}) 存在</div>";
    } else {
        echo "<div class='test-item error'>❌ {$file} ({$desc}) 不存在</div>";
    }
}

// ========================================
// 总结
// ========================================
echo "<hr><h2>📊 测试总结</h2>";

$conn->close();

echo "<div class='test-item info'>
    <strong>测试完成！</strong><br><br>
    如果所有测试都通过 ✅，说明邮箱配置功能安装成功！<br><br>
    <strong>下一步：</strong><br>
    1. 访问 <a href='admin.php'>管理后台</a><br>
    2. 点击"邮箱配置"菜单<br>
    3. 添加QQ邮箱账户<br>
    4. 测试发送邮件<br>
</div>";

echo "<div class='test-item warning'>
    <strong>⚠️ 注意：</strong><br>
    - 本测试文件仅供开发调试使用<br>
    - 生产环境建议删除此文件<br>
    - 授权码请妥善保管，不要泄露<br>
</div>";

echo "</body></html>";
