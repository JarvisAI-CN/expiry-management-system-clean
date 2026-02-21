<?php
/**
 * ========================================
 * 保质期管理系统 - 查看调试日志脚本
 * 文件名: view_debug_log.php
 * 用途: 查看debug_log.txt中的调试信息
 * ========================================
 */

header('Content-Type: text/html; charset=utf-8');

$logPath = dirname(__FILE__) . '/debug_log.txt';

echo "<h1>调试日志查看器</h1>";

if (file_exists($logPath)) {
    echo "<p style='color: green;'><strong>✓ 找到调试日志文件:</strong> $logPath</p>";
    echo "<p><strong>文件大小:</strong> " . filesize($logPath) . " 字节</p>";
    
    $lines = file($logPath);
    $totalLines = count($lines);
    echo "<p><strong>总行数:</strong> $totalLines</p>";
    
    echo "<h3>完整的调试日志:</h3>";
    echo "<pre style='background-color: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto;'>";
    foreach ($lines as $line) {
        echo htmlspecialchars($line) . "<br>";
    }
    echo "</pre>";
    
    // 过滤只显示API相关的日志
    echo "<h3>API相关的调试日志:</h3>";
    echo "<pre style='background-color: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto;'>";
    $apiLogs = array_filter($lines, function($line) {
        return strpos($line, '[API]') !== false;
    });
    if (!empty($apiLogs)) {
        foreach ($apiLogs as $line) {
            echo htmlspecialchars($line) . "<br>";
        }
    } else {
        echo "没有找到API相关的日志";
    }
    echo "</pre>";
} else {
    echo "<p style='color: red;'><strong>✗ 未找到调试日志文件</strong></p>";
    echo "<p>调试日志文件路径: $logPath</p>";
    echo "<p><strong>可能的原因:</strong></p>";
    echo "<ul>";
    echo "<li>用户还没有尝试发送邮件，所以没有生成日志</li>";
    echo "<li>文件权限问题，无法创建日志文件</li>";
    echo "<li>debug_log.php文件未正确上传</li>";
    echo "</ul>";
}
?>
