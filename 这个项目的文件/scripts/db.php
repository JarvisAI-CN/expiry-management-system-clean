<?php
/**
 * ========================================
 * 保质期管理系统 - 数据库连接文件
 * 文件名: db.php
 * ========================================
 */

// 检查是否安装
if (!file_exists(__DIR__ . '/config.php')) {
    if (basename($_SERVER['PHP_SELF']) !== 'install.php') {
        header("Location: install.php");
        exit;
    }
} else {
    require_once __DIR__ . '/config.php';
}

// 错误报告设置
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * 获取数据库连接（单例模式）
 */
function getDBConnection() {
    static $conn = null;
    if ($conn !== null) return $conn;
    
    if (!defined('DB_HOST')) return false;

    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) return false;
        $conn->set_charset(DB_CHARSET);
        return $conn;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 获取设置项
 */
function getSetting($key, $default = '') {
    $conn = getDBConnection();
    if (!$conn) return $default;
    
    $stmt = $conn->prepare("SELECT s_value FROM settings WHERE s_key = ? LIMIT 1");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['s_value'];
    }
    return $default;
}

/**
 * 保存设置项
 */
function setSetting($key, $value) {
    $conn = getDBConnection();
    if (!$conn) return false;
    
    $stmt = $conn->prepare("INSERT INTO settings (s_key, s_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE s_value = VALUES(s_value)");
    $stmt->bind_param("ss", $key, $value);
    return $stmt->execute();
}

/**
 * 检查登录状态
 */
function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        if (isset($_GET['api'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        return false;
    }
    return true;
}

/**
 * 记录操作日志
 */
function addLog($action, $details = '') {
    $conn = getDBConnection();
    if (!$conn) return false;
    
    $uid = $_SESSION['user_id'] ?? 0;
    $stmt = $conn->prepare("INSERT INTO logs (user_id, action, details) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $uid, $action, $details);
    return $stmt->execute();
}

function escapeValue($conn, $value) {
    return $conn->real_escape_string($value);
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function logError($message, $context = null) {
    $logMessage = date('[Y-m-d H:i:s] ') . $message;
    if ($context !== null) {
        $logMessage .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    error_log($logMessage);
}
