<?php
/**
 * 直接设置admin用户为管理员
 */
require_once 'db.php';
$conn = getDBConnection();

// 直接将admin用户设为管理员
$updateStmt = $conn->prepare("UPDATE users SET is_admin = 1 WHERE username = ?");
$adminUsername = 'admin';
$updateStmt->bind_param("s", $adminUsername);

if ($updateStmt->execute()) {
    echo "<h3 style='color: green;'>✅ 成功将 $adminUsername 用户设为管理员</h3>";
} else {
    echo "<h3 style='color: red;'>❌ 设置失败: " . $conn->error . "</h3>";
}

// 验证设置结果
$checkStmt = $conn->prepare("SELECT id, username, is_admin FROM users WHERE username = ?");
$checkStmt->bind_param("s", $adminUsername);
$checkStmt->execute();
$result = $checkStmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo "<p>用户ID: " . $row['id'] . "</p>";
    echo "<p>用户名: " . $row['username'] . "</p>";
    echo "<p>是否管理员: " . ($row['is_admin'] ? "是" : "否") . "</p>";
} else {
    echo "<h3 style='color: red;'>❌ 未找到用户: $adminUsername</h3>";
}

// 显示所有用户信息
echo "<h3>所有用户信息:</h3>";
$allUsersStmt = $conn->prepare("SELECT id, username, is_admin, role FROM users");
$allUsersStmt->execute();
$allUsersResult = $allUsersStmt->get_result();

echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>用户名</th><th>是否管理员</th><th>角色</th></tr>";
while ($row = $allUsersResult->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['id'] . "</td>";
    echo "<td>" . $row['username'] . "</td>";
    echo "<td>" . ($row['is_admin'] ? "✅" : "❌") . "</td>";
    echo "<td>" . $row['role'] . "</td>";
    echo "</tr>";
}
echo "</table>";
?>
