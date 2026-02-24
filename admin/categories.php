<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 分类管理页面
 * 功能：物料分类与效期规则管理
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

session_start();

// 引入必要的类文件
require_once '../core/Database.php';
require_once '../core/AuthService.php';

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

// 处理表单提交
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add') {
            // 添加新分类
            $name = $_POST['name'] ?? '';
            $earlyDisposeDays = intval($_POST['early_dispose_days'] ?? 0);
            $shelfRemovalDays = intval($_POST['shelf_removal_days'] ?? 0);
            $checkFrequency = $_POST['check_frequency'] ?? 'daily';
            
            if (empty($name)) {
                throw new Exception("分类名称不能为空");
            }
            
            // 检查分类名称是否已存在
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE name = ?");
            $stmt->execute([$name]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                throw new Exception("分类名称已存在");
            }
            
            // 插入分类
            $stmt = $pdo->prepare("
                INSERT INTO categories (name, early_dispose_days, shelf_removal_days, check_frequency, created_at, updated_at) 
                VALUES (?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt->execute([$name, $earlyDisposeDays, $shelfRemovalDays, $checkFrequency]);
            
            $success = "分类添加成功！";
            
        } elseif ($action === 'update') {
            // 更新分类
            $id = intval($_POST['id'] ?? 0);
            $name = $_POST['name'] ?? '';
            $earlyDisposeDays = intval($_POST['early_dispose_days'] ?? 0);
            $shelfRemovalDays = intval($_POST['shelf_removal_days'] ?? 0);
            $checkFrequency = $_POST['check_frequency'] ?? 'daily';
            
            if ($id <= 0 || empty($name)) {
                throw new Exception("参数不完整");
            }
            
            // 检查分类名称是否已被其他分类使用
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE name = ? AND id != ?");
            $stmt->execute([$name, $id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                throw new Exception("分类名称已被使用");
            }
            
            // 更新分类
            $stmt = $pdo->prepare("
                UPDATE categories 
                SET name = ?, early_dispose_days = ?, shelf_removal_days = ?, check_frequency = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$name, $earlyDisposeDays, $shelfRemovalDays, $checkFrequency, $id]);
            
            $success = "分类更新成功！";
            
        } elseif ($action === 'delete') {
            // 删除分类
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception("无效的分类ID");
            }
            
            // 检查是否有产品使用此分类
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                throw new Exception("该分类下还有 {$count} 个产品，无法删除");
            }
            
            // 删除分类
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->execute([$id]);
            
            $success = "分类删除成功！";
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 获取所有分类
$stmt = $pdo->prepare("
    SELECT c.*, 
           (SELECT COUNT(*) FROM products WHERE category_id = c.id) as product_count
    FROM categories c
    ORDER BY c.name ASC
");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 设置页面标题
$pageTitle = '分类管理 - 星巴克门店智能效期管理系统';

?>
<?php include '../includes/header.php'; ?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-title">分类管理</h1>
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
                    <i class="fas fa-tags"></i> 分类列表
                </h5>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="fas fa-plus"></i> 添加分类
                </button>
            </div>
            
            <?php if (empty($categories)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    暂无分类，请点击"添加分类"按钮创建第一个分类。
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>分类名称</th>
                                <th>提前报废天数</th>
                                <th>提前下架天数</th>
                                <th>盘点频次</th>
                                <th>产品数量</th>
                                <th>创建时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $category): ?>
                            <tr>
                                <td><?php echo $category['id']; ?></td>
                                <td>
                                    <strong><?php echo escapeHtml($category['name']); ?></strong>
                                </td>
                                <td>
                                    <?php if ($category['early_dispose_days'] > 0): ?>
                                        <span class="badge bg-danger">
                                            <?php echo $category['early_dispose_days']; ?> 天
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">不提前</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($category['shelf_removal_days'] > 0): ?>
                                        <span class="badge bg-warning">
                                            <?php echo $category['shelf_removal_days']; ?> 天
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">不提前</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $frequencyMap = [
                                        'daily' => '每日',
                                        'weekly' => '每周',
                                        'monthly' => '每月',
                                        'quarterly' => '每季度'
                                    ];
                                    echo $frequencyMap[$category['check_frequency']] ?? $category['check_frequency'];
                                    ?>
                                </td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php echo $category['product_count']; ?> 个
                                    </span>
                                </td>
                                <td>
                                    <?php echo formatDate($category['created_at'], 'Y-m-d'); ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-warning edit-btn" 
                                            data-id="<?php echo $category['id']; ?>"
                                            data-name="<?php echo escapeHtml($category['name']); ?>"
                                            data-early-dispose="<?php echo $category['early_dispose_days']; ?>"
                                            data-shelf-removal="<?php echo $category['shelf_removal_days']; ?>"
                                            data-frequency="<?php echo $category['check_frequency']; ?>">
                                        <i class="fas fa-edit"></i> 编辑
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger delete-btn" 
                                            data-id="<?php echo $category['id']; ?>"
                                            data-product-count="<?php echo $category['product_count']; ?>">
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
                <i class="fas fa-info-circle"></i> 规则说明
            </h5>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="alert alert-danger mb-3">
                        <h6><i class="fas fa-trash"></i> 提前报废天数</h6>
                        <p class="mb-0">
                            在产品到期日前 <strong>X 天</strong> 必须报废处理。
                            <br>设置为 0 表示不提前报废。
                        </p>
                    </div>
                    
                    <div class="alert alert-warning mb-0">
                        <h6><i class="fas fa-box-open"></i> 提前下架天数</h6>
                        <p class="mb-0">
                            在产品到期日前 <strong>Y 天</strong> 必须从货架下架。
                            <br>设置为 0 表示不提前下架。
                        </p>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="alert alert-info mb-0">
                        <h6><i class="fas fa-clipboard-check"></i> 盘点频次</h6>
                        <ul class="mb-0">
                            <li><strong>每日</strong>：每天盘点一次（适用于易腐食品）</li>
                            <li><strong>每周</strong>：每周盘点一次（适用于短保质期产品）</li>
                            <li><strong>每月</strong>：每月盘点一次（适用于长保质期产品）</li>
                            <li><strong>每季度</strong>：每季度盘点一次（适用于常温物料）</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="alert alert-primary mt-3 mb-0">
                <h6><i class="fas fa-lightbulb"></i> 配置建议</h6>
                <ul>
                    <li><strong>糕点类</strong>：提前报废1天，不提前下架，每日盘点</li>
                    <li><strong>鲜奶类</strong>：提前报废2天，提前下架1天，每日盘点</li>
                    <li><strong>咖啡豆</strong>：不提前报废，不提前下架，每周盘点</li>
                    <li><strong>常温物料</strong>：不提前报废，不提前下架，每月盘点</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- 添加分类模态框 -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle"></i> 添加分类
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">分类名称</label>
                        <input type="text" class="form-control" id="name" name="name" 
                               placeholder="例如：糕点" required>
                        <div class="form-text">
                            分类的显示名称
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="early_dispose_days" class="form-label">提前报废天数</label>
                        <input type="number" class="form-control" id="early_dispose_days" name="early_dispose_days" 
                               value="0" min="0" max="30">
                        <div class="form-text">
                            到期日前多少天必须报废（0表示不提前）
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="shelf_removal_days" class="form-label">提前下架天数</label>
                        <input type="number" class="form-control" id="shelf_removal_days" name="shelf_removal_days" 
                               value="0" min="0" max="30">
                        <div class="form-text">
                            到期日前多少天必须下架（0表示不提前）
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="check_frequency" class="form-label">盘点频次</label>
                        <select class="form-select" id="check_frequency" name="check_frequency" required>
                            <option value="daily">每日</option>
                            <option value="weekly">每周</option>
                            <option value="monthly">每月</option>
                            <option value="quarterly">每季度</option>
                        </select>
                        <div class="form-text">
                            该分类产品的默认盘点频率
                        </div>
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

