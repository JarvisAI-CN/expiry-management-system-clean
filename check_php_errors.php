<?php
/**
 * ========================================
 * 保质期管理系统 - 检查PHP错误日志脚本
 * 文件名: check_php_errors.php
 * 用途: 检查服务器上的PHP错误日志，获取调试信息
 * ========================================
 */

header('Content-Type: text/html; charset=utf-8');

// 尝试找到PHP错误日志文件
$errorLogPaths = [
    '/var/log/php_errors.log',
    '/var/log/apache2/error.log',
    '/var/log/nginx/error.log',
    '/www/wwwroot/pandian.dhmip.cn/error_log', // 宝塔面板可能的位置
    dirname(__FILE__) . '/error_log'
];

$foundLog = '';

foreach ($errorLogPaths as $logPath) {
    if (file_exists($logPath)) {
        $foundLog = $logPath;
        break;
    }
}

echo "<h1>PHP错误日志检查</h1>";

if ($foundLog) {
    echo "<p style='color: green;'><strong>✓ 找到错误日志文件:</strong> $foundLog</p>";
    echo "<h3>最新错误信息 (最后50行):</h3>";
    echo "<pre style='background-color: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto;'>";
    
    $lines = file($foundLog);
    $lastLines = array_slice($lines, -50);
    foreach ($lastLines as $line) {
        echo htmlspecialchars(trim($line)) . "<br>";
    }
    
    echo "</pre>";
    
    // 检查是否有与send_inventory_email相关的日志
    echo "<h3>与send_inventory_email相关的日志:</h3>";
    echo "<pre style='background-color: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto;'>";
    
    $relatedLogs = [];
    foreach ($lines as $line) {
        if (strpos($line, 'send_inventory_email') !== false) {
            $relatedLogs[] = trim($line);
        }
    }
    
    if (!empty($relatedLogs)) {
        foreach ($relatedLogs as $log) {
            echo htmlspecialchars($log) . "<br>";
        }
    } else {
        echo "没有找到与send_inventory_email相关的日志信息";
    }
    
    echo "</pre>";
} else {
    echo "<p style='color: red;'><strong>✗ 未找到PHP错误日志文件</strong></p>";
    echo "<p>常见的PHP错误日志位置:</p>";
    echo "<ul>";
    foreach ($errorLogPaths as $path) {
        echo "<li>$path</li>";
    }
    echo "</ul>";
    
    // 检查当前目录是否有error_log文件
    $currentDirErrorLog = dirname(__FILE__) . '/error_log';
    echo "<p>当前目录是否有error_log文件:</p>";
    if (file_exists($currentDirErrorLog)) {
        echo "<p style='color: green;'>✓ 当前目录有error_log文件 (" . filesize($currentDirErrorLog) . " 字节)</p>";
        echo "<h3>当前目录error_log内容:</h3>";
        echo "<pre style='background-color: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto;'>";
        echo htmlspecialchars(file_get_contents($currentDirErrorLog));
        echo "</pre>";
    } else {
        echo "<p style='color: red;'>✗ 当前目录没有error_log文件</p>";
    }
}
?>
