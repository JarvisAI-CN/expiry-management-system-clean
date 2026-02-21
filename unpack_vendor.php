<?php
/**
 * ========================================
 * 保质期管理系统 - 解压vendor目录脚本
 * 文件名: unpack_vendor.php
 * 用途: 解压vendor.tar.gz到项目目录
 * ========================================
 */

header('Content-Type: text/plain');

// 检查文件是否存在
$vendorTarPath = __DIR__ . '/vendor.tar.gz';
if (!file_exists($vendorTarPath)) {
    die("❌ 没有找到 vendor.tar.gz 文件，请检查是否已上传");
}

// 检查是否有权限写入
if (!is_writable(__DIR__)) {
    die("❌ 项目目录不可写，请检查权限");
}

// 使用PHP内置的gzdecode和phar解压tar.gz
$content = file_get_contents($vendorTarPath);
$ungzipped = gzdecode($content);

if ($ungzipped === false) {
    die("❌ 解压gzip失败");
}

// 临时保存解压后的tar内容
$tarPath = __DIR__ . '/vendor.tmp';
file_put_contents($tarPath, $ungzipped);

// 使用Phar解压tar
$phar = new PharData($tarPath);
$phar->extractTo(__DIR__);

// 清理临时文件
unlink($tarPath);
unlink($vendorTarPath);

echo "✅ vendor目录解压成功！\n";
echo "✅ PHPMailer库已安装。\n";
echo "\n🎉 现在可以使用邮件发送功能了。\n";

// 测试邮件发送功能
require_once __DIR__ . '/test_email_send.php';
?>
