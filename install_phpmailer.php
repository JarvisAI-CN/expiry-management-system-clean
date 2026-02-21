<?php
/**
 * ========================================
 * 保质期管理系统 - PHPMailer安装脚本
 * 文件名: install_phpmailer.php
 * 用途: 通过HTTP请求安装PHPMailer库
 * ========================================
 */

header('Content-Type: text/plain');
header('X-Content-Type-Options: nosniff');

// 检查是否为本地访问（可选）
// $allowedIPs = ['127.0.0.1', '::1'];
// $remoteIP = $_SERVER['REMOTE_ADDR'];
// if (!in_array($remoteIP, $allowedIPs)) {
//     die("Access denied from $remoteIP. Please access from local server.");
// }

// 检查是否有composer
$composerPath = 'composer';

// 如果没有找到系统的composer，尝试下载
if (!is_executable($composerPath) && !file_exists('composer.phar')) {
    echo "=== 正在下载Composer... ===\n";
    $composerDownload = @file_get_contents('https://getcomposer.org/installer');
    if (!$composerDownload) {
        die("❌ 无法下载Composer.");
    }
    @file_put_contents('composer-setup.php', $composerDownload);
    
    echo "=== 正在安装Composer... ===\n";
    $output = [];
    $return_var = 0;
    exec('php composer-setup.php 2>&1', $output, $return_var);
    
    if ($return_var !== 0) {
        echo implode("\n", $output) . "\n";
        @unlink('composer-setup.php');
        die("❌ Composer安装失败.");
    }
    @unlink('composer-setup.php');
    $composerPath = 'php composer.phar';
    echo "✅ Composer安装成功.\n";
}

// 安装依赖
echo "\n=== 正在安装PHPMailer依赖... ===\n";
$output = [];
$return_var = 0;
exec("$composerPath install 2>&1", $output, $return_var);

if ($return_var !== 0) {
    echo implode("\n", $output) . "\n";
    die("❌ 依赖安装失败.");
}

echo implode("\n", $output) . "\n";
echo "\n✅ PHPMailer库安装成功！\n";
echo "✅ 现在可以使用邮件发送功能了.\n";
?>
