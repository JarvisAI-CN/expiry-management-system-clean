<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 物料管理页面
 * 功能：产品的CRUD、搜索、分类关联
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
            // 添加新产品
            $sku = $_POST['sku'] ?? '';
            $name = $_POST['name'] ?? '';
            $categoryId = intval($_POST['category_id'] ?? 0);
            $companyCategory = $_POST['company_category'] ?? '';
            
            if (empty($sku) || empty($name) || $categoryId <= 0) {
                throw new Exception("SKU、产品名称和分类不能为空");
            }
            
            // 检查SKU是否已存在
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ?");
            $stmt->execute([$sku]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                throw new Exception("SKU已存在");
            }
            
            // 插入产品
            $stmt = $pdo->prepare("
                INSERT INTO products (sku, name, category_id, company_category_raw, created_at, updated_at) 
                VALUES (?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt->execute([$sku, $name, $categoryId, $companyCategory]);
            
            $success = "产品添加成功！";
            
        } elseif ($action === 'update') {
            // 更新产品
            $id = intval($_POST['id'] ?? 0);
            $sku = $_POST['sku'] ?? '';
            $name = $_POST['name'] ?? '';
            $categoryId = intval($_POST['category_id'] ?? 0);
            $companyCategory = $_POST['company_category'] ?? '';
            
            if ($id <= 0 || empty($sku) || empty($name) || $categoryId <= 0) {
                throw new Exception("参数不完整");
            }
            
            // 检查SKU是否已被其他产品使用
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ? AND id != ?");
            $stmt->execute([$sku, $id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                throw new Exception("SKU已被其他产品使用");
            }
            
            // 更新产品
            $stmt = $pdo->prepare("
                UPDATE products 
                SET sku = ?, name = ?, category_id = ?, company_category_raw = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$sku, $name, $categoryId, $companyCategory, $id]);
            
            $success = "产品更新成功！";
            
        } elseif ($action === 'delete') {
            // 删除产品
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception("无效的产品ID");
            }
            
            // 检查是否有盘点记录使用此产品
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM stocktake_items WHERE product_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                throw new Exception("该产品已被使用在 {$count} 条盘点记录中，无法删除");
            }
            
            // 删除产品
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$id]);
            
            $success = "产品删除成功！";
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 获取所有产品
$searchParams = [];
$searchConditions = [];
$params = [];

if (isset($_GET['search'])) {
    $search = $_GET['search'] ?? '';
    $categoryId = intval($_GET['category_id'] ?? 0);
    
    if (!empty($search)) {
        $searchConditions[] = "(sku LIKE ? OR name LIKE ? OR company_category_raw LIKE ?)";
        $searchParams[] = "%{$search}%";
        $searchParams[] = "%{$search}%";
        $searchParams[] = "%{$search}%";
    }
    
    if ($categoryId > 0) {
        $searchConditions[] = "category_id = ?";
        $searchParams[] = $categoryId;
    }
    
    $params = $searchParams;
}

// 构建查询
$query = "
    SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id
";

if (!empty($searchConditions)) {
    $query .= " WHERE " . implode(" AND ", $searchConditions);
}

$query .= " ORDER BY p.updated_at DESC";

// 分页
$page = intval($_GET['page'] ?? 1);
$perPage = 10;
$offset = ($page - 1) * $perPage;

// 执行查询
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$totalProducts = $stmt->rowCount();

// 分页查询
$query .= " LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($query);
array_push($params, $perPage, $offset);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取所有分类（用于下拉选择）
$stmt = $pdo->prepare("SELECT * FROM categories ORDER BY name ASC");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 计算总页数
$totalPages = ceil($totalProducts / $perPage);

// 设置页面标题
$pageTitle = '物料管理 - 星巴克门店智能效期管理系统';

