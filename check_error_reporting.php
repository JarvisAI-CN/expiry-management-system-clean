<?php
/**
 * ========================================
 * 保质期管理系统 - 检查错误报告配置脚本
 * 文件名: check_error_reporting.php
 * 用途: 检查和测试PHP错误报告配置
 * ========================================
 */

header('Content-Type: text/html; charset=utf-8');

echo "<h1>PHP错误报告配置检查</h1>";

// 检查当前配置
echo "<h3>当前PHP错误报告配置:</h3>";
echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; margin-bottom: 20px;'>";
echo "<tr><th style='background-color: #f5f5f5; padding: 10px; text-align: left;'>配置项</th><th style='background-color: #f5f5f5; padding: 10px; text-align: left;'>值</th></tr>";
echo "<tr><td>display_errors</td><td>" . ini_get('display_errors') . "</td></tr>";
echo "<tr><td>error_reporting</td><td>" . ini_get('error_reporting') . " (" . error_reporting() . ")</td></tr>";
echo "<tr><td>log_errors</td><td>" . ini_get('log_errors') . "</td></tr>";
echo "<tr><td>error_log</td><td>" . ini_get('error_log') . "</td></tr>";
echo "<tr><td>open_basedir</td><td>" . ini_get('open_basedir') . "</td></tr>";
echo "</table>";

// 测试错误记录
echo "<h3>测试错误记录:</h3>";

// 创建一个临时测试文件
$testFile = dirname(__FILE__) . '/test_error_log.txt';

// 设置自定义错误处理
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    global $testFile;
    $logMessage = "[" . date('Y-m-d H:i:s') . "] Error ($errno): $errstr in $errfile on line $errline" . PHP_EOL;
    file_put_contents($testFile, $logMessage, FILE_APPEND | LOCK_EX);
    return true;
}

// 设置自定义错误处理
set_error_handler('customErrorHandler');
set_exception_handler(function($e) {
    global $testFile;
    $logMessage = "[" . date('Y-m-d H:i:s') . "] Exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . PHP_EOL;
    file_put_contents($testFile, $logMessage, FILE_APPEND | LOCK_EX);
});

// 清理之前的测试日志
if (file_exists($testFile)) {
    unlink($testFile);
}

// 测试记录错误
echo "<p>1. 测试记录警告信息:</p>";
trigger_error("这是一个测试警告", E_USER_WARNING);
if (file_exists($testFile)) {
    $content = file_get_contents($testFile);
    if (strpos($content, "测试警告") !== false) {
        echo "<p style='color: green;'><strong>✓ 成功记录警告信息</strong></p>";
    } else {
        echo "<p style='color: red;'><strong>✗ 未能记录警告信息</strong></p>";
    }
} else {
    echo "<p style='color: red;'><strong>✗ 日志文件未创建</strong></p>";
}

// 测试记录send_inventory_email相关错误
echo "<p>2. 测试记录send_inventory_email相关错误:</p>";
error_log('send_inventory_email API called (test): ' . date('Y-m-d H:i:s'));

// 检查是否能记录到标准错误日志
$sent = mail('test@example.com', '测试邮件', '这是一封测试邮件');
echo "<p>3. 测试mail()函数: " . ($sent ? "<span style='color: green;'>✓ 发送成功</span>" : "<span style='color: red;'>✗ 发送失败</span>") . "</p>";

// 显示测试文件内容
echo "<h3>测试日志文件内容:</h3>";
if (file_exists($testFile)) {
    echo "<pre style='background-color: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto;'>";
    echo htmlspecialchars(file_get_contents($testFile));
    echo "</pre>";
    
    // 清理测试文件
    unlink($testFile);
} else {
    echo "<p style='color: red;'><strong>✗ 测试日志文件未创建</strong></p>";
}

// 恢复默认错误处理
restore_error_handler();
restore_exception_handler();
?>
