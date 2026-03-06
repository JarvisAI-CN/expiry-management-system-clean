#!/usr/bin/env php
<?php
/**
 * 导出文件清理脚本
 * 
 * 功能：
 * - 清理指定天数前的CSV导出文件
 * - 支持dry-run模式预览
 * - 记录清理日志
 * - 可通过cron定期执行
 * 
 * 使用方法：
 * - 正常清理: php scripts/cleanup_exports.php
 * - 预览模式: php scripts/cleanup_exports.php --dry-run
 * - 清理所有: php scripts/cleanup_exports.php --full
 * 
 * @author 贾维斯
 * @created 2026-03-06
 */

$exportDir = dirname(__DIR__) . '/exports';
$retentionDays = 7;
$dryRun = in_array('--dry-run', $argv);
$fullCleanup = in_array('--full', $argv);

// 如果是--full模式，清理所有文件（不管天数）
if ($fullCleanup) {
    $retentionDays = 0;
}

// 检查目录是否存在
if (!is_dir($exportDir)) {
    echo "[ERROR] Export directory not found: $exportDir\n";
    exit(1);
}

// 获取所有CSV文件
$files = glob("$exportDir/*.csv");
$deletedCount = 0;
$skippedCount = 0;
$totalSize = 0;

echo ($dryRun ? "[DRY-RUN] " : "") . "Starting export cleanup...\n";
echo "Directory: $exportDir\n";
echo "Retention: $retentionDays days\n";
echo str_repeat('-', 60) . "\n";

foreach ($files as $file) {
    $filename = basename($file);
    $fileAge = (time() - filemtime($file)) / 86400; // 转换为天数
    $fileSize = filesize($file);
    
    // 判断是否需要删除
    $shouldDelete = $retentionDays === 0 || $fileAge > $retentionDays;
    
    if ($shouldDelete) {
        if ($dryRun) {
            echo "[DRY-RUN] Would delete: $filename ";
            echo "(" . number_format($fileAge, 1) . " days old, " . formatBytes($fileSize) . ")\n";
        } else {
            if (unlink($file)) {
                echo "[DELETED] $filename (" . number_format($fileAge, 1) . " days old)\n";
                $deletedCount++;
                $totalSize += $fileSize;
            } else {
                echo "[ERROR] Failed to delete: $filename\n";
            }
        }
    } else {
        $skippedCount++;
    }
}

echo str_repeat('-', 60) . "\n";

if ($dryRun) {
    $totalFiles = count($files);
    $wouldDeleteCount = $totalFiles - $skippedCount;
    echo "Summary: Would delete $wouldDeleteCount of $totalFiles files\n";
} else {
    echo "Deleted: $deletedCount files\n";
    echo "Space saved: " . formatBytes($totalSize) . "\n";
    echo "Skipped: $skippedCount files (within retention period)\n";
    
    // 记录到日志文件
    $logFile = dirname(__DIR__) . '/logs/cleanup_exports.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logEntry = sprintf(
        "[%s] Deleted: %d files, Saved: %s\n",
        date('Y-m-d H:i:s'),
        $deletedCount,
        formatBytes($totalSize)
    );
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    echo "Logged to: $logFile\n";
}

exit(0);

/**
 * 格式化字节大小
 */
function formatBytes($size, $precision = 2) {
    if ($size == 0) return '0 B';
    
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $pow = floor(log($size, 1024));
    $pow = min($pow, count($units) - 1);
    
    $size /= pow(1024, $pow);
    
    return round($size, $precision) . ' ' . $units[$pow];
}