?>
<?php include '../includes/header.php'; ?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-title">物料管理</h1>
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

    <!-- 搜索和筛选 -->
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title mb-3">
                <i class="fas fa-search"></i> 搜索筛选
            </h5>
            <form method="get">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-search"></i>
                            </span>
                            <input type="text" class="form-control" name="search" 
                                   placeholder="搜索SKU、产品名称或公司分类"
                                   value="<?php echo escapeHtml($_GET['search'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <select class="form-select" name="category_id">
                            <option value="0">所有分类</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" 
                                    <?php echo ($_GET['category_id'] ?? 0) == $category['id'] ? 'selected' : ''; ?>>
                                <?php echo escapeHtml($category['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> 搜索
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- 产品统计 -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="fas fa-chart-bar"></i> 产品统计
                </h5>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="fas fa-plus"></i> 添加产品
                </button>
            </div>
            
            <div class="mt-3">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="card-title text-primary"><?php echo $totalProducts; ?></h3>
                                <p class="card-text">总产品数</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="card-title text-success"><?php echo count($categories); ?></h3>
                                <p class="card-text">分类数</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="card-title text-warning">
                                    <?php echo count(array_unique(array_column($products, 'category_id'))); ?>
                                </h3>
                                <p class="card-text">活跃分类数</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="card-title text-info">
                                    <?php echo count(array_unique(array_column($products, 'company_category_raw'))); ?>
                                </h3>
                                <p class="card-text">公司分类数</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 产品列表 -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>SKU</th>
                            <th>产品名称</th>
                            <th>分类</th>
                            <th>公司分类</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?php echo $product['id']; ?></td>
                            <td>
                                <strong><?php echo escapeHtml($product['sku']); ?></strong>
                            </td>
                            <td><?php echo escapeHtml($product['name']); ?></td>
                            <td>
                                <?php if (!empty($product['category_name'])): ?>
                                    <span class="badge bg-info"><?php echo escapeHtml($product['category_name']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">未分类</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($product['company_category_raw'])): ?>
                                    <?php echo escapeHtml($product['company_category_raw']); ?>
                                <?php else: ?>
                                    <span class="text-muted">无</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo formatDate($product['created_at'], 'Y-m-d'); ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-warning edit-btn" 
                                        data-id="<?php echo $product['id']; ?>"
                                        data-sku="<?php echo escapeHtml($product['sku']); ?>"
                                        data-name="<?php echo escapeHtml($product['name']); ?>"
                                        data-category-id="<?php echo $product['category_id']; ?>"
                                        data-company-category="<?php echo escapeHtml($product['company_category_raw']); ?>">
                                    <i class="fas fa-edit"></i> 编辑
                                </button>
                                <button type="button" class="btn btn-sm btn-danger delete-btn" 
                                        data-id="<?php echo $product['id']; ?>">
                                    <i class="fas fa-trash"></i> 删除
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- 分页 -->
            <?php if ($totalPages > 1): ?>
            <div class="d-flex justify-content-center mt-4">
                <?php echo generatePagination($page, $totalPages); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5 class="card-title mb-4">
                <i class="fas fa-info-circle"></i> 使用说明
            </h5>
            
            <div class="alert alert-info">
                <h6><i class="fas fa-shopping-cart"></i> SKU管理</h6>
                <p>
                    SKU是产品的唯一标识符，用于物料管理和库存追踪。建议使用规范的SKU格式，如：
                    <code class="text-danger">SBK001/202312</code>
                </p>
            </div>
            
            <div class="alert alert-warning">
                <h6><i class="fas fa-tags"></i> 分类管理</h6>
                <p>
                    产品必须属于一个分类，分类信息决定了产品的效期规则和盘点频率。可以在"分类管理"页面配置。
                </p>
            </div>
            
            <div class="alert alert-success">
                <h6><i class="fas fa-download"></i> 数据导入</h6>
                <p>
                    如需批量添加产品，可以使用"数据导入"功能，支持从Excel文件导入产品信息。
                </p>
            </div>
        </div>
    </div>
</div>

<!-- 添加产品模态框 -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle"></i> 添加产品
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="sku" class="form-label">SKU</label>
                        <input type="text" class="form-control" id="sku" name="sku" 
                               placeholder="例如：SBK001" required>
                        <div class="form-text">产品的唯一标识符</div>
                    </div>
                    <div class="mb-3">
                        <label for="name" class="form-label">产品名称</label>
                        <input type="text" class="form-control" id="name" name="name" 
                               placeholder="产品名称" required>
                        <div class="form-text">产品的完整名称</div>
                    </div>
                    <div class="mb-3">
                        <label for="category_id" class="form-label">分类</label>
                        <select class="form-select" id="category_id" name="category_id" required>
                            <option value="">请选择分类</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>">
                                <?php echo escapeHtml($category['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">产品所属的分类</div>
                    </div>
                    <div class="mb-3">
                        <label for="company_category" class="form-label">公司分类</label>
                        <input type="text" class="form-control" id="company_category" name="company_category" 
                               placeholder="公司原始分类名称">
                        <div class="form-text">可选：公司系统中的分类名称</div>
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

<!-- 编辑产品模态框 -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit"></i> 编辑产品
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_sku" class="form-label">SKU</label>
                        <input type="text" class="form-control" id="edit_sku" name="sku" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">产品名称</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_category_id" class="form-label">分类</label>
                        <select class="form-select" id="edit_category_id" name="category_id" required>
                            <option value="">请选择分类</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>">
                                <?php echo escapeHtml($category['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_company_category" class="form-label">公司分类</label>
                        <input type="text" class="form-control" id="edit_company_category" name="company_category">
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
        const sku = $(this).data('sku');
        const name = $(this).data('name');
        const categoryId = $(this).data('categoryId');
        const companyCategory = $(this).data('companyCategory');
        
        $('#edit_id').val(id);
        $('#edit_sku').val(sku);
        $('#edit_name').val(name);
        $('#edit_category_id').val(categoryId);
        $('#edit_company_category').val(companyCategory);
        
        $('#editModal').modal('show');
    });
    
    // 删除按钮
    $('.delete-btn').click(function() {
        const id = $(this).data('id');
        
        confirmAction('确定要删除这个产品吗？', function() {
            $('#delete_id').val(id);
            $('#deleteForm').submit();
        });
    });
</script>

<?php include '../includes/footer.php'; ?>
