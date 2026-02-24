<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 系统入口文件
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

// 检查系统是否已安装
if (!file_exists('install.lock')) {
    header('Location: install.php');
    exit;
}

// 启动会话
session_start();

// 加载核心文件
require_once 'core/Database.php';
require_once 'core/AuthService.php';
require_once 'core/MigrationManager.php';

// 加载数据库配置
$config = include 'config/database.php';

// 初始化服务
$db = new Database($config);
$pdo = $db->getConnection();

// 创建鉴权服务
$authConfig = [
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => isset($_SERVER['HTTPS'])
];
$authService = new AuthService($pdo, $authConfig);

// 自动检查和应用数据库迁移
$migrationManager = new MigrationManager($pdo, __DIR__ . '/migrations');
$migrationResult = $migrationManager->checkAndApplyMigrations();

// 记录迁移结果
if (!$migrationResult['success']) {
    error_log('数据库迁移失败：' . $migrationResult['message']);
}

// 检查用户是否登录
if (!$authService->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// 获取当前用户
$user = $authService->getCurrentUser();

// 重定向到仪表板
header('Location: dashboard.php');
exit;
