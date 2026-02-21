<?php
/**
 * ========================================
 * 保质期管理系统 - 安装引导页
 * 文件名: install.php
 * 创建日期: 2026-02-15
 * ========================================
 */

session_start();

$configFile = 'config.php';
$lockFile = 'install.lock';

// 如果已安装，禁止再次访问
if (file_exists($lockFile)) {
    die("系统已安装。如需重新安装，请手动删除 install.lock 文件。");
}

// 如果配置文件存在但缺少email_accounts表，提示升级
if (file_exists($configFile)) {
    require_once $configFile;
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn && !$conn->connect_error) {
        $checkTable = $conn->query("SHOW TABLES LIKE 'email_accounts'");
        if ($checkTable->num_rows === 0) {
            // 需要升级
            if (file_exists(__DIR__ . '/upgrade_to_v2.14.php')) {
                echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>需要升级</title></head><body style='font-family:sans-serif;text-align:center;padding:50px'>";
                echo "<h1>🚀 系统需要升级</h1>";
                echo "<p>检测到新版本可用，系统需要升级数据库。</p>";
                echo "<p><a href='upgrade_to_v2.14.php' style='background:#007AFF;color:white;padding:12px 24px;text-decoration:none;border-radius:8px;font-size:16px'>立即升级</a></p>";
                echo "<p style='color:#666;font-size:14px'>升级过程大约需要10-30秒，请耐心等待</p>";
                echo "</body></html>";
                exit;
            }
        }
        $conn->close();
    }
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = $_POST['db_host'] ?? 'localhost';
    $db_user = $_POST['db_user'] ?? '';
    $db_pass = $_POST['db_pass'] ?? '';
    $db_name = $_POST['db_name'] ?? 'expiry_system';
    
    $admin_user = $_POST['admin_user'] ?? 'admin';
    $admin_pass = $_POST['admin_pass'] ?? '';

    // 1. 尝试连接数据库
    $conn = @new mysqli($db_host, $db_user, $db_pass);
    
    if ($conn->connect_error) {
        $error = "数据库连接失败: " . $conn->connect_error;
    } else {
        // 2. 尝试选择数据库，如果不存在则尝试创建
        if (!$conn->select_db($db_name)) {
            // 尝试创建数据库
            $create_sql = "CREATE DATABASE IF NOT EXISTS `$db_name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            if (!$conn->query($create_sql)) {
                $error = "无法创建或访问数据库 '$db_name'。请确保该用户有权限，或者您已在面板中手动创建了该名称的数据库。";
            } else {
                $conn->select_db($db_name);
            }
        }

        if (!$error) {
            // 3. 创建表结构
            $sql = "
            CREATE TABLE IF NOT EXISTS `categories` (
              `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(50) NOT NULL,
              `type` VARCHAR(20) NOT NULL,
              `rule` TEXT,
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            INSERT IGNORE INTO `categories` (`name`, `type`, `rule`) VALUES 
            ('小食品', 'snack', '{\"need_buffer\":true, \"scrap_on_removal\":true}'),
            ('物料', 'material', '{\"need_buffer\":false, \"scrap_on_removal\":false}'),
            ('咖啡豆', 'coffee', '{\"need_buffer\":true, \"scrap_on_removal\":false, \"allow_gift\":true}');

            CREATE TABLE IF NOT EXISTS `products` (
              `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
              `category_id` INT(11) UNSIGNED DEFAULT 0,
              `sku` VARCHAR(100) NOT NULL,
              `name` VARCHAR(200) NOT NULL,
              `removal_buffer` INT(5) UNSIGNED DEFAULT 0,
              `inventory_cycle` VARCHAR(20) DEFAULT 'none',
              `last_inventory_at` DATETIME DEFAULT NULL,
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_sku` (`sku`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `batches` (
              `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
              `product_id` INT(11) UNSIGNED NOT NULL,
              `expiry_date` DATE NOT NULL,
              `quantity` INT(11) UNSIGNED NOT NULL DEFAULT 0,
              `session_id` VARCHAR(50) DEFAULT NULL,
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              CONSTRAINT `fk_batches_products` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `inventory_sessions` (
              `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
              `session_key` VARCHAR(50) NOT NULL,
              `user_id` INT(11) UNSIGNED,
              `item_count` INT(11) DEFAULT 0,
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_session_key` (`session_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `users` (
              `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
              `username` VARCHAR(50) NOT NULL,
              `password` VARCHAR(255) NOT NULL,
              `role` VARCHAR(20) DEFAULT 'admin',
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `settings` (
              `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
              `s_key` VARCHAR(100) NOT NULL,
              `s_value` TEXT,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_key` (`s_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `logs` (
              `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` INT(11) UNSIGNED,
              `action` VARCHAR(100),
              `details` TEXT,
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            -- ========================================
            -- 邮箱账户配置表 (v2.14.0+)
            -- ========================================
            CREATE TABLE IF NOT EXISTS `email_accounts` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮箱账户配置表';

            CREATE TABLE IF NOT EXISTS `email_logs` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮件发送日志表';

            -- 初始化默认设置
            INSERT IGNORE INTO `settings` (`s_key`, `s_value`) VALUES 
            ('ai_api_url', 'https://api.openai.com/v1'),
            ('ai_api_key', ''),
            ('ai_model', 'gpt-4o'),
            ('alert_email', ''),
            ('alert_days', '3,7,15'),
            ('email_smtp_host', 'smtp.qq.com'),
            ('email_smtp_port', '465'),
            ('email_smtp_encryption', 'ssl'),
            ('email_cooldown_seconds', '300');
            ";
            
            // 执行多条 SQL
            if ($conn->multi_query($sql)) {
                do {
                    if ($result = $conn->store_result()) { $result->free(); }
                } while ($conn->next_result());
                
                // 4. 创建管理员账号
                $hashed_pass = password_hash($admin_pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?) ON DUPLICATE KEY UPDATE password = VALUES(password)");
                $stmt->bind_param("ss", $admin_user, $hashed_pass);
                $stmt->execute();
                
                // 5. 写入配置文件
                // 生成随机加密密钥
                $encryptionKey = bin2hex(random_bytes(16)); // 32字符十六进制
                
                $configContent = "<?php\n"
                               . "define('DB_HOST', '$db_host');\n"
                               . "define('DB_USER', '$db_user');\n"
                               . "define('DB_PASS', '$db_pass');\n"
                               . "define('DB_NAME', '$db_name');\n"
                               . "define('DB_CHARSET', 'utf8mb4');\n"
                               . "define('EMAIL_ENCRYPTION_KEY', '$encryptionKey');\n"
                               . "// EMAIL_ENCRYPTION_KEY 用于加密邮箱授权码，请妥善保管此密钥\n";
                
                if (file_put_contents($configFile, $configContent)) {
                    // 6. 创建锁文件
                    file_put_contents($lockFile, date('Y-m-d H:i:s'));
                    $success = true;
                } else {
                    $error = "无法写入 config.php，请检查目录权限。";
                }
            } else {
                $error = "表结构创建失败: " . $conn->error;
            }
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
    <title>系统安装 - 保质期管理系统</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .install-card { max-width: 500px; width: 100%; padding: 30px; background: white; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .step-title { font-weight: 700; color: #667eea; margin-bottom: 25px; text-align: center; }
    </style>
</head>
<body>
    <div class="install-card">
        <h3 class="step-title">⚡ 系统安装引导</h3>
        
        <?php if ($success): ?>
            <div class="alert alert-success text-center">
                <h4>安装成功！</h4>
                <p>系统已完成配置，请删除 install.php 以保安全。</p>
                <a href="index.php" class="btn btn-primary mt-3">进入系统</a>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST">
                <h6 class="mb-3 fw-bold">1. 数据库配置</h6>
                <div class="mb-3">
                    <input type="text" name="db_host" class="form-control" placeholder="数据库地址 (默认 localhost)" value="localhost" required>
                </div>
                <div class="mb-3">
                    <input type="text" name="db_user" class="form-control" placeholder="数据库用户名" required>
                </div>
                <div class="mb-3">
                    <input type="password" name="db_pass" class="form-control" placeholder="数据库密码">
                </div>
                <div class="mb-3">
                    <input type="text" name="db_name" class="form-control" placeholder="数据库名 (默认 expiry_system)" value="expiry_system" required>
                </div>

                <h6 class="mb-3 mt-4 fw-bold">2. 管理员设置</h6>
                <div class="mb-3">
                    <input type="text" name="admin_user" class="form-control" placeholder="管理员账号" value="admin" required>
                </div>
                <div class="mb-3">
                    <input type="password" name="admin_pass" class="form-control" placeholder="管理员密码" required>
                </div>

                <div class="d-grid gap-2 mt-4">
                    <button type="submit" class="btn btn-primary btn-lg">立即安装</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
