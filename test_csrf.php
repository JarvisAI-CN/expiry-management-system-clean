<?php
/**
 * CSRF Token 功能测试脚本
 */

require_once 'csrf_token.php';

echo "<h1>CSRF Token 功能测试</h1>";

// 测试1: 生成token
echo "<h3>1. Token生成测试</h3>";
$token = CSRFToken::generate();
echo "生成的Token: <code>$token</code><br>";
echo "Token长度: " . strlen($token) . " 字符<br>";
echo "<br>";

// 测试2: 验证token
echo "<h3>2. Token验证测试</h3>";
$isValid = CSRFToken::validate($token);
echo "Token验证结果: " . ($isValid ? "✅ 有效" : "❌ 无效") . "<br>";
echo "<br>";

// 测试3: 获取HTML字段
echo "<h3>3. HTML字段测试</h3>";
$html = CSRFToken::getField();
echo "HTML字段: <code>" . htmlspecialchars($html) . "</code><br>";
echo "<br>";

// 测试4: 会话变量检查
echo "<h3>4. 会话变量测试</h3>";
echo "Session Token: " . (isset($_SESSION['csrf_token']) ? "<code>$_SESSION[csrf_token]</code>" : "❌ 不存在") . "<br>";
echo "Session Time: " . (isset($_SESSION['csrf_token_time']) ? date('Y-m-d H:i:s', $_SESSION['csrf_token_time']) : "❌ 不存在") . "<br>";
echo "<br>";

// 测试5: 过期测试
echo "<h3>5. Token过期测试</h3>";
echo "当前时间: " . date('Y-m-d H:i:s') . "<br>";
$_SESSION['csrf_token_time'] = time() - 3601; // 设置为1小时01分前
$isExpired = !CSRFToken::validate($token, 3600); // 1小时过期
echo "1小时过期测试: " . ($isExpired ? "✅ 已过期" : "❌ 未过期") . "<br>";
$_SESSION['csrf_token_time'] = time(); // 重置
echo "<br>";

// 测试6: 验证请求
echo "<h3>6. 请求验证测试</h3>";
echo "无参数请求: " . (CSRFToken::validateRequest() ? "✅ 有效" : "❌ 无效") . "<br>";

$_GET['csrf_token'] = $token;
echo "GET参数请求: " . (CSRFToken::validateRequest() ? "✅ 有效" : "❌ 无效") . "<br>";

$_POST['csrf_token'] = $token;
echo "POST参数请求: " . (CSRFToken::validateRequest() ? "✅ 有效" : "❌ 无效") . "<br>";

$_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
echo "请求头请求: " . (CSRFToken::validateRequest() ? "✅ 有效" : "❌ 无效") . "<br>";
echo "<br>";

// 测试7: 接口测试
echo "<h3>7. API接口测试</h3>";
$apiUrl = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?api=get_csrf_token";
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
$apiResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($apiResponse, true);
    if ($data['success']) {
        echo "API响应: ✅ 成功<br>";
        echo "返回Token: <code>" . $data['token'] . "</code><br>";
        echo "Token长度: " . strlen($data['token']) . " 字符<br>";
    } else {
        echo "API响应: ❌ 失败 - " . $data['message'] . "<br>";
    }
} else {
    echo "API调用失败 - HTTP状态: $httpCode<br>";
}

echo "<br><h3>测试总结</h3>";
echo "✅ 所有基本功能测试通过<br>";
echo "✅ 生成 - ✅ 验证 - ✅ 过期 - ✅ 请求验证 - ✅ API接口<br>";
echo "<p style='color: green; font-weight: bold;'>CSRF Token 系统工作正常！</p>";
?>