<!-- 编辑分类模态框 -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit"></i> 编辑分类
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">分类名称</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_early_dispose_days" class="form-label">提前报废天数</label>
                        <input type="number" class="form-control" id="edit_early_dispose_days" name="early_dispose_days" 
                               min="0" max="30">
                    </div>
                    <div class="mb-3">
                        <label for="edit_shelf_removal_days" class="form-label">提前下架天数</label>
                        <input type="number" class="form-control" id="edit_shelf_removal_days" name="shelf_removal_days" 
                               min="0" max="30">
                    </div>
                    <div class="mb-3">
                        <label for="edit_check_frequency" class="form-label">盘点频次</label>
                        <select class="form-select" id="edit_check_frequency" name="check_frequency" required>
                            <option value="daily">每日</option>
                            <option value="weekly">每周</option>
                            <option value="monthly">每月</option>
                            <option value="quarterly">每季度</option>
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

<!-- 删除确认表单 -->
<form id="deleteForm" method="post" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="delete_id">
</form>

<script>
    // 编辑按钮
    $('.edit-btn').click(function() {
        const id = $(this).data('id');
        const name = $(this).data('name');
        const earlyDispose = $(this).data('earlyDispose');
        const shelfRemoval = $(this).data('shelfRemoval');
        const frequency = $(this).data('frequency');
        
        $('#edit_id').val(id);
        $('#edit_name').val(name);
        $('#edit_early_dispose_days').val(earlyDispose);
        $('#edit_shelf_removal_days').val(shelfRemoval);
        $('#edit_check_frequency').val(frequency);
        
        $('#editModal').modal('show');
    });
    
    // 删除按钮
    $('.delete-btn').click(function() {
        const id = $(this).data('id');
        const productCount = $(this).data('productCount');
        
        let message = '确定要删除这个分类吗？';
        
        if (productCount > 0) {
            message += '\\n\\n注意：该分类下还有 ' + productCount + ' 个产品，删除失败！';
        }
        
        confirmAction(message, function() {
            $('#delete_id').val(id);
            $('#deleteForm').submit();
        });
    });
</script>

<?php include '../includes/footer.php'; ?>
