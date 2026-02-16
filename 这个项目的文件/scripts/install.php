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

            -- 初始化默认设置
            INSERT IGNORE INTO `settings` (`s_key`, `s_value`) VALUES 
            ('ai_api_url', 'https://api.openai.com/v1'),
            ('ai_api_key', ''),
            ('ai_model', 'gpt-4o'),
            ('alert_email', ''),
            ('alert_days', '3,7,15');
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
                $configContent = "<?php\n"
                               . "define('DB_HOST', '$db_host');\n"
                               . "define('DB_USER', '$db_user');\n"
                               . "define('DB_PASS', '$db_pass');\n"
                               . "define('DB_NAME', '$db_name');\n"
                               . "define('DB_CHARSET', 'utf8mb4');\n";
                
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
