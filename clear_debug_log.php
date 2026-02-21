<?php
/**
 * 清除调试日志脚本
 */

$logPath = dirname(__FILE__) . '/debug_log.txt';

if (file_exists($logPath)) {
    if (unlink($logPath)) {
        echo json_encode(['success' => true, 'message' => '调试日志已清除']);
    } else {
        echo json_encode(['success' => false, 'message' => '无法删除日志文件']);
    }
} else {
    echo json_encode(['success' => true, 'message' => '日志文件不存在']);
}
?>
