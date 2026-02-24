<?php
/**
 * 星巴克门店智能效期管理系统 - 安装测试脚本
 * 用于模拟系统安装过程，检查基础配置是否正常
 */

require_once 'core/Database.php';

echo "===============================================\n";
echo "星巴克门店智能效期管理系统 - 安装测试\n";
echo "===============================================\n";
echo "测试时间：" . date('Y-m-d H:i:s') . "\n";
echo "PHP 版本：" . PHP_VERSION . "\n";
echo "\n";

// 测试配置文件目录
if (!is_dir('config')) {
    echo "📁 创建配置目录\n";
    if (!mkdir('config', 0755, true)) {
        die("❌ 无法创建 config 目录\n");
    }
}

// 创建临时配置文件
echo "🔧 创建临时配置文件\n";
$tempConfig = [
    'host' => 'localhost',
    'name' => 'expiry_guard',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
    'prefix' => '',
];

if (!file_put_contents('config/database.php', "<?php return " . var_export($tempConfig, true) . ";\n")) {
    die("❌ 无法创建配置文件\n");
}

echo "✅ 临时配置文件创建成功\n";
echo "\n";

// 检查数据库连接（测试）
echo "🔌 测试数据库连接\n";

try {
    $db = new Database($tempConfig);
    $pdo = $db->getConnection();
    
    echo "✅ 数据库连接成功\n";
    
    // 检查数据库是否存在
    $stmt = $pdo->query("SHOW DATABASES LIKE 'expiry_guard'");
    if ($stmt->rowCount() === 0) {
        echo "⚠️  数据库不存在，请通过 install.php 完成安装\n";
    } else {
        echo "✅ 数据库存在\n";
        
        // 检查表结构
        $tables = ['users', 'categories', 'products', 'stocktake_sessions', 'stocktake_items', 'import_todo'];
        $missingTables = [];
        
        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() === 0) {
                $missingTables[] = $table;
            }
        }
        
        if (empty($missingTables)) {
            echo "✅ 所有核心表均存在\n";
        } else {
            echo "⚠️  缺少表：" . implode(', ', $missingTables) . "\n";
            echo "   需要通过 install.php 重新导入 schema.sql\n";
        }
    }
    
} catch (Exception $e) {
    echo "⚠️  数据库连接失败：" . $e->getMessage() . "\n";
    echo "   需要通过 install.php 完成安装\n";
}

echo "\n";
echo "📊 测试完成！\n";
echo "\n";

if (!file_exists('install.lock')) {
    echo "⚠️  系统未正式安装，需要通过浏览器访问 install.php 完成\n";
    echo "✅ 系统基本文件结构已经完整\n";
} else {
    echo "✅ 系统已安装，可以通过浏览器访问 login.php 登录\n";
}
