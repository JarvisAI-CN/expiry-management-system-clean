<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 邮件配置管理页面
 * 功能：QQ邮箱账号管理与轮询配置
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

session_start();

// 引入必要的类文件
require_once '../core/Database.php';
require_once '../core/AuthService.php';
require_once '../core/EmailService.php';

// 加载数据库配置
$config = include '../config/database.php';

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
    header('Location: ../login.php');
    exit;
}

// 创建邮件服务
$emailService = new EmailService($pdo);

// 处理表单提交
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add') {
            // 添加新账号
            $qqNumber = $_POST['qq_number'] ?? '';
            $authCode = $_POST['auth_code'] ?? '';
            
            if (empty($qqNumber) || empty($authCode)) {
                throw new Exception("QQ号和授权码不能为空");
            }
            
            // 测试账号
            $result = $emailService->testAccount($qqNumber, $authCode);
            
            if ($result !== true) {
                throw new Exception("账号验证失败：" . $result);
            }
            
            // 添加账号
            $result = $emailService->addAccount($qqNumber, $authCode);
            
            if (!$result) {
                throw new Exception("添加账号失败");
            }
            
            $success = "邮箱账号添加成功！";
            
        } elseif ($action === 'update') {
            // 更新账号
            $id = intval($_POST['id'] ?? 0);
            $qqNumber = $_POST['qq_number'] ?? '';
            $authCode = $_POST['auth_code'] ?? '';
            
            if ($id <= 0 || empty($qqNumber) || empty($authCode)) {
                throw new Exception("参数不完整");
            }
            
            // 测试账号
            $result = $emailService->testAccount($qqNumber, $authCode);
            
            if ($result !== true) {
                throw new Exception("账号验证失败：" . $result);
            }
            
            // 更新账号
            $result = $emailService->updateAccount($id, $qqNumber, $authCode);
            
            if (!$result) {
                throw new Exception("更新账号失败");
            }
            
            $success = "邮箱账号更新成功！";
            
        } elseif ($action === 'delete') {
            // 删除账号
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception("无效的账号ID");
            }
            
            // 检查是否是最后一个账号
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_accounts WHERE is_active = 1");
            $stmt->execute();
            $count = $stmt->fetchColumn();
            
            if ($count <= 1) {
                throw new Exception("至少需要保留一个有效的邮箱账号");
            }
            
            // 删除账号
            $result = $emailService->deleteAccount($id);
            
            if (!$result) {
                throw new Exception("删除账号失败");
            }
            
            $success = "邮箱账号删除成功！";
            
        } elseif ($action === 'test') {
            // 测试账号
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception("无效的账号ID");
            }
            
            $stmt = $pdo->prepare("SELECT * FROM email_accounts WHERE id = ?");
            $stmt->execute([$id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$account) {
                throw new Exception("账号不存在");
            }
            
            $result = $emailService->testAccount($account['qq_number'], $account['auth_code']);
            
            if ($result === true) {
                $success = "账号测试成功！";
            } else {
                throw new Exception("账号测试失败：" . $result);
            }
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 获取所有邮箱账号
$stmt = $pdo->prepare("
    SELECT * FROM email_accounts 
    WHERE is_active = 1 
    ORDER BY last_used_at ASC
");
$stmt->execute();
$emailAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 设置页面标题
$pageTitle = '邮件配置管理 - 星巴克门店智能效期管理系统';

?>
<?php include '../includes/header.php'; ?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-title">邮件配置管理</h1>
        <a href="../dashboard.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> 返回首页
        </a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="card-title mb-0">
                    <i class="fas fa-envelope"></i> 邮箱账号列表
                </h5>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="fas fa-plus"></i> 添加账号
                </button>
            </div>
            
            <?php if (empty($emailAccounts)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    暂无邮箱账号，请点击"添加账号"按钮添加第一个账号。
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>QQ号</th>
                                <th>邮箱地址</th>
                                <th>最后使用时间</th>
                                <th>状态</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($emailAccounts as $index => $account): ?>
                            <tr>
                                <td><?php echo $account['id']; ?></td>
                                <td><?php echo escapeHtml($account['qq_number']); ?></td>
                                <td><?php echo escapeHtml($account['qq_number']); ?>@qq.com</td>
                                <td>
                                    <?php 
                                    if ($account['last_used_at'] && $account['last_used_at'] !== '0000-00-00 00:00:00') {
                                        echo formatDate($account['last_used_at'], 'Y-m-d H:i');
                                    } else {
                                        echo '<span class="text-muted">未使用</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($index === 0): ?>
                                        <span class="badge bg-primary">下次使用</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">活跃</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info test-btn" data-id="<?php echo $account['id']; ?>">
                                        <i class="fas fa-check-circle"></i> 测试
                                    </button>
                                    <button type="button" class="btn btn-sm btn-warning edit-btn" data-id="<?php echo $account['id']; ?>" 
                                            data-qq="<?php echo escapeHtml($account['qq_number']); ?>">
                                        <i class="fas fa-edit"></i> 编辑
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger delete-btn" data-id="<?php echo $account['id']; ?>">
                                        <i class="fas fa-trash"></i> 删除
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5 class="card-title mb-4">
                <i class="fas fa-info-circle"></i> 配置说明
            </h5>
            
            <div class="alert alert-primary">
                <h6><i class="fas fa-cog"></i> SMTP 配置（系统预设）</h6>
                <table class="table table-sm mb-0">
                    <tr>
                        <td style="width: 20%"><strong>服务器</strong></td>
                        <td>smtp.qq.com</td>
                    </tr>
                    <tr>
                        <td><strong>端口</strong></td>
                        <td>465</td>
                    </tr>
                    <tr>
                        <td><strong>加密</strong></td>
                        <td>SSL</td>
                    </tr>
                </table>
            </div>
            
            <div class="alert alert-info">
                <h6><i class="fas fa-question-circle"></i> 如何获取 QQ 邮箱授权码？</h6>
                <ol>
                    <li>登录 QQ 邮箱网页版（mail.qq.com）</li>
                    <li>点击"设置" → "账户"</li>
                    <li>找到"POP3/IMAP/SMTP/Exchange/CardDAV/CalDAV服务"</li>
                    <li>开启"POP3/SMTP服务"或"IMAP/SMTP服务"</li>
                    <li>按照提示发送短信验证</li>
                    <li>获取授权码（16位字符）</li>
                </ol>
            </div>
            
            <div class="alert alert-warning">
                <h6><i class="fas fa-exclamation-triangle"></i> 使用建议</h6>
                <ul>
                    <li><strong>多账号轮询</strong>：添加多个 QQ 邮箱账号，系统会自动轮询使用，防止单账号被封禁</li>
                    <li><strong>授权码安全</strong>：授权码是邮箱的密码，请妥善保管，不要泄露</li>
                    <li><strong>定期测试</strong>：定期点击"测试"按钮验证账号是否正常</li>
                    <li><strong>频率控制</strong>：系统已内置发送间隔（1秒），避免被识别为垃圾邮件</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- 添加账号模态框 -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle"></i> 添加邮箱账号
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="qq_number" class="form-label">QQ 号</label>
                        <input type="text" class="form-control" id="qq_number" name="qq_number" 
                               placeholder="请输入QQ号" required>
                        <div class="form-text">
                            用于发送邮件的 QQ 号
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="auth_code" class="form-label">授权码</label>
                        <input type="text" class="form-control" id="auth_code" name="auth_code" 
                               placeholder="请输入QQ邮箱授权码" required>
                        <div class="form-text">
                            QQ 邮箱的 SMTP 授权码（16位字符）
                        </div>
                    </div>
                    <div class="alert alert-info mb-0">
                        <small>
                            <i class="fas fa-info-circle"></i> 
                            添加后系统会自动测试账号连通性，确保配置正确。
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">添加并测试</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 编辑账号模态框 -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit"></i> 编辑邮箱账号
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_qq_number" class="form-label">QQ 号</label>
                        <input type="text" class="form-control" id="edit_qq_number" name="qq_number" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_auth_code" class="form-label">授权码</label>
                        <input type="text" class="form-control" id="edit_auth_code" name="auth_code" required>
                        <div class="form-text">
                            如不修改授权码，请保持原值不变
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存并测试</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 删除确认表单 -->
<form id="deleteForm" method="post" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="delete_id">
</form>

<!-- 测试表单 -->
<form id="testForm" method="post" style="display: none;">
    <input type="hidden" name="action" value="test">
    <input type="hidden" name="id" id="test_id">
</form>

<script>
    // 编辑按钮
    $('.edit-btn').click(function() {
        const id = $(this).data('id');
        const qq = $(this).data('qq');
        
        $('#edit_id').val(id);
        $('#edit_qq_number').val(qq);
        $('#edit_auth_code').val('');
        
        $('#editModal').modal('show');
    });
    
    // 删除按钮
    $('.delete-btn').click(function() {
        const id = $(this).data('id');
        
        confirmAction('确定要删除这个邮箱账号吗？', function() {
            $('#delete_id').val(id);
            $('#deleteForm').submit();
        });
    });
    
    // 测试按钮
    $('.test-btn').click(function() {
        const id = $(this).data('id');
        
        $('#test_id').val(id);
        $('#testForm').submit();
    });
</script>

<?php include '../includes/footer.php'; ?>
