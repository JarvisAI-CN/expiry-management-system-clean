<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Mock getDBConnection for testing
function getDBConnection() {
    return new mysqli('localhost', 'root', '', 'test_sandbox');
}

function testMigration() {
    $conn = new mysqli('localhost', 'root', '');
    $conn->query("CREATE DATABASE IF NOT EXISTS test_sandbox");
    $conn->select_db('test_sandbox');
    $conn->query("DROP TABLE IF EXISTS products");
    $conn->query("CREATE TABLE products (id INT AUTO_INCREMENT PRIMARY KEY, sku VARCHAR(50), name VARCHAR(50))");
    
    echo "Starting test migration...\n";
    
    // Test the logic I used in index.php
    $cols = [
        'products' => [
            'category_id' => 'INT(11) UNSIGNED DEFAULT 0 AFTER id',
            'inventory_cycle' => "VARCHAR(20) DEFAULT 'none' AFTER removal_buffer", // Note: This might fail if removal_buffer doesn't exist yet
            'last_inventory_at' => "DATETIME DEFAULT NULL AFTER inventory_cycle"
        ]
    ];

    // Fix dependency order for test: ensure removal_buffer exists
    $conn->query("ALTER TABLE products ADD COLUMN removal_buffer INT DEFAULT 0");

    foreach($cols as $table => $fields) {
        foreach($fields as $col => $def) {
            echo "Checking $table.$col...\n";
            $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
            if ($res && $res->num_rows == 0) { 
                echo "Applying: ALTER TABLE `$table` ADD COLUMN `$col` $def\n";
                if (!$conn->query("ALTER TABLE `$table` ADD COLUMN `$col` $def")) {
                    die("❌ Migration failed: " . $conn->error);
                }
            }
        }
    }
    echo "✅ Migration Logic Passed!\n";
}

testMigration();
?>
