<?php
/**
 * ========================================
 * 保质期管理系统 - 调试日志测试脚本
 * 文件名: test_debug_log.php
 * 用途: 测试debug_log函数是否正常工作
 * ========================================
 */

header('Content-Type: text/html; charset=utf-8');

echo "<h1>调试日志测试</h1>";

// 引入调试日志工具
require_once __DIR__ . '/debug_log.php';

// 测试1: 记录基本信息
echo "<h3>1. 测试记录基本信息:</h3>";
$test1Result = debugLog("这是一个测试日志信息");
if ($test1Result) {
    echo "<p style='color: green;'><strong>✓ 成功记录测试日志信息</strong></p>";
} else {
    echo "<p style='color: red;'><strong>✗ 未能记录测试日志信息</strong></p>";
}

// 测试2: 记录带前缀的信息
echo "<h3>2. 测试记录带前缀的信息:</h3>";
$test2Result = debugLog("测试带前缀的日志信息", "TEST");
if ($test2Result) {
    echo "<p style='color: green;'><strong>✓ 成功记录带前缀的测试日志信息</strong></p>";
} else {
    echo "<p style='color: red;'><strong>✗ 未能记录带前缀的测试日志信息</strong></p>";
}

// 测试3: 记录API相关信息
echo "<h3>3. 测试记录API相关信息:</h3>";
$test3Result = debugLog("API调用成功", "API");
if ($test3Result) {
    echo "<p style='color: green;'><strong>✓ 成功记录API相关信息</strong></p>";
} else {
    echo "<p style='color: red;'><strong>✗ 未能记录API相关信息</strong></p>";
}

// 检查日志文件是否创建
echo "<h3>4. 检查日志文件:</h3>";
$logPath = __DIR__ . '/debug_log.txt';
if (file_exists($logPath)) {
    $fileSize = filesize($logPath);
    $linesCount = count(file($logPath));
    echo "<p style='color: green;'><strong>✓ 日志文件已成功创建</strong></p>";
    echo "<p><strong>文件路径:</strong> $logPath</p>";
    echo "<p><strong>文件大小:</strong> $fileSize 字节</p>";
    echo "<p><strong>行数:</strong> $linesCount 行</p>";
    
    // 显示前几行内容
    echo "<h3>日志文件内容:</h3>";
    echo "<pre style='background-color: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto;'>";
    $lines = file($logPath);
    for ($i = 0; $i < min(5, $linesCount); $i++) {
        echo htmlspecialchars($lines[$i]) . "<br>";
    }
    if ($linesCount > 5) {
        echo "... 还有 " . ($linesCount - 5) . " 行 ...";
    }
    echo "</pre>";
} else {
    echo "<p style='color: red;'><strong>✗ 日志文件未创建</strong></p>";
    echo "<p>尝试的路径: $logPath</p>";
    echo "<p><strong>检查以下内容:</strong></p>";
    echo "<ul>";
    echo "<li>文件夹权限是否允许写入 (建议: 755)</li>";
    echo "<li>文件权限是否允许写入 (建议: 644)</li>";
    echo "<li>php.ini中的open_basedir限制</li>";
    echo "</ul>";
    
    // 尝试直接创建文件
    echo "<h4>尝试直接创建文件:</h4>";
    $tempFileName = __DIR__ . '/temp_test_file_' . uniqid() . '.txt';
    $tempContent = "测试文件内容: " . date('Y-m-d H:i:s');
    
    if (file_put_contents($tempFileName, $tempContent) !== false) {
        echo "<p style='color: green;'><strong>✓ 成功直接创建文件</strong></p>";
        echo "<p><strong>文件路径:</strong> $tempFileName</p>";
        echo "<p><strong>文件大小:</strong> " . filesize($tempFileName) . " 字节</p>";
        
        // 删除临时文件
        unlink($tempFileName);
        echo "<p style='color: green;'><strong>✓ 临时文件已删除</strong></p>";
    } else {
        echo "<p style='color: red;'><strong>✗ 无法直接创建文件</strong></p>";
        echo "<p><strong>错误信息:</strong> " . error_get_last()['message'] . "</p>";
    }
}
?>
