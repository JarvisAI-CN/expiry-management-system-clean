<?php
// 测试PHP到MySQL的连接
echo "Testing PHP to MySQL connection...\n";

// 方法1: 使用 mysqli 扩展
echo "\n1. Using mysqli extension:\n";
try {
    $conn = new mysqli('localhost', 'root', '', 'expiry_system');
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

// 方法2: 使用 PDO
echo "\n2. Using PDO:\n";
try {
    $pdo = new PDO('mysql:host=localhost;dbname=expiry_system;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "Successfully connected!\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM inventory_sessions");
    $count = $stmt->fetchColumn();
    echo "Number of inventory sessions: " . $count . "\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// 方法3: 使用 mysqli 面向过程方式
echo "\n3. Using mysqli procedural style:\n";
$conn = mysqli_connect('localhost', 'root', '', 'expiry_system');
if (!$conn) {
    echo "Connection failed: " . mysqli_connect_error() . "\n";
} else {
    echo "Successfully connected!\n";
    
    $result = mysqli_query($conn, "SELECT COUNT(*) FROM inventory_sessions");
    if ($result) {
        $row = mysqli_fetch_row($result);
        echo "Number of inventory sessions: " . $row[0] . "\n";
    } else {
        echo "Query failed: " . mysqli_error($conn) . "\n";
    }
    
    mysqli_close($conn);
}
?>
