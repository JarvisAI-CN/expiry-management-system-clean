<?php
/**
 * CSRF Token 生成和验证类
 * 用于防止跨站请求伪造攻击
 */

class CSRFToken {
    
    /**
     * 生成CSRF token
     * 
     * @return string
     */
    public static function generate() {
        if (!session_id()) {
            session_start();
        }
        
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();
        
        return $token;
    }
    
    /**
     * 验证CSRF token
     * 
     * @param string $token
     * @param int $expire 过期时间（秒），默认3600秒（1小时）
     * @return bool
     */
    public static function validate($token, $expire = 3600) {
        if (!session_id()) {
            session_start();
        }
        
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        
        if ($_SESSION['csrf_token'] !== $token) {
            return false;
        }
        
        if (time() - $_SESSION['csrf_token_time'] > $expire) {
            unset($_SESSION['csrf_token']);
            unset($_SESSION['csrf_token_time']);
            return false;
        }
        
        return true;
    }
    
    /**
     * 获取CSRF token HTML字段
     * 
     * @return string
     */
    public static function getField() {
        $token = self::generate();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
    
    /**
     * 验证从请求中获取的CSRF token
     * 
     * @return bool
     */
    public static function validateRequest() {
        $token = '';
        
        if (isset($_POST['csrf_token'])) {
            $token = $_POST['csrf_token'];
        } elseif (isset($_GET['csrf_token'])) {
            $token = $_GET['csrf_token'];
        } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        
        return self::validate($token);
    }
}

// 使用示例
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    // 测试CSRF token功能
    session_start();
    
    echo "<h3>CSRF Token 测试</h3>";
    
    // 生成token
    $token = CSRFToken::generate();
    echo "生成的Token: " . $token . "<br>";
    
    // 验证token
    $isValid = CSRFToken::validate($token);
    echo "验证结果: " . ($isValid ? "✅ 有效" : "❌ 无效") . "<br>";
    
    // 验证请求
    echo "请求验证: " . (CSRFToken::validateRequest() ? "✅ 有效" : "❌ 无效") . "<br>";
    
    // 获取HTML字段
    echo "HTML字段: " . CSRFToken::getField() . "<br>";
}
?>