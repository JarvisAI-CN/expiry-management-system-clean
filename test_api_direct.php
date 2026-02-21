<?php
/**
 * ========================================
 * 编辑盘点单功能 - API测试脚本（简化版）
 * 文件名: test_api_direct.php
 * 版本: v1.0.0
 * ========================================
 */

session_start();
require_once 'db.php';

// 测试结果记录
$testResults = [
    'total' => 0,
    'passed' => 0,
    'failed' => 0,
    'tests' => []
];

/**
 * 记录测试结果
 */
function recordTest($name, $passed, $message = '') {
    global $testResults;
    $testResults['total']++;
    if ($passed) {
        $testResults['passed']++;
        $status = '✅ PASS';
    } else {
        $testResults['failed']++;
        $status = '❌ FAIL';
    }

    $testResults['tests'][] = [
        'name' => $name,
        'status' => $status,
        'message' => $message
    ];
}

echo "<!DOCTYPE html>
<html lang='zh-CN'>
<head>
    <meta charset='UTF-8'>
    <title>编辑盘点单功能 - API测试报告</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css' rel='stylesheet'>
    <style>
        body { padding: 20px; background: #f5f5f7; }
        .test-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .test-pass { color: #198754; }
        .test-fail { color: #dc3545; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 6px; }
    </style>
</head>
<body>
    <div class='container'>
        <h1 class='mb-4'>🧪 编辑盘点单功能 - API测试报告</h1>";

$conn = getDBConnection();

// 测试1: 数据库连接
if ($conn) {
    recordTest('数据库连接', true, '数据库连接成功');
} else {
    recordTest('数据库连接', false, '无法连接数据库');
    echo '<p class="text-danger">数据库连接失败，无法继续测试</p>';
    exit;
}

// 测试2: 检查数据库表是否存在
$tables = ['inventory_edit_logs', 'inventory_sessions', 'batches', 'products', 'users'];
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        recordTest("表 $table 存在性", true);
    } else {
        recordTest("表 $table 存在性", false, '表不存在');
    }
}

// 测试3: 检查inventory_edit_logs表结构
$columnResult = $conn->query("SHOW COLUMNS FROM inventory_edit_logs");
if ($columnResult && $columnResult->num_rows >= 7) {
    recordTest('审计日志表结构', true, 'inventory_edit_logs表结构正确');
} else {
    recordTest('审计日志表结构', false, 'inventory_edit_logs表结构不完整');
}

// 测试4: 检查batches表的updated_at字段
$columnResult = $conn->query("SHOW COLUMNS FROM batches LIKE 'updated_at'");
if ($columnResult && $columnResult->num_rows > 0) {
    recordTest('batches.updated_at字段', true, '字段存在');
} else {
    recordTest('batches.updated_at字段', false, '字段不存在');
}

// 测试5: 检查users表的is_admin字段
$columnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'is_admin'");
if ($columnResult && $columnResult->num_rows > 0) {
    recordTest('users.is_admin字段', true, '字段存在');
} else {
    recordTest('users.is_admin字段', false, '字段不存在');
}

// 测试6: 检查是否有管理员用户
$adminResult = $conn->query("SELECT COUNT(*) as count FROM users WHERE is_admin = 1");
if ($adminResult && $row = $adminResult->fetch_assoc()) {
    if ($row['count'] > 0) {
        recordTest('管理员用户', true, "找到 {$row['count']} 个管理员用户");
    } else {
        recordTest('管理员用户', false, '没有管理员用户');
    }
}

// 测试7: 检查是否有盘点单数据
$sessionResult = $conn->query("SELECT COUNT(*) as count FROM inventory_sessions");
if ($sessionResult && $row = $sessionResult->fetch_assoc()) {
    if ($row['count'] > 0) {
        recordTest('盘点单数据', true, "找到 {$row['count']} 个盘点单");
    } else {
        recordTest('盘点单数据', false, '没有盘点单数据');
    }
}

// 测试8: 检查审计日志
$auditResult = $conn->query("SELECT COUNT(*) as count FROM inventory_edit_logs");
if ($auditResult && $row = $auditResult->fetch_assoc()) {
    recordTest('审计日志记录', true, "找到 {$row['count']} 条审计日志");
} else {
    recordTest('审计日志记录', false, '无法查询审计日志');
}

// 显示测试概要
echo "<div class='test-card'>
    <h3>测试概要</h3>
    <p>总测试数: <strong>{$testResults['total']}</strong></p>
    <p class='test-pass'>通过: <strong>{$testResults['passed']}</strong></p>
    <p class='test-fail'>失败: <strong>{$testResults['failed']}</strong></p>
</div>";

// 显示详细结果
echo "<div class='test-card'>
    <h3>详细测试结果</h3>
    <table class='table table-striped'>
        <thead>
            <tr>
                <th>测试名称</th>
                <th>状态</th>
                <th>备注</th>
            </tr>
        </thead>
        <tbody>";

foreach ($testResults['tests'] as $test) {
    $statusClass = strpos($test['status'], 'PASS') !== false ? 'test-pass' : 'test-fail';
    echo "<tr>
        <td>{$test['name']}</td>
        <td class='{$statusClass}'><strong>{$test['status']}</strong></td>
        <td>{$test['message']}</td>
    </tr>";
}

echo "  </tbody>
    </table>
</div>";

// 测试建议
echo "<div class='test-card'>
    <h3>测试建议</h3>
    <ul>";

if ($testResults['failed'] == 0) {
    echo "<li class='test-pass'>✅ 所有基础测试通过！数据库结构正确。</li>
          <li>建议进行手动功能测试。</li>
          <li>建议测试API端点的实际调用。</li>";
} else {
    echo "<li class='test-fail'>❌ 存在失败的测试，请检查数据库升级。</li>";
}

echo "  </ul>
</div>";

// 使用说明
echo "<div class='test-card'>
    <h3>📖 使用说明</h3>
    <ol>
        <li><strong>进入编辑模式</strong>: 在&quot;查看往期盘点&quot;页面，点击任意盘点单的&quot;编辑&quot;按钮</li>
        <li><strong>修改商品信息</strong>: 在编辑界面中，修改有效期或数量，点击&quot;保存&quot;按钮</li>
        <li><strong>删除商品</strong>: 点击商品行的&quot;删除&quot;按钮</li>
        <li><strong>添加商品</strong>: 点击&quot;添加商品&quot;按钮，扫描条码添加新商品</li>
        <li><strong>完成编辑</strong>: 点击&quot;完成编辑&quot;返回往期盘点列表</li>
    </ol>
</div>";

echo "
    </div>
</body>
</html>";
?>
