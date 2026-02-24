<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 系统安装程序
 * 功能：引导用户完成系统安装配置
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

// 检查是否已安装
if (file_exists('install.lock')) {
    header('Location: login.php');
    exit;
}

// 安装状态
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$errors = [];
$success = '';

// 步骤 1: 环境检测
if ($step == 1) {
    // 检查 PHP 版本
    if (version_compare(PHP_VERSION, '8.1.0', '<')) {
        $errors[] = "PHP 版本过低，需要 PHP 8.1.0 或更高版本";
    }
    
    // 检查扩展
    $requiredExtensions = ['gd', 'pdo', 'pdo_mysql', 'curl', 'zip', 'xml'];
    foreach ($requiredExtensions as $ext) {
        if (!extension_loaded($ext)) {
            $errors[] = "缺少 PHP 扩展：{$ext}";
        }
    }
    
    // 检查文件权限
    $directoriesToCheck = ['config', 'public/uploads', 'logs'];
    foreach ($directoriesToCheck as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        if (!is_writable($dir)) {
            $errors[] = "目录 {$dir} 不可写";
        }
    }
    
    // 如果没有错误且用户提交了表单，进入下一步
    if (empty($errors) && isset($_POST['step1_submit'])) {
        header('Location: install.php?step=2');
        exit;
    }
}

