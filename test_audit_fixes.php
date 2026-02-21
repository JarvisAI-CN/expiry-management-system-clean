<?php
/**
 * ========================================
 * 审计修复验证脚本
 * 用途: 验证代码审计中发现的问题是否已修复
 * ========================================
 */

// 引入配置和核心文件
require_once 'config.php';
require_once 'db.php';

// 测试函数
function testEmailValidation() {
    echo "=== 测试邮箱格式验证 ===\n";
    
    require_once 'email_functions.php';
    
    $conn = getDBConnection();
    
    // 测试无效邮箱
    $invalidEmails = [
        'invalidemail',
        'email@',
        '@example.com',
        'email@example',
        'email@.com'
    ];
    
    foreach ($invalidEmails as $email) {
        $result = sendEmail($conn, $email, '测试邮件', '这是一封测试邮件');
        if ($result['success']) {
            echo "❌ 测试失败: 应该拒绝无效邮箱 '$email'\n";
        } else {
            echo "✅ 测试成功: 正确拒绝无效邮箱 '$email'\n";
        }
    }
}

function testDebugLogLevel() {
    echo "\n=== 测试调试日志级别控制 ===\n";
    
    define('DEBUG_MODE', false); // 模拟生产环境
    require_once 'debug_log.php';
    
    // 测试调试信息是否会被记录到文件
    $logFile = 'debug_log.txt';
    
    // 清理旧的调试日志
    if (file_exists($logFile)) {
        unlink($logFile);
    }
    
    debugLog('这是调试信息', 'DEBUG');
    debugLog('这是错误信息', 'ERROR');
    
    if (file_exists($logFile)) {
        $logContent = file_get_contents($logFile);
        if (strpos($logContent, '这是调试信息') !== false) {
            echo "❌ 测试失败: 生产环境应该不记录调试信息\n";
        } else {
            echo "✅ 测试成功: 生产环境只记录错误信息\n";
        }
    } else {
        echo "✅ 测试成功: 生产环境不创建调试日志文件\n";
    }
    
    // 再次启用调试模式
    define('DEBUG_MODE', true);
}

function testDatabaseConnectivity() {
    echo "\n=== 测试数据库连接 ===\n";
    
    $conn = getDBConnection();
    if ($conn) {
        echo "✅ 数据库连接成功\n";
        
        // 检查必要的表是否存在
        $requiredTables = [
            'email_accounts',
            'email_logs',
            'inventory_edit_logs',
            'users',
            'products',
            'batches'
        ];
        
        foreach ($requiredTables as $table) {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            if ($result->num_rows > 0) {
                echo "✅ 表 $table 存在\n";
            } else {
                echo "❌ 表 $table 不存在\n";
            }
        }
        
        $conn->close();
    } else {
        echo "❌ 数据库连接失败\n";
    }
}

function testApiEndpoints() {
    echo "\n=== 测试API接口响应 ===\n";
    
    $endpoints = [
        'api.php?endpoint=categories',
        'api.php?endpoint=products',
        'api.php?endpoint=batches'
    ];
    
    foreach ($endpoints as $endpoint) {
        $url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/' . $endpoint;
        $response = @file_get_contents($url);
        
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['success']) && $data['success']) {
                echo "✅ 接口 $endpoint 响应成功\n";
            } else {
                echo "❌ 接口 $endpoint 响应失败: " . ($data['message'] ?? '未知错误') . "\n";
            }
        } else {
            echo "❌ 接口 $endpoint 请求失败\n";
        }
    }
}

// 运行所有测试
echo "============================\n";
echo "=== 审计修复验证脚本 ===\n";
echo "============================\n";

if (php_sapi_name() === 'cli') {
    echo "\n执行环境: 命令行模式\n";
    
    testEmailValidation();
    testDebugLogLevel();
    testDatabaseConnectivity();
} else {
    echo "\n执行环境: 网页模式\n";
    echo "请在命令行模式下运行此脚本以获得完整的测试输出\n";
}

echo "\n============================\n";
echo "=== 测试完成 ===\n";
echo "============================\n";
?>