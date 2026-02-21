<?php
// 测试PHP到MySQL的连接 - 使用新创建的用户
echo "Testing PHP to MySQL connection (php_user)...\n";

// 使用 mysqli 扩展
echo "\nUsing mysqli extension:\n";
try {
    $conn = new mysqli('localhost', 'php_user', 'php_password', 'expiry_system');
    if ($conn->connect_error) {
        echo "Connection failed: " . $conn->connect_error . "\n";
    } else {
        echo "Successfully connected!\n";
        
        // 测试查询
        $result = $conn->query("SELECT COUNT(*) FROM inventory_sessions");
        if ($result) {
            $row = $result->fetch_row();
            echo "Number of inventory sessions: " . $row[0] . "\n";
        } else {
            echo "Query failed: " . $conn->error . "\n";
        }
        
        $conn->close();
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