// 步骤 2: 数据库配置
if ($step == 2) {
    if (isset($_POST['step2_submit'])) {
        $dbHost = $_POST['db_host'];
        $dbName = $_POST['db_name'];
        $dbUser = $_POST['db_user'];
        $dbPass = $_POST['db_pass'];
        
        try {
            // 测试数据库连接
            $dsn = "mysql:host={$dbHost};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // 检查数据库是否存在
            $stmt = $pdo->query("SHOW DATABASES LIKE '{$dbName}'");
            if ($stmt->rowCount() == 0) {
                // 尝试创建数据库
                $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
            
            // 保存配置文件
            $configContent = "<?php
/**
 * 星巴克门店智能效期管理系统 - 数据库配置
 * 自动生成，请勿手动修改
 */
return [
    'host' => '{$dbHost}',
    'name' => '{$dbName}',
    'user' => '{$dbUser}',
    'pass' => '{$dbPass}',
    'charset' => 'utf8mb4',
    'prefix' => '',
];";
            
            if (file_put_contents('config/database.php', $configContent) === false) {
                throw new Exception('无法写入数据库配置文件');
            }
            
            header('Location: install.php?step=3');
            exit;
            
        } catch (PDOException $e) {
            $errors[] = "数据库连接失败：" . $e->getMessage();
        } catch (Exception $e) {
            $errors[] = "操作失败：" . $e->getMessage();
        }
    }
}

// 步骤 3: 管理员设置
if ($step == 3) {
    if (isset($_POST['step3_submit'])) {
        $username = $_POST['admin_user'];
        $password = $_POST['admin_pass'];
        $confirmPassword = $_POST['admin_pass_confirm'];
        
        // 验证输入
        if (empty($username)) {
            $errors[] = "用户名不能为空";
        } elseif (strlen($username) < 3) {
            $errors[] = "用户名至少需要3个字符";
        }
        
        if (empty($password)) {
            $errors[] = "密码不能为空";
        } elseif (strlen($password) < 6) {
            $errors[] = "密码至少需要6个字符";
        }
        
        if ($password !== $confirmPassword) {
            $errors[] = "两次输入的密码不一致";
        }
        
        if (empty($errors)) {
            try {
                // 加载数据库配置
                $config = include 'config/database.php';
                
                // 连接数据库
                $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset={$config['charset']}";
                $pdo = new PDO($dsn, $config['user'], $config['pass']);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // 导入数据库结构
                $sql = file_get_contents('schema.sql');
                $pdo->exec($sql);
                
                // 更新管理员密码
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ? WHERE id = ?");
                $stmt->execute([$username, $hashedPassword, 1]);
                
                // 创建安装锁文件
                file_put_contents('install.lock', date('Y-m-d H:i:s'));
                
                $success = "系统安装成功！正在跳转至登录页面...";
                header('Refresh: 3; URL=login.php');
                
            } catch (Exception $e) {
                $errors[] = "安装失败：" . $e->getMessage();
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>星巴克门店智能效期管理系统 - 安装</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        .installation-progress {
            margin-bottom: 30px;
        }
        
        .installation-progress .progress-step {
            text-align: center;
        }
        
        .installation-progress .progress-step.active {
            color: #0d6efd;
            font-weight: bold;
        }
        
        .installation-progress .progress-step.completed {
            color: #198754;
        }
        
        .installation-progress .progress-line {
            height: 2px;
            background-color: #e9ecef;
            margin: 10px 0;
        }
        
        .installation-progress .progress-line.completed {
            background-color: #198754;
        }
        
        .form-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .btn-primary {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
        
        .btn-primary:hover {
            background-color: #0b5ed7;
            border-color: #0a58ca;
        }
        
        .error-message {
            color: #dc3545;
            margin-bottom: 10px;
        }
        
        .success-message {
            color: #198754;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h3 class="card-title mb-0">星巴克门店智能效期管理系统 V3.0.0</h3>
                        <p class="mb-0">系统安装向导</p>
                    </div>
                    
                    <div class="card-body">
                        <!-- 安装进度 -->
                        <div class="installation-progress">
                            <div class="row">
                                <div class="col-md-3 progress-step <?php echo $step == 1 ? 'active' : ($step > 1 ? 'completed' : ''); ?>">
                                    环境检测
                                </div>
                                <div class="col-md-1 progress-line <?php echo $step > 1 ? 'completed' : ''; ?>"></div>
                                <div class="col-md-3 progress-step <?php echo $step == 2 ? 'active' : ($step > 2 ? 'completed' : ''); ?>">
                                    数据库配置
                                </div>
                                <div class="col-md-1 progress-line <?php echo $step > 2 ? 'completed' : ''; ?>"></div>
                                <div class="col-md-3 progress-step <?php echo $step == 3 ? 'active' : ($step > 3 ? 'completed' : ''); ?>">
                                    管理员设置
                                </div>
                            </div>
                        </div>
                        
                        <!-- 错误信息 -->
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger" role="alert">
                                <strong>错误：</strong>
                                <ul>
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo $error; ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <!-- 成功信息 -->
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success" role="alert">
                                <strong>成功：</strong><?php echo $success; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- 步骤 1: 环境检测 -->
                        <?php if ($step == 1): ?>
                            <div class="form-section">
                                <h4>环境检测</h4>
                                <p>正在检查服务器环境是否满足系统要求...</p>
                                
                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <h6>PHP 版本</h6>
                                        <p class="text-<?php echo version_compare(PHP_VERSION, '8.1.0', '>=') ? 'success' : 'danger'; ?>">
                                            当前版本：<?php echo PHP_VERSION; ?>
                                        </p>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <h6>系统架构</h6>
                                        <p><?php echo php_uname('s') . ' ' . php_uname('r'); ?></p>
                                    </div>
                                </div>
                                
                                <div class="row mt-3">
                                    <div class="col-md-12">
                                        <h6>PHP 扩展</h6>
                                        <div class="row">
                                            <?php foreach ($requiredExtensions as $ext): ?>
                                                <div class="col-md-3 mb-2">
                                                    <span class="badge bg-<?php echo extension_loaded($ext) ? 'success' : 'danger'; ?>">
                                                        <?php echo $ext; ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row mt-3">
                                    <div class="col-md-12">
                                        <h6>目录权限</h6>
                                        <div class="row">
                                            <?php foreach ($directoriesToCheck as $dir): ?>
                                                <div class="col-md-4 mb-2">
                                                    <span class="badge bg-<?php echo is_writable($dir) ? 'success' : 'danger'; ?>">
                                                        <?php echo $dir; ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (empty($errors)): ?>
                                    <div class="mt-4 text-center">
                                        <form method="post">
                                            <button type="submit" name="step1_submit" class="btn btn-primary btn-lg">
                                                继续安装
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="mt-4 text-center">
                                        <p class="text-danger">请修复以上错误后继续</p>
                                        <form method="post">
                                            <button type="submit" name="step1_submit" class="btn btn-primary">
                                                重新检测
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- 步骤 2: 数据库配置 -->
                        <?php if ($step == 2): ?>
                            <div class="form-section">
                                <h4>数据库配置</h4>
                                <p>请输入数据库连接信息...</p>
                                
                                <form method="post">
                                    <div class="mb-3">
                                        <label for="db_host" class="form-label">数据库主机</label>
                                        <input type="text" class="form-control" id="db_host" name="db_host" 
                                               value="localhost" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="db_name" class="form-label">数据库名称</label>
                                        <input type="text" class="form-control" id="db_name" name="db_name" 
                                               value="expiry_guard" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="db_user" class="form-label">数据库用户名</label>
                                        <input type="text" class="form-control" id="db_user" name="db_user" 
                                               value="root" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="db_pass" class="form-label">数据库密码</label>
                                        <input type="password" class="form-control" id="db_pass" name="db_pass">
                                    </div>
                                    
                                    <div class="text-center mt-4">
                                        <button type="submit" name="step2_submit" class="btn btn-primary btn-lg">
                                            验证并保存配置
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                        
                        <!-- 步骤 3: 管理员设置 -->
                        <?php if ($step == 3): ?>
                            <div class="form-section">
                                <h4>管理员设置</h4>
                                <p>请设置系统管理员账号...</p>
                                
                                <form method="post">
                                    <div class="mb-3">
                                        <label for="admin_user" class="form-label">用户名</label>
                                        <input type="text" class="form-control" id="admin_user" name="admin_user" 
                                               placeholder="请输入用户名" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="admin_pass" class="form-label">密码</label>
                                        <input type="password" class="form-control" id="admin_pass" name="admin_pass" 
                                               placeholder="请输入密码（至少6位）" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="admin_pass_confirm" class="form-label">确认密码</label>
                                        <input type="password" class="form-control" id="admin_pass_confirm" name="admin_pass_confirm" 
                                               placeholder="请再次输入密码" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="admin_email" class="form-label">邮箱地址</label>
                                        <input type="email" class="form-control" id="admin_email" name="admin_email" 
                                               placeholder="admin@example.com" required>
                                    </div>
                                    
                                    <div class="text-center mt-4">
                                        <button type="submit" name="step3_submit" class="btn btn-primary btn-lg">
                                            完成安装
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
