<?php
/**
 * ========================================
 * API密钥管理页面
 * 文件名: api_keys.php
 * ========================================
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_key_manager.php';

session_start();

// 检查登录
if (!checkAuth()) {
    header("Location: index.php");
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'user';

// 处理表单提交
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create':
            $name = trim($_POST['name'] ?? '');
            $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

            if (empty($name)) {
                $message = '请输入密钥名称';
                $messageType = 'danger';
            } else {
                $result = createApiKey($name, $userId, $expiresAt);
                if ($result['success']) {
                    $message = "API密钥创建成功！<br><strong>请保存您的密钥：</strong><code style='font-size:16px;'>" . htmlspecialchars($result['api_key']) . "</code>";
                    $messageType = 'success';
                } else {
                    $message = $result['message'];
                    $messageType = 'danger';
                }
            }
            break;

        case 'delete':
            $keyId = intval($_POST['key_id'] ?? 0);
            $result = deleteApiKey($keyId, $userId);
            $message = $result['message'];
            $messageType = $result['success'] ? 'success' : 'danger';
            break;

        case 'toggle':
            $keyId = intval($_POST['key_id'] ?? 0);
            $result = toggleApiKeyStatus($keyId);
            $message = $result['message'];
            $messageType = $result['success'] ? 'success' : 'danger';
            break;
    }
}

// 获取所有API密钥
$apiKeys = getApiKeys();

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API密钥管理 - 保质期管理系统</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .api-key-display {
            font-family: 'Courier New', monospace;
            font-size: 14px;
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 4px;
            word-break: break-all;
        }
        .status-active {
            color: #198754;
        }
        .status-inactive {
            color: #dc3545;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-shield-lock"></i> 保质期管理系统
            </a>
            <div class="d-flex">
                <a href="dashboard.php" class="btn btn-outline-light me-2">
                    <i class="bi bi-house"></i> 返回管理后台
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="bi bi-key"></i> API密钥管理</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createKeyModal">
                        <i class="bi bi-plus-circle"></i> 创建新密钥
                    </button>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-list"></i> 我的API密钥
                    </div>
                    <div class="card-body">
                        <?php if (empty($apiKeys)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-key" style="font-size: 48px;"></i>
                            <p class="mt-3">还没有API密钥</p>
                            <p class="small">点击上方"创建新密钥"按钮创建您的第一个API密钥</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>名称</th>
                                        <th>API密钥</th>
                                        <th>状态</th>
                                        <th>创建时间</th>
                                        <th>最后使用</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($apiKeys as $key): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($key['name']); ?></strong>
                                        </td>
                                        <td>
                                            <code class="api-key-display"><?php echo htmlspecialchars($key['api_key_masked']); ?></code>
                                        </td>
                                        <td>
                                            <?php if ($key['is_active']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle"></i> 启用</span>
                                            <?php else: ?>
                                            <span class="badge bg-danger"><i class="bi bi-x-circle"></i> 禁用</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($key['created_at'])); ?></td>
                                        <td>
                                            <?php if ($key['last_used_at']): ?>
                                            <?php echo date('Y-m-d H:i', strtotime($key['last_used_at'])); ?>
                                            <?php else: ?>
                                            <span class="text-muted">从未使用</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary" onclick="copyApiKey('<?php echo htmlspecialchars($key['api_key']); ?>')">
                                                    <i class="bi bi-clipboard"></i> 复制
                                                </button>
                                                <button class="btn btn-outline-warning" onclick="toggleKey(<?php echo $key['id']; ?>)">
                                                    <i class="bi bi-power"></i>
                                                </button>
                                                <button class="btn btn-outline-danger" onclick="deleteKey(<?php echo $key['id']; ?>, '<?php echo htmlspecialchars($key['name']); ?>')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header">
                        <i class="bi bi-info-circle"></i> API使用说明
                    </div>
                    <div class="card-body">
                        <h5>接口地址</h5>
                        <p><code>api.php?endpoint=数据类型</code></p>

                        <h5>认证方式</h5>
                        <p>在请求头中添加：<code>Authorization: Bearer YOUR_API_KEY</code></p>

                        <h5>支持的endpoint</h5>
                        <ul>
                            <li><code>products</code> - 获取所有产品数据</li>
                            <li><code>batches</code> - 获取所有批次数据</li>
                            <li><code>expiring</code> - 获取即将过期的产品（默认30天内）</li>
                            <li><code>summary</code> - 获取汇总统计</li>
                            <li><code>categories</code> - 获取分类数据</li>
                            <li><code>all</code> - 获取所有数据（完整导出）</li>
                        </ul>

                        <h5>示例请求</h5>
                        <pre><code>curl -H "Authorization: Bearer YOUR_API_KEY" "http://你的域名/api.php?endpoint=summary"</code></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 创建密钥模态框 -->
    <div class="modal fade" id="createKeyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">创建新API密钥</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label for="name" class="form-label">密钥名称</label>
                            <input type="text" class="form-control" id="name" name="name" required placeholder="例如：贾维斯访问密钥">
                            <div class="form-text">给密钥起个名字，便于识别用途</div>
                        </div>
                        <div class="mb-3">
                            <label for="expires_at" class="form-label">过期时间（可选）</label>
                            <input type="datetime-local" class="form-control" id="expires_at" name="expires_at">
                            <div class="form-text">留空表示永不过期</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-primary">创建</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function copyApiKey(apiKey) {
            navigator.clipboard.writeText(apiKey).then(() => {
                alert('API密钥已复制到剪贴板！');
            });
        }

        function toggleKey(keyId) {
            if (confirm('确定要切换此密钥的状态吗？')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="toggle"><input type="hidden" name="key_id" value="' + keyId + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        function deleteKey(keyId, keyName) {
            if (confirm('确定要删除密钥 "' + keyName + '" 吗？此操作不可恢复！')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="key_id" value="' + keyId + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
