<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 数据导入模块
 * 功能：Excel文件上传、解析、验证和导入
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

session_start();

// 引入必要的类文件
require_once '../core/Database.php';
require_once '../core/AuthService.php';
require_once '../core/ImportService.php';

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

// 创建导入服务
$importService = new ImportService($pdo);

// 处理文件上传和数据导入
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'upload') {
            // 文件上传
            if (!isset($_FILES['excel_file'])) {
                throw new Exception("请选择要上传的Excel文件");
            }
            
            $file = $_FILES['excel_file'];
            
            // 验证文件类型
            $allowedTypes = ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
            if (!in_array($file['type'], $allowedTypes)) {
                throw new Exception("只支持 .xls 和 .xlsx 格式的Excel文件");
            }
            
            // 验证文件大小 (最大 10MB)
            $maxSize = 10 * 1024 * 1024;
            if ($file['size'] > $maxSize) {
                throw new Exception("文件大小不能超过 10MB");
            }
            
            // 验证文件是否上传成功
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("文件上传失败，错误代码：" . $file['error']);
            }
            
            // 临时文件路径
            $tmpPath = $file['tmp_name'];
            
            // 解析 Excel 文件
            $result = $importService->parseExcel($tmpPath);
            
            if (!is_array($result)) {
                throw new Exception($result);
            }
            
            // 验证数据格式
            $validationResult = $importService->validateData($result);
            
            if (!$validationResult['success']) {
                $errorMessages = implode("\n", $validationResult['errors']);
                throw new Exception("数据格式验证失败：\n" . $errorMessages);
            }
            
            // 验证通过，添加到待办列表
            $currentUser = $authService->getCurrentUser();
            $result = $importService->addToTodoList($result, $currentUser['id']);
            
            if (!$result) {
                throw new Exception("添加到待办列表失败");
            }
            
            $success = "文件解析成功！已添加 " . count($result) . " 条待处理记录";
            
        } elseif ($action === 'bind') {
            // 绑定分类
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            $categoryId = intval($_POST['category_id'] ?? 0);
            $checkFrequency = $_POST['check_frequency'] ?? 'daily';
            
            if (empty($ids) || $categoryId <= 0) {
                throw new Exception("请选择要绑定的记录和分类");
            }
            
            $result = $importService->bindCategories($ids, $categoryId, $checkFrequency);
            
            if (!$result) {
                throw new Exception("绑定分类失败");
            }
            
            $success = "成功绑定 " . count($ids) . " 条记录";
            
        } elseif ($action === 'delete') {
            // 删除待办记录
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception("无效的待办记录ID");
            }
            
            $result = $importService->deleteTodo($id);
            
            if (!$result) {
                throw new Exception("删除待办记录失败");
            }
            
            $success = "待办记录删除成功";
            
        } elseif ($action === 'clear') {
            // 清空待办列表
            $currentUser = $authService->getCurrentUser();
            $result = $importService->clearTodoList($currentUser['id']);
            
            if (!$result) {
                throw new Exception("清空待办列表失败");
            }
            
            $success = "待办列表清空成功";
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 获取待办列表
$searchParams = [];
$searchConditions = [];
$params = [];

if (isset($_GET['search'])) {
    $companyCategory = $_GET['company_category'] ?? '';
    
    if (!empty($companyCategory)) {
        $searchConditions[] = "company_category_raw LIKE ?";
        $searchParams[] = "%{$companyCategory}%";
    }
    
    $params = $searchParams;
}

// 构建查询
$query = "
    SELECT * FROM import_todo 
    WHERE user_id = ?
";

if (!empty($searchConditions)) {
    $query .= " AND " . implode(" AND ", $searchConditions);
}

$query .= " ORDER BY id DESC";

// 分页
$page = intval($_GET['page'] ?? 1);
$perPage = 10;
$offset = ($page - 1) * $perPage;

// 执行查询
$currentUser = $authService->getCurrentUser();
$stmt = $pdo->prepare($query);
$stmt->execute([$currentUser['id']]);
$totalTodo = $stmt->rowCount();

// 分页查询
$query .= " LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($query);
array_push($params, $currentUser['id'], $perPage, $offset);
$stmt->execute($params);
$todoList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取所有分类（用于下拉选择）
$stmt = $pdo->prepare("SELECT * FROM categories ORDER BY name ASC");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 计算总页数
$totalPages = ceil($totalTodo / $perPage);

// 设置页面标题
$pageTitle = '数据导入 - 星巴克门店智能效期管理系统';

?>
<?php include '../includes/header.php'; ?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-title">数据导入</h1>
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
            <i class="fas fa-exclamation-triangle"></i> <?php echo str_replace("\n", "<br>", $error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 文件上传区域 -->
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title mb-3">
                <i class="fas fa-cloud-upload-alt"></i> Excel文件上传
            </h5>
            
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="input-group">
                            <input type="file" class="form-control" id="excel_file" name="excel_file" 
                                   accept=".xls,.xlsx" required>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-upload"></i> 上传
                            </button>
                        </div>
                        <div class="form-text mt-1">
                            支持 .xls 和 .xlsx 格式，文件大小不超过 10MB
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- 待办列表 -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="card-title mb-0">
                    <i class="fas fa-tasks"></i> 待办列表 (<?php echo $totalTodo; ?> 条)
                </h5>
                
                <div class="d-flex gap-2">
                    <form method="get" class="d-flex gap-2">
                        <input type="text" class="form-control form-control-sm" 
                               name="company_category" 
                               placeholder="搜索公司分类"
                               value="<?php echo escapeHtml($_GET['company_category'] ?? ''); ?>">
                        <button type="submit" name="search" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-search"></i> 搜索
                        </button>
                    </form>
                    
                    <?php if (!empty($todoList)): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="clearBtn">
                        <i class="fas fa-trash"></i> 清空
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (empty($todoList)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    待办列表为空，请上传Excel文件开始导入
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered data-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">
                                    <input type="checkbox" id="selectAll">
                                </th>
                                <th>SKU</th>
                                <th>产品名称</th>
                                <th>公司分类</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todoList as $todo): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="todo-check" value="<?php echo $todo['id']; ?>">
                                </td>
                                <td><?php echo escapeHtml($todo['sku']); ?></td>
                                <td><?php echo escapeHtml($todo['product_name']); ?></td>
                                <td><?php echo escapeHtml($todo['company_category_raw']); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-danger delete-btn" 
                                            data-id="<?php echo $todo['id']; ?>">
                                        <i class="fas fa-trash"></i> 删除
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- 批量操作 -->
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="selectAllDisplay">
                        <label class="form-check-label" for="selectAllDisplay">全选</label>
                    </div>
                    
                    <button type="button" class="btn btn-primary" id="bindBtn">
                        <i class="fas fa-link"></i> 批量绑定
                    </button>
                </div>
                
                <!-- 分页 -->
                <?php if ($totalPages > 1): ?>
                <div class="d-flex justify-content-center mt-4">
                    <?php echo generatePagination($page, $totalPages); ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5 class="card-title mb-4">
                <i class="fas fa-info-circle"></i> 使用说明
            </h5>
            
            <div class="alert alert-info">
                <h6><i class="fas fa-file-excel"></i> 支持的Excel格式</h6>
                <p>
                    Excel文件应包含以下列：
                    <strong>公司分类、商品名称、SKU、数量、效期</strong>
                </p>
            </div>
            
            <div class="alert alert-warning">
                <h6><i class="fas fa-exclamation-triangle"></i> 重要提示</h6>
                <ul>
                    <li>上传文件后，数据会先解析并验证格式</li>
                    <li>验证通过的数据会添加到待办列表</li>
                    <li>需要手动审核和绑定分类后才能正式入库</li>
                    <li>支持批量选择和绑定操作</li>
                </ul>
            </div>
            
            <div class="alert alert-success">
                <h6><i class="fas fa-check-circle"></i> 操作流程</h6>
                <ol>
                    <li>点击"上传"按钮，选择Excel文件</li>
                    <li>系统解析并验证数据格式</li>
                    <li>在待办列表中查看和搜索记录</li>
                    <li>选择需要绑定的记录，点击"批量绑定"</li>
                    <li>选择分类和盘点频率，完成绑定</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- 绑定分类模态框 -->
<div class="modal fade" id="bindModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-link"></i> 批量绑定分类
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="bindForm">
                <input type="hidden" name="action" value="bind">
                <input type="hidden" name="ids" id="bindIds">
                <div class="modal-body">
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
                    </div>
                    <div class="mb-3">
                        <label for="check_frequency" class="form-label">盘点频率</label>
                        <select class="form-select" id="check_frequency" name="check_frequency" required>
                            <option value="daily">每日</option>
                            <option value="weekly">每周</option>
                            <option value="monthly">每月</option>
                            <option value="quarterly">每季度</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">绑定</button>
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

<!-- 清空确认表单 -->
<form id="clearForm" method="post" style="display: none;">
    <input type="hidden" name="action" value="clear">
</form>

<script>
    // 全选/取消全选
    $('#selectAll').click(function() {
        $('.todo-check').prop('checked', $(this).prop('checked'));
    });
    
    $('#selectAllDisplay').click(function() {
        $('.todo-check').prop('checked', $(this).prop('checked'));
    });
    
    // 单个复选框点击
    $('.todo-check').click(function() {
        const allChecked = $('.todo-check:checked').length === $('.todo-check').length;
        $('#selectAll').prop('checked', allChecked);
        $('#selectAllDisplay').prop('checked', allChecked);
    });
    
    // 删除按钮
    $('.delete-btn').click(function() {
        const id = $(this).data('id');
        
        confirmAction('确定要删除这条待办记录吗？', function() {
            $('#delete_id').val(id);
            $('#deleteForm').submit();
        });
    });
    
    // 绑定按钮
    $('#bindBtn').click(function() {
        const checkedIds = $('.todo-check:checked').map(function() {
            return $(this).val();
        }).get();
        
        if (checkedIds.length === 0) {
            showError('请选择要绑定的记录');
            return;
        }
        
        $('#bindIds').val(JSON.stringify(checkedIds));
        $('#bindModal').modal('show');
    });
    
    // 清空按钮
    $('#clearBtn').click(function() {
        confirmAction('确定要清空待办列表吗？', function() {
            $('#clearForm').submit();
        });
    });
    
    // 模态框提交前验证
    $('#bindForm').on('submit', function() {
        const categoryId = $('#category_id').val();
        
        if (!categoryId) {
            showError('请选择分类');
            return false;
        }
        
        return true;
    });
</script>

<?php include '../includes/footer.php'; ?>
