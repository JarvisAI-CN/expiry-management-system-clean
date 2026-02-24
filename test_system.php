<?php
/**
 * 星巴克门店智能效期管理系统 - 系统测试脚本
 * 用于验证系统核心功能是否正常运行
 */

require_once 'core/Database.php';
require_once 'core/AuthService.php';
require_once 'core/ImportService.php';
require_once 'core/AIService.php';

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

// 测试2: 数据库连接
$testResults['database'] = [
    'name' => '数据库连接',
    'status' => 'fail',
    'message' => ''
];

if ($testResults['config']['status'] === 'pass') {
    try {
        $db = new Database();
        $pdo = $db->connect();
        $testResults['database']['status'] = 'pass';
        $testResults['database']['message'] = '成功连接到 MySQL 数据库';
    } catch (Exception $e) {
        $testResults['database']['message'] = '连接失败：' . $e->getMessage();
    }
}

// 测试3: 数据库结构
$testResults['schema'] = [
    'name' => '数据库结构',
    'status' => 'fail',
    'message' => ''
];

if ($testResults['database']['status'] === 'pass') {
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

// 测试4: 核心服务类
$testResults['services'] = [
    'name' => '核心服务类',
    'status' => 'fail',
    'message' => ''
];

$servicesToCheck = ['AuthService', 'Database', 'ImportService', 'AIService'];
$missingServices = [];

foreach ($servicesToCheck as $service) {
    $fileName = 'core/' . $service . '.php';
    if (!file_exists($fileName)) {
        $missingServices[] = $service;
    }
}

if (empty($missingServices)) {
    $testResults['services']['status'] = 'pass';
    $testResults['services']['message'] = '所有核心服务类均存在';
} else {
    $testResults['services']['status'] = 'fail';
    $testResults['services']['message'] = '缺少服务类：' . implode(', ', $missingServices);
}

// 测试5: 用户认证
$testResults['auth'] = [
    'name' => '用户认证系统',
    'status' => 'fail',
    'message' => ''
];

if ($testResults['database']['status'] === 'pass') {
    try {
        $authService = new AuthService();
        $user = $authService->getCurrentUser($pdo);
        
        // 检查默认用户是否存在
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
        $stmt->execute([1]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            $testResults['auth']['status'] = 'pass';
            $testResults['auth']['message'] = '默认管理员用户存在';
        } else {
            $testResults['auth']['status'] = 'warn';
            $testResults['auth']['message'] = '未找到默认管理员用户';
        }
    } catch (Exception $e) {
        $testResults['auth']['message'] = '错误：' . $e->getMessage();
    }
}

// 测试6: 分类和产品
$testResults['data'] = [
    'name' => '基础数据',
    'status' => 'fail',
    'message' => ''
];

if ($testResults['database']['status'] === 'pass') {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM categories");
        $categoryCount = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM products");
        $productCount = $stmt->fetchColumn();
        
        if ($categoryCount > 0) {
            $testResults['data']['status'] = 'pass';
            $testResults['data']['message'] = '找到 ' . $categoryCount . ' 个分类，' . $productCount . ' 个产品';
        } else {
            $testResults['data']['status'] = 'warn';
            $testResults['data']['message'] = '分类表为空，需要初始化基础数据';
        }
    } catch (Exception $e) {
        $testResults['data']['message'] = '错误：' . $e->getMessage();
    }
}

// 测试7: 数据导入功能
$testResults['import'] = [
    'name' => '数据导入功能',
    'status' => 'fail',
    'message' => ''
];

if ($testResults['database']['status'] === 'pass') {
    try {
        $importService = new ImportService($pdo);
        $testResults['import']['status'] = 'pass';
        $testResults['import']['message'] = '导入服务初始化成功';
    } catch (Exception $e) {
        $testResults['import']['message'] = '错误：' . $e->getMessage();
    }
}

// 测试8: AI 服务
$testResults['ai'] = [
    'name' => 'AI 服务',
    'status' => 'fail',
    'message' => ''
];

try {
    $aiService = new AIService();
    $testResults['ai']['status'] = 'pass';
    $testResults['ai']['message'] = 'AI 服务初始化成功';
} catch (Exception $e) {
    $testResults['ai']['status'] = 'warn';
    $testResults['ai']['message'] = 'AI 服务配置可能未设置：' . $e->getMessage();
}

// 测试9: 文件上传目录权限
$testResults['upload'] = [
    'name' => '文件上传权限',
    'status' => 'fail',
    'message' => ''
];

$uploadDir = 'public/uploads';
if (!is_dir($uploadDir)) {
    $testResults['upload']['status'] = 'fail';
    $testResults['upload']['message'] = '上传目录不存在';
} elseif (!is_writable($uploadDir)) {
    $testResults['upload']['status'] = 'fail';
    $testResults['upload']['message'] = '上传目录不可写';
} else {
    $testResults['upload']['status'] = 'pass';
    $testResults['upload']['message'] = '上传目录权限正常';
}

// 测试10: 页面文件
$testResults['pages'] = [
    'name' => '页面文件',
    'status' => 'fail',
    'message' => ''
];

$requiredPages = ['login.php', 'index.php', 'dashboard.php', 'stocktake.php', 'admin/import_todo.php', 'admin/categories.php', 'admin/products.php'];
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

// 输出测试报告
echo "星巴克门店智能效期管理系统 - 系统测试报告\n";
echo "===============================================\n";
echo "测试时间：" . date('Y-m-d H:i:s') . "\n";
echo "PHP 版本：" . PHP_VERSION . "\n";
echo "系统版本：3.0.0\n";
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
    echo "可以通过浏览器访问 login.php 开始使用系统\n";
} else {
    echo "❌ 系统检查发现 " . $failedTests . " 个失败项，请修复后重新测试\n";
}

if ($warnTests > 0) {
    echo "⚠️ 系统检查发现 " . $warnTests . " 个警告项，系统可以使用但某些功能可能受限\n";
}

// 输出详细信息（如果有）
if ($testResults['config']['status'] === 'fail') {
    echo "\n";
    echo "ℹ️  系统未安装，需要通过浏览器访问 install.php 完成安装\n";
} elseif ($testResults['schema']['status'] === 'fail') {
    echo "\n";
    echo "ℹ️  数据库结构不完整，需要重新导入 schema.sql 文件\n";
}
