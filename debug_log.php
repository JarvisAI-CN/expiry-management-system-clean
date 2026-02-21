<?php
/**
 * ========================================
 * 保质期管理系统 - 调试日志工具
 * 文件名: debug_log.php
 * 用途: 在项目目录中记录调试信息，方便检查
 * ========================================
 */

// 调试模式开关（生产环境应设置为false）
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', false);
}

function debugLog($message, $prefix = "DEBUG") {
    // 生产环境下只记录错误信息
    if (!DEBUG_MODE) {
        if ($prefix === 'ERROR') {
            error_log($message);
        }
        return false;
    }
    
    $logPath = dirname(__FILE__) . '/debug_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $fullMessage = "[$timestamp] [$prefix] $message" . PHP_EOL;
    
    // 确保日志文件可写
    $success = file_put_contents($logPath, $fullMessage, FILE_APPEND | LOCK_EX);
    
    return $success !== false;
}

// 清理旧的日志文件（如果文件大小超过1MB）
function cleanDebugLog() {
    $logPath = dirname(__FILE__) . '/debug_log.txt';
    if (file_exists($logPath) && filesize($logPath) > 1024 * 1024) { // 1MB
        // 压缩旧的日志文件
        $oldLogPath = dirname(__FILE__) . '/debug_log_' . date('YmdHis') . '.txt';
        rename($logPath, $oldLogPath);
    }
}

// 初始化日志清理
cleanDebugLog();
?>
