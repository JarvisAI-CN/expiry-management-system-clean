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

// 初始化服务
$db = new Database();
$pdo = $db->connect();
$authService = new AuthService();

// 检查用户是否登录
if (!$authService->isAuthenticated()) {
    header('Location: login.php');
    exit;
}

// 获取当前用户
$user = $authService->getCurrentUser($pdo);

// 重定向到仪表板
header('Location: dashboard.php');
exit;
