<?php
/**
 * ========================================
 * 数据库升级脚本 - v2.8.3.2
 * 功能：添加username字段到inventory_sessions表
 * ========================================
 */

require_once 'db.php';

$conn = getDBConnection();
if (!$conn) {
    die("数据库连接失败");
}

echo "<h2>升级到 v2.8.3.2</h2>";
echo "<p>添加盘点人显示功能...</p>";

try {
    // 检查字段是否已存在
    $check = $conn->query("SHOW COLUMNS FROM inventory_sessions LIKE 'username'");
    if ($check && $check->num_rows > 0) {
        echo "<p style='color:blue'>✓ username 字段已存在，跳过</p>";
    } else {
        // 添加username字段
        $sql = "ALTER TABLE `inventory_sessions`
                ADD COLUMN `username` VARCHAR(50) DEFAULT NULL
                COMMENT '盘点人用户名'
                AFTER `user_id`";

        if ($conn->query($sql)) {
            echo "<p style='color:green'>✓ 成功添加 username 字段</p>";
        } else {
            echo "<p style='color:red'>✗ 添加字段失败: " . $conn->error . "</p>";
        }
    }

    echo "<hr>";
    echo "<p style='color:green'><b>升级完成！</b></p>";
    echo "<p><a href='index.php'>返回系统</a></p>";

} catch (Exception $e) {
    echo "<p style='color:red'>✗ 升级失败: " . $e->getMessage() . "</p>";
}
?>
