<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 后台管理页面
 * 功能：系统配置、用户管理、分类管理、物料管理、数据导入、AI配置、邮件配置
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

session_start();

// 引入必要的类文件和函数库
require_once 'includes/functions.php';
require_once 'core/Database.php';
require_once 'core/AuthService.php';

// 加载数据库配置
$config = include 'config/database.php';

// 创建数据库连接
$database = new Database($config);
$pdo = $database->getConnection();

// 创建鉴权服务
$authConfig = [
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => isset($_SERVER['HTTPS'])
];

$authService = new AuthService($pdo, $authConfig);

// 检查用户登录状态
if (!$authService->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// 检查用户权限
if (!$authService->isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

// 页面管理
$currentTab = $_GET['tab'] ?? 'dashboard';

// 处理用户管理操作
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add_user') {
            // 添加用户
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $email = $_POST['email'] ?? '';
            $role = $_POST['role'] ?? 'user';
            
            // 验证输入
            if (empty($username) || empty($password) || empty($email)) {
                throw new Exception("用户名、密码和邮箱不能为空");
            }
            
            if (strlen($password) < 6) {
                throw new Exception("密码长度至少需要6个字符");
            }
            
            // 检查用户名是否已存在
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->rowCount() > 0) {
                throw new Exception("用户名已存在");
            }
            
            // 检查邮箱是否已存在
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->rowCount() > 0) {
                throw new Exception("邮箱已存在");
            }
            
            // 加密密码
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // 添加用户
            $stmt = $pdo->prepare("
                INSERT INTO users (username, password, email, role, created_at, updated_at) 
                VALUES (?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt->execute([$username, $hashedPassword, $email, $role]);
            
            $success = "用户添加成功！";
            
        } elseif ($action === 'delete_user') {
            // 删除用户
            $userId = intval($_POST['user_id'] ?? 0);
            
            if ($userId <= 0) {
                throw new Exception("无效的用户ID");
            }
            
            // 不能删除自己
            $currentUser = $authService->getCurrentUser();
            if ($userId === $currentUser['id']) {
                throw new Exception("不能删除自己");
            }
            
            // 不能删除管理员账户
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user['role'] === 'admin') {
                throw new Exception("不能删除管理员账户");
            }
            
            // 删除用户
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            
            $success = "用户删除成功！";
            
        } elseif ($action === 'update_user') {
            // 更新用户
            $userId = intval($_POST['user_id'] ?? 0);
            $username = $_POST['username'] ?? '';
            $email = $_POST['email'] ?? '';
            $role = $_POST['role'] ?? 'user';
            
            if ($userId <= 0 || empty($username) || empty($email)) {
                throw new Exception("参数不完整");
            }
            
            // 检查用户名是否已存在（排除当前用户）
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$username, $userId]);
            if ($stmt->rowCount() > 0) {
                throw new Exception("用户名已存在");
            }
            
            // 检查邮箱是否已存在（排除当前用户）
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $userId]);
            if ($stmt->rowCount() > 0) {
                throw new Exception("邮箱已存在");
            }
            
            // 更新用户信息
            $stmt = $pdo->prepare("
                UPDATE users 
                SET username = ?, email = ?, role = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            
            $stmt->execute([$username, $email, $role, $userId]);
            
            $success = "用户信息更新成功！";
            
        } elseif ($action === 'change_password') {
            // 更改密码
            $userId = intval($_POST['user_id'] ?? 0);
            $newPassword = $_POST['new_password'] ?? '';
            
            if ($userId <= 0 || empty($newPassword) || strlen($newPassword) < 6) {
                throw new Exception("无效的密码参数");
            }
            
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("
                UPDATE users 
                SET password = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            
            $stmt->execute([$hashedPassword, $userId]);
            
            $success = "密码更新成功！";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 获取所有用户列表
$users = [];
$stmt = $pdo->prepare("
    SELECT id, username, email, role, created_at, updated_at 
    FROM users 
    ORDER BY created_at DESC
");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 设置页面标题
$pageTitle = '后台管理 - 星巴克门店智能效期管理系统';

?>
<?php include 'includes/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <!-- 侧边栏 -->
        <div class="col-md-3 sidebar">
            <div class="sidebar-content">
                <h4><i class="fas fa-tachometer-alt"></i> 后台管理</h4>
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a href="admin.php" class="nav-link <?php echo $currentTab === 'dashboard' ? 'active' : ''; ?>">
                            <i class="fas fa-home"></i> 后台首页
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin.php?tab=users" class="nav-link <?php echo $currentTab === 'users' ? 'active' : ''; ?>">
                            <i class="fas fa-users"></i> 用户管理
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin.php?tab=categories" class="nav-link <?php echo $currentTab === 'categories' ? 'active' : ''; ?>">
                            <i class="fas fa-th-large"></i> 分类管理
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin.php?tab=products" class="nav-link <?php echo $currentTab === 'products' ? 'active' : ''; ?>">
                            <i class="fas fa-box"></i> 物料管理
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin.php?tab=import" class="nav-link <?php echo $currentTab === 'import' ? 'active' : ''; ?>">
                            <i class="fas fa-upload"></i> 数据导入
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin.php?tab=ai" class="nav-link <?php echo $currentTab === 'ai' ? 'active' : ''; ?>">
                            <i class="fas fa-robot"></i> AI配置
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin.php?tab=email" class="nav-link <?php echo $currentTab === 'email' ? 'active' : ''; ?>">
                            <i class="fas fa-envelope"></i> 邮件配置
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="fas fa-arrow-left"></i> 返回首页
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- 主内容区域 -->
        <div class="col-md-9">
            <div class="page-content">
                <!-- 成功/错误提示 -->
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- 后台首页 -->
                <?php if ($currentTab === 'dashboard'): ?>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="fas fa-tachometer-alt"></i> 后台管理首页
                                    </h5>
                                    <p class="card-text">欢迎来到星巴克门店智能效期管理系统后台管理界面</p>
                                    <div class="row mt-4">
                                        <div class="col-md-3">
                                            <div class="card text-center">
                                                <div class="card-body">
                                                    <div class="text-primary mb-2">
                                                        <i class="fas fa-users fa-3x"></i>
                                                    </div>
                                                    <h3><?php echo count($users); ?></h3>
                                                    <p class="text-muted">用户总数</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="card text-center">
                                                <div class="card-body">
                                                    <div class="text-success mb-2">
                                                        <i class="fas fa-box fa-3x"></i>
                                                    </div>
                                                    <h3><?php 
                                                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products");
                                                        $stmt->execute();
                                                        echo $stmt->fetchColumn();
                                                    ?></h3>
                                                    <p class="text-muted">物料总数</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="card text-center">
                                                <div class="card-body">
                                                    <div class="text-info mb-2">
                                                        <i class="fas fa-th-large fa-3x"></i>
                                                    </div>
                                                    <h3><?php 
                                                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories");
                                                        $stmt->execute();
                                                        echo $stmt->fetchColumn();
                                                    ?></h3>
                                                    <p class="text-muted">分类总数</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="card text-center">
                                                <div class="card-body">
                                                    <div class="text-warning mb-2">
                                                        <i class="fas fa-calendar-check fa-3x"></i>
                                                    </div>
                                                    <h3><?php 
                                                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM stocktake_sessions");
                                                        $stmt->execute();
                                                        echo $stmt->fetchColumn();
                                                    ?></h3>
                                                    <p class="text-muted">盘点记录</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 用户管理 -->
                <?php if ($currentTab === 'users'): ?>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h5 class="card-title">
                                            <i class="fas fa-users"></i> 用户管理
                                        </h5>
                                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                            <i class="fas fa-plus"></i> 添加用户
                                        </button>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>用户名</th>
                                                    <th>邮箱</th>
                                                    <th>角色</th>
                                                    <th>创建时间</th>
                                                    <th>操作</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($users as $user): ?>
                                                <tr>
                                                    <td><?php echo escapeHtml($user['username']); ?></td>
                                                    <td><?php echo escapeHtml($user['email']); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $user['role'] === 'admin' ? 'danger' : 'secondary'; ?>">
                                                            <?php echo $user['role'] === 'admin' ? '管理员' : '普通用户'; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo formatDate($user['created_at']); ?></td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-secondary edit-user-btn"
                                                                data-id="<?php echo $user['id']; ?>"
                                                                data-username="<?php echo $user['username']; ?>"
                                                                data-email="<?php echo $user['email']; ?>"
                                                                data-role="<?php echo $user['role']; ?>">
                                                            <i class="fas fa-edit"></i> 编辑
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-danger delete-user-btn"
                                                                data-id="<?php echo $user['id']; ?>"
                                                                data-username="<?php echo $user['username']; ?>">
                                                            <i class="fas fa-trash"></i> 删除
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-info change-password-btn"
                                                                data-id="<?php echo $user['id']; ?>"
                                                                data-username="<?php echo $user['username']; ?>">
                                                            <i class="fas fa-key"></i> 密码
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 分类管理 -->
                <?php if ($currentTab === 'categories'): ?>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="fas fa-th-large"></i> 分类管理
                                    </h5>
                                    <p class="card-text">分类管理功能将在后续版本中实现</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 物料管理 -->
                <?php if ($currentTab === 'products'): ?>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="fas fa-box"></i> 物料管理
                                    </h5>
                                    <p class="card-text">物料管理功能将在后续版本中实现</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 数据导入 -->
                <?php if ($currentTab === 'import'): ?>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="fas fa-upload"></i> 数据导入
                                    </h5>
                                    <p class="card-text">数据导入功能将在后续版本中实现</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- AI配置 -->
                <?php if ($currentTab === 'ai'): ?>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="fas fa-robot"></i> AI配置
                                    </h5>
                                    <p class="card-text">AI配置功能将在后续版本中实现</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 邮件配置 -->
                <?php if ($currentTab === 'email'): ?>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="fas fa-envelope"></i> 邮件配置
                                    </h5>
                                    <p class="card-text">邮件配置功能将在后续版本中实现</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 添加用户模态框 -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus"></i> 添加用户
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="add_user">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="username" class="form-label">用户名</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">密码</label>
                        <input type="password" class="form-control" id="password" name="password" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">邮箱</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="role" class="form-label">角色</label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="user">普通用户</option>
                            <option value="admin">管理员</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">添加</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 编辑用户模态框 -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit"></i> 编辑用户
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="editUsername" class="form-label">用户名</label>
                        <input type="text" class="form-control" id="editUsername" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="editEmail" class="form-label">邮箱</label>
                        <input type="email" class="form-control" id="editEmail" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="editRole" class="form-label">角色</label>
                        <select class="form-select" id="editRole" name="role" required>
                            <option value="user">普通用户</option>
                            <option value="admin">管理员</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 删除用户模态框 -->
<div class="modal fade" id="deleteUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-trash"></i> 删除用户
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="deleteUserMessage"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">删除</button>
            </div>
        </div>
    </div>
</div>

<!-- 更改密码模态框 -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-key"></i> 更改密码
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="user_id" id="changePasswordUserId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="newPassword" class="form-label">新密码</label>
                        <input type="password" class="form-control" id="newPassword" name="new_password" required minlength="6">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // 编辑用户按钮事件
    $('.edit-user-btn').click(function() {
        const id = $(this).data('id');
        const username = $(this).data('username');
        const email = $(this).data('email');
        const role = $(this).data('role');
        
        $('#editUserId').val(id);
        $('#editUsername').val(username);
        $('#editEmail').val(email);
        $('#editRole').val(role);
        $('#editUserModal').modal('show');
    });

    // 删除用户按钮事件
    $('.delete-user-btn').click(function() {
        const id = $(this).data('id');
        const username = $(this).data('username');
        
        $('#deleteUserMessage').text('确定要删除用户 ' + username + ' 吗？');
        $('#deleteUserModal').data('user-id', id);
        $('#deleteUserModal').modal('show');
    });

    // 确认删除按钮事件
    $('#confirmDeleteBtn').click(function() {
        const userId = $('#deleteUserModal').data('user-id');
        
        $.post('', {
            action: 'delete_user',
            user_id: userId
        }, function() {
            location.reload();
        });
    });

    // 更改密码按钮事件
    $('.change-password-btn').click(function() {
        const id = $(this).data('id');
        const username = $(this).data('username');
        
        $('#changePasswordUserId').val(id);
        $('#changePasswordModal').modal('show');
    });
</script>

<?php include 'includes/footer.php'; ?>
