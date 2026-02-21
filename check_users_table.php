<?php
/**
 * 检查用户表结构
 */
require_once 'db.php';
$conn = getDBConnection();

// 检查users表是否存在
echo "检查users表是否存在...<br>";
$tableResult = $conn->query("SHOW TABLES LIKE 'users'");
if ($tableResult->num_rows > 0) {
    echo "✅ users表存在<br><br>";
    
    // 获取users表结构
    $describeResult = $conn->query("DESCRIBE users");
    echo "<h3>users表结构:</h3>";
    echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse;'>";
    echo "<tr><th>字段名</th><th>类型</th><th>是否为空</th><th>默认值</th><th>备注</th></tr>";
    while ($row = $describeResult->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Default'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 检查是否有is_admin字段
    $adminColumnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'is_admin'");
    if ($adminColumnResult->num_rows > 0) {
        echo "<p style='color: green; margin-top: 10px;'>✅ is_admin字段存在</p>";
    } else {
        echo "<p style='color: red; margin-top: 10px;'>❌ is_admin字段不存在，需要添加</p>";
        
        // 尝试添加is_admin字段
        echo "<p>正在添加is_admin字段...</p>";
        $alterResult = $conn->query("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0");
        
        if ($alterResult) {
            echo "<p style='color: green;'>✅ is_admin字段添加成功</p>";
        } else {
            echo "<p style='color: red;'>❌ 字段添加失败: " . $conn->error . "</p>";
        }
    }
} else {
    echo "❌ users表不存在";
}

// 检查是否有管理员用户
echo "<h3>管理员用户检查:</h3>";
$adminResult = $conn->query("SELECT id, username, is_admin FROM users WHERE is_admin = 1");
if ($adminResult->num_rows > 0) {
    echo "<p style='color: green;'>✅ 找到管理员用户:</p>";
    echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>用户名</th><th>是否管理员</th></tr>";
    while ($row = $adminResult->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['username'] . "</td>";
        echo "<td>" . ($row['is_admin'] ? "是" : "否") . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>❌ 没有找到管理员用户，需要创建</p>";
    
    // 检查是否有用户
    $userResult = $conn->query("SELECT id, username FROM users LIMIT 10");
    if ($userResult->num_rows > 0) {
        echo "<p>现有用户:</p>";
        echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse;'>";
        echo "<tr><th>ID</th><th>用户名</th></tr>";
        while ($row = $userResult->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . $row['username'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<p>请选择一个用户设为管理员:</p>";
        echo "<form method='post'>";
        echo "<select name='user_id' required>";
        $userResult->data_seek(0); // 重置指针
        while ($row = $userResult->fetch_assoc()) {
            echo "<option value='" . $row['id'] . "'>" . $row['username'] . "</option>";
        }
        echo "</select>";
        echo "<input type='submit' name='set_admin' value='设为管理员'>";
        echo "</form>";
        
        if (isset($_POST['set_admin'])) {
            $userId = intval($_POST['user_id']);
            $updateResult = $conn->query("UPDATE users SET is_admin = 1 WHERE id = " . $userId);
            if ($updateResult) {
                echo "<p style='color: green;'>✅ 用户已设为管理员</p>";
                echo "<script>window.location.reload();</script>";
            } else {
                echo "<p style='color: red;'>❌ 操作失败: " . $conn->error . "</p>";
            }
        }
    } else {
        echo "<p>没有用户数据，请先通过系统创建用户</p>";
    }
}
?>
