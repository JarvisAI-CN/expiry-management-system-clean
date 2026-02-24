<?php
/**
 * 星巴克门店智能效期管理系统 - 系统测试脚本（修复版）
 * 用于验证系统核心功能是否正常运行
 */

// 测试报告
$testResults = [];

// 测试1: 检查配置文件
$testResults['config'] = [
    'name' => '配置文件检查',
    'status' => 'fail',
    'message' => ''
];

if (file_exists('config/database.php')) {
    $dbConfig = include 'config/database.php';
    if (is_array($dbConfig) && isset($dbConfig['host'], $dbConfig['name'], $dbConfig['user'], $dbConfig['pass'])) {
        $testResults['config']['status'] = 'pass';
        $testResults['config']['message'] = '数据库配置文件存在且格式正确';
    } else {
        $testResults['config']['message'] = '数据库配置文件格式错误';
    }
} else {
    $testResults['config']['message'] = '数据库配置文件不存在';
}

// 测试2: 检查核心类文件
$testResults['core_classes'] = [
    'name' => '核心类文件检查',
    'status' => 'fail',
    'message' => ''
];

$requiredClasses = ['Database', 'AuthService', 'ImportService', 'AIService', 'EmailService'];
$missingClasses = [];

foreach ($requiredClasses as $className) {
    $fileName = 'core/' . $className . '.php';
    if (!file_exists($fileName)) {
        $missingClasses[] = $className;
    }
}

if (empty($missingClasses)) {
    $testResults['core_classes']['status'] = 'pass';
    $testResults['core_classes']['message'] = '所有核心类文件均存在';
} else {
    $testResults['core_classes']['status'] = 'fail';
    $testResults['core_classes']['message'] = '缺少类文件：' . implode(', ', $missingClasses);
}

// 测试3: 检查页面文件
$testResults['pages'] = [
    'name' => '页面文件检查',
    'status' => 'fail',
    'message' => ''
];

$requiredPages = ['login.php', 'dashboard.php', 'stocktake.php', 'admin/import_todo.php', 'admin/categories.php', 'admin/products.php'];
$missingPages = [];

foreach ($requiredPages as $page) {
    if (!file_exists($page)) {
        $missingPages[] = $page;
    }
}

if (empty($missingPages)) {
    $testResults['pages']['status'] = 'pass';
    $testResults['pages']['message'] = '所有必要页面文件均存在';
} else {
    $testResults['pages']['status'] = 'fail';
    $testResults['pages']['message'] = '缺少页面文件：' . implode(', ', $missingPages);
}

// 测试4: 检查上传目录权限
$testResults['upload'] = [
    'name' => '文件上传权限',
    'status' => 'fail',
    'message' => ''
];

$uploadDir = 'public/uploads';
if (!is_dir($uploadDir)) {
    if (mkdir($uploadDir, 0755, true)) {
        $testResults['upload']['status'] = 'pass';
        $testResults['upload']['message'] = '上传目录已创建，权限正常';
    } else {
        $testResults['upload']['status'] = 'fail';
        $testResults['upload']['message'] = '无法创建上传目录';
    }
} elseif (!is_writable($uploadDir)) {
    $testResults['upload']['status'] = 'fail';
    $testResults['upload']['message'] = '上传目录不可写';
} else {
    $testResults['upload']['status'] = 'pass';
    $testResults['upload']['message'] = '上传目录权限正常';
}

// 测试5: 检查数据库连接
$testResults['database'] = [
    'name' => '数据库连接检查',
    'status' => 'fail',
    'message' => ''
];

if ($testResults['config']['status'] === 'pass') {
    $dbConfig = include 'config/database.php';
    
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8mb4";
    
    try {
        $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $testResults['database']['status'] = 'pass';
        $testResults['database']['message'] = '成功连接到 MySQL 数据库';
    } catch (Exception $e) {
        $testResults['database']['message'] = '连接失败：' . $e->getMessage();
    }
}

// 测试6: 检查数据库结构
if (isset($pdo)) {
    $testResults['schema'] = [
        'name' => '数据库结构检查',
        'status' => 'fail',
        'message' => ''
    ];
    
    try {
        // 检查核心表是否存在
        $tablesToCheck = ['users', 'categories', 'products', 'stocktake_sessions', 'stocktake_items', 'import_todo'];
        $missingTables = [];
        
        foreach ($tablesToCheck as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() === 0) {
                $missingTables[] = $table;
            }
        }
        
        if (empty($missingTables)) {
            $testResults['schema']['status'] = 'pass';
            $testResults['schema']['message'] = '所有核心表均存在';
        } else {
            $testResults['schema']['status'] = 'fail';
            $testResults['schema']['message'] = '缺少表：' . implode(', ', $missingTables);
        }
    } catch (Exception $e) {
        $testResults['schema']['message'] = '查询失败：' . $e->getMessage();
    }
}

// 输出测试报告
echo "星巴克门店智能效期管理系统 - 系统测试报告\n";
echo "===============================================\n";
echo "测试时间：" . date('Y-m-d H:i:s') . "\n";
echo "PHP 版本：" . PHP_VERSION . "\n";
echo "系统版本：3.0.5\n";
echo "\n";

$passedTests = 0;
$failedTests = 0;
$warnTests = 0;

foreach ($testResults as $testKey => $testResult) {
    $statusIcon = '';
    $statusText = '';
    
    if ($testResult['status'] === 'pass') {
        $statusIcon = '✅';
        $statusText = '通过';
        $passedTests++;
    } elseif ($testResult['status'] === 'fail') {
        $statusIcon = '❌';
        $statusText = '失败';
        $failedTests++;
    } else {
        $statusIcon = '⚠️';
        $statusText = '警告';
        $warnTests++;
    }
    
    printf("%-20s %s %-10s %s\n", $testResult['name'], $statusIcon, $statusText, $testResult['message']);
}

echo "\n";
echo "测试统计：\n";
echo "总测试数：" . count($testResults) . "\n";
echo "通过：" . $passedTests . "\n";
echo "失败：" . $failedTests . "\n";
echo "警告：" . $warnTests . "\n";
echo "\n";

if ($failedTests === 0) {
    echo "✅ 系统检查完成！所有核心功能正常运行\n";
    if (file_exists('install.php')) {
        echo "可以通过浏览器访问 install.php 完成系统安装\n";
    } else {
        echo "可以通过浏览器访问 login.php 开始使用系统\n";
    }
} else {
    echo "❌ 系统检查发现 " . $failedTests . " 个失败项，请修复后重新测试\n";
}
