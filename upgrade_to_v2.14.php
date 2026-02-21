<?php
/**
 * ========================================
 * 保质期管理系统 - 邮箱配置功能升级脚本
 * 版本: v2.14.0
 * 创建日期: 2026-02-21
 * ========================================
 * 
 * 用途: 为已安装系统添加邮箱账户配置功能
 * 使用: 访问此文件执行升级，升级后请删除
 * 
 */

session_start();
require_once __DIR__ . '/db.php';

// 权限检查
if (!isset($_SESSION['user_id'])) {
    die("请先<a href='index.php'>登录</a>后再执行升级");
}

$success = false;
$error = '';
$steps = [];

// 检查是否已升级
if (file_exists(__DIR__ . '/upgrade_v2.14.lock')) {
    $error = "系统已升级到 v2.14.0。如需重新升级，请删除 upgrade_v2.14.lock 文件。";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $conn = getDBConnection();
    
    if (!$conn) {
        $error = "数据库连接失败";
    } else {
        try {
            // 步骤1: 创建email_accounts表
            $steps[] = "正在创建邮箱账户表...";
            $sql1 = "CREATE TABLE IF NOT EXISTS `email_accounts` (
              `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '账户ID',
              `qq_number` VARCHAR(20) NOT NULL COMMENT 'QQ号',
              `email_address` VARCHAR(100) NOT NULL COMMENT '完整邮箱地址',
              `auth_code_encrypted` TEXT NOT NULL COMMENT '加密后的授权码',
              `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用: 1=启用, 0=禁用',
              `priority` INT(11) NOT NULL DEFAULT 0 COMMENT '优先级 (数字越大优先级越高)',
              `send_count` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计发送次数',
              `last_sent_at` DATETIME DEFAULT NULL COMMENT '最后发送时间',
              `last_sent_success` TINYINT(1) DEFAULT NULL COMMENT '最后发送是否成功',
              `error_message` TEXT DEFAULT NULL COMMENT '最后的错误信息',
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
              `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
              `created_by` INT(11) UNSIGNED DEFAULT NULL COMMENT '创建人ID',
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_qq_number` (`qq_number`),
              KEY `idx_active_priority` (`is_active`, `priority` DESC, `send_count` ASC, `last_sent_at` ASC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮箱账户配置表'";
            
            if (!$conn->query($sql1)) {
                throw new Exception("创建email_accounts表失败: " . $conn->error);
            }
            $steps[] = "✅ 邮箱账户表创建成功";
            
            // 步骤2: 创建email_logs表
            $steps[] = "正在创建邮件日志表...";
            $sql2 = "CREATE TABLE IF NOT EXISTS `email_logs` (
              `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '日志ID',
              `account_id` INT(11) UNSIGNED NOT NULL COMMENT '邮箱账户ID',
              `recipient` VARCHAR(200) NOT NULL COMMENT '收件人邮箱',
              `subject` VARCHAR(500) NOT NULL COMMENT '邮件主题',
              `body` TEXT DEFAULT NULL COMMENT '邮件正文',
              `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending' COMMENT '发送状态',
              `error_message` TEXT DEFAULT NULL COMMENT '错误信息',
              `sent_at` DATETIME DEFAULT NULL COMMENT '发送时间',
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
              PRIMARY KEY (`id`),
              KEY `idx_account_status` (`account_id`, `status`),
              KEY `idx_sent_at` (`sent_at`),
              CONSTRAINT `fk_email_logs_account` FOREIGN KEY (`account_id`) REFERENCES `email_accounts` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮件发送日志表'";
            
            if (!$conn->query($sql2)) {
                throw new Exception("创建email_logs表失败: " . $conn->error);
            }
            $steps[] = "✅ 邮件日志表创建成功";
            
            // 步骤3: 添加默认设置
            $steps[] = "正在添加系统设置...";
            $conn->query("INSERT IGNORE INTO `settings` (`s_key`, `s_value`) VALUES 
                ('email_smtp_host', 'smtp.qq.com'),
                ('email_smtp_port', '465'),
                ('email_smtp_encryption', 'ssl'),
                ('email_cooldown_seconds', '300')
            ");
            $steps[] = "✅ 系统设置添加成功";
            
            // 步骤4: 更新或创建EMAIL_ENCRYPTION_KEY
            $steps[] = "正在配置加密密钥...";
            $configPath = __DIR__ . '/config.php';
            $configContent = file_get_contents($configPath);
            
            // 检查是否已存在EMAIL_ENCRYPTION_KEY
            if (strpos($configContent, 'EMAIL_ENCRYPTION_KEY') === false) {
                // 生成随机密钥
                $encryptionKey = bin2hex(random_bytes(16));
                
                // 在config.php末尾添加
                $newLine = "\ndefine('EMAIL_ENCRYPTION_KEY', '$encryptionKey');\n";
                $newLine .= "// EMAIL_ENCRYPTION_KEY 用于加密邮箱授权码，请妥善保管此密钥\n";
                
                if (file_put_contents($configPath, $newLine, FILE_APPEND)) {
                    $steps[] = "✅ 加密密钥配置成功";
                } else {
                    throw new Exception("无法写入config.php，请检查文件权限");
                }
            } else {
                $steps[] = "⚠️  加密密钥已存在，跳过";
            }
            
            // 步骤5: 更新版本号
            $steps[] = "正在更新版本号...";
            file_put_contents(__DIR__ . '/VERSION.txt', "2.14.0");
            $steps[] = "✅ 版本号更新为 v2.14.0";
            
            // 创建升级锁文件
            file_put_contents(__DIR__ . '/upgrade_v2.14.lock', date('Y-m-d H:i:s'));
            
            $success = true;
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
        
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统升级 - 保质期管理系统 v2.14.0</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { 
            background: linear-gradient(135deg, #E3F2FD 0%, #BBDEFB 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .upgrade-card { 
            max-width: 600px; 
            width: 100%; 
            padding: 40px; 
            background: white; 
            border-radius: 20px; 
            box-shadow: 0 10px 40px rgba(0,0,0,0.1); 
        }
        .upgrade-title { 
            font-weight: 700; 
            color: #007AFF; 
            margin-bottom: 10px;
            text-align: center;
        }
        .upgrade-subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
        }
        .step-log {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            max-height: 300px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        .step-item {
            padding: 5px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .step-item:last-child {
            border-bottom: none;
        }
        .feature-list {
            text-align: left;
            margin: 20px 0;
        }
        .feature-list li {
            padding: 8px 0;
        }
    </style>
</head>
<body>
    <div class="upgrade-card">
        <h2 class="upgrade-title">🚀 系统升级</h2>
        <p class="upgrade-subtitle">保质期管理系统 v2.13.x → v2.14.0</p>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <h5>✅ 升级成功！</h5>
                <p>系统已成功升级到 v2.14.0，现在可以使用邮箱配置功能。</p>
            </div>
            
            <div class="step-log">
                <?php foreach ($steps as $step): ?>
                    <div class="step-item"><?php echo $step; ?></div>
                <?php endforeach; ?>
            </div>
            
            <div class="alert alert-info mt-3">
                <strong>新功能：</strong>
                <ul class="feature-list mb-0">
                    <li>✨ 多邮箱账户管理</li>
                    <li>✨ 智能轮换发送算法</li>
                    <li>✨ 授权码加密存储（AES-256）</li>
                    <li>✨ 邮件发送日志记录</li>
                    <li>✨ 测试发送功能</li>
                </ul>
            </div>
            
            <div class="d-grid gap-2 mt-4">
                <a href="admin.php" class="btn btn-primary btn-lg">进入管理后台</a>
                <small class="text-center text-muted mt-2">升级完成后，建议删除此文件</small>
            </div>
            
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="alert alert-info">
                <strong>本次升级包含以下内容：</strong>
                <ul class="feature-list mb-0">
                    <li>✨ 新增邮箱账户配置表（email_accounts）</li>
                    <li>✨ 新增邮件发送日志表（email_logs）</li>
                    <li>✨ 添加EMAIL_ENCRYPTION_KEY加密密钥</li>
                    <li>✨ 集成邮箱管理API（email_api.php）</li>
                    <li>✨ 支持多账户智能轮换发送</li>
                </ul>
            </div>
            
            <div class="alert alert-warning">
                <strong>⚠️ 注意事项：</strong>
                <ul class="feature-list mb-0">
                    <li>升级前请备份数据库</li>
                    <li>确保config.php有写入权限</li>
                    <li>升级完成后建议删除此文件</li>
                </ul>
            </div>
            
            <form method="POST">
                <div class="d-grid gap-2 mt-4">
                    <button type="submit" class="btn btn-primary btn-lg">开始升级</button>
                    <a href="admin.php" class="btn btn-outline-secondary">取消</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
