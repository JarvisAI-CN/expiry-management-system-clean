<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 盘点系统
 * 功能：产品数据录入、盘点管理、效期监控
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

// 处理盘点操作
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create') {
            // 创建新的盘点单
            $session_code = $_POST['session_code'] ?? '';
            $description = $_POST['description'] ?? '';
            $currentUser = $authService->getCurrentUser();
            
            if (empty($session_code)) {
                // 自动生成盘点编号
                $session_code = 'STK-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO stocktake_sessions (session_code, user_id, status, ai_analysis, created_at, updated_at) 
                VALUES (?, ?, 'draft', NULL, NOW(), NOW())
            ");
            
            $stmt->execute([$session_code, $currentUser['id']]);
            $sessionId = $pdo->lastInsertId();
            
            $success = "盘点单创建成功！";
            header('Location: stocktake.php?session_id=' . $sessionId);
            exit;
            
        } elseif ($action === 'update') {
            // 更新盘点单
            $sessionId = intval($_POST['session_id'] ?? 0);
            $session_code = $_POST['session_code'] ?? '';
            
            if ($sessionId <= 0 || empty($session_code)) {
                throw new Exception("参数不完整");
            }
            
            $stmt = $pdo->prepare("
                UPDATE stocktake_sessions 
                SET session_code = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$session_code, $sessionId]);
            $success = "盘点单更新成功！";
            
        } elseif ($action === 'add_item') {
            // 添加盘点项目
            $sessionId = intval($_POST['session_id'] ?? 0);
            $productId = intval($_POST['product_id'] ?? 0);
            $quantity = intval($_POST['quantity'] ?? 1);
            $expiryDate = $_POST['expiry_date'] ?? '';
            
            if ($sessionId <= 0 || $productId <= 0 || empty($expiryDate)) {
                throw new Exception("参数不完整");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO stocktake_items (session_id, product_id, quantity, expiry_date, created_at, updated_at) 
                VALUES (?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt->execute([$sessionId, $productId, $quantity, $expiryDate]);
            $success = "产品添加成功！";
            
        } elseif ($action === 'update_item') {
            // 更新盘点项目
            $itemId = intval($_POST['item_id'] ?? 0);
            $quantity = intval($_POST['quantity'] ?? 1);
            $expiryDate = $_POST['expiry_date'] ?? '';
            
            if ($itemId <= 0 || empty($expiryDate)) {
                throw new Exception("参数不完整");
            }
            
            $stmt = $pdo->prepare("
                UPDATE stocktake_items 
                SET quantity = ?, expiry_date = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$quantity, $expiryDate, $itemId]);
            $success = "项目更新成功！";
            
        } elseif ($action === 'delete_item') {
            // 删除盘点项目
            $itemId = intval($_POST['item_id'] ?? 0);
            
            if ($itemId <= 0) {
                throw new Exception("无效的项目ID");
            }
            
            $stmt = $pdo->prepare("DELETE FROM stocktake_items WHERE id = ?");
            $stmt->execute([$itemId]);
            $success = "项目删除成功！";
            
        } elseif ($action === 'complete') {
            // 完成盘点
            $sessionId = intval($_POST['session_id'] ?? 0);
            
            if ($sessionId <= 0) {
                throw new Exception("无效的盘点单ID");
            }
            
            $stmt = $pdo->prepare("UPDATE stocktake_sessions SET status = 'completed', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$sessionId]);
            
            // 生成AI分析报告（如果配置了AI）
            $systemConfigs = [];
            $stmt = $pdo->prepare("SELECT * FROM system_configs");
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $systemConfigs[$row['config_key']] = $row['config_value'];
            }
            
            if (!empty($systemConfigs['ai_api_key'])) {
                require_once 'core/AIService.php';
                
                $aiService = new AIService($systemConfigs);
                
                $stmt = $pdo->prepare("
                    SELECT ssi.*, p.name as product_name, c.name as category_name
                    FROM stocktake_items ssi
                    LEFT JOIN products p ON ssi.product_id = p.id
                    LEFT JOIN categories c ON p.category_id = c.id
                    WHERE session_id = ?
                ");
                
                $stmt->execute([$sessionId]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $analysis = $aiService->analyzeStocktake($items);
                
                $stmt = $pdo->prepare("
                    INSERT INTO stocktake_analysis (session_id, analysis_content, created_at, updated_at) 
                    VALUES (?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE 
                        analysis_content = ?, updated_at = NOW()
                ");
                
                $stmt->execute([$sessionId, $analysis, $analysis]);
            }
            
            $success = "盘点完成！";
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 页面逻辑
$currentSessionId = intval($_GET['session_id'] ?? 0);
$currentPage = $_GET['page'] ?? 'list';

// 设置页面标题
$pageTitle = '盘点系统 - 星巴克门店智能效期管理系统';

?>
<?php include 'includes/header.php'; ?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-title">盘点系统</h1>
        <a href="dashboard.php" class="btn btn-secondary">
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

    <?php if ($currentSessionId > 0): ?>
        <!-- 单个盘点单详情 -->
        <?php
        $stmt = $pdo->prepare("
            SELECT * FROM stocktake_sessions 
            WHERE id = ?
        ");
        $stmt->execute([$currentSessionId]);
        $currentSession = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$currentSession):
        ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> 
            盘点单不存在
        </div>
        <?php else: ?>
        <div class="row">
            <!-- 左侧：盘点单信息 -->
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-info-circle"></i> 盘点单信息
                        </h5>
                        
                        <form method="post" id="sessionForm">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="session_id" value="<?php echo $currentSession['id']; ?>">
                            
                            <div class="mb-3">
                                <label for="session_code" class="form-label">盘点编号</label>
                                <input type="text" class="form-control" id="session_code" name="session_code" 
                                       value="<?php echo escapeHtml($currentSession['session_code']); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">状态</label>
                                <div>
                                    <span class="badge bg-<?php 
                                        echo $currentSession['status'] === 'completed' ? 'success' : 'warning'; 
                                    ?>">
                                        <?php echo $currentSession['status'] === 'completed' ? '已完成' : '进行中'; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">创建时间</label>
                                <div class="text-muted">
                                    <?php echo formatDate($currentSession['created_at']); ?>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">更新时间</label>
                                <div class="text-muted">
                                    <?php echo formatDate($currentSession['updated_at']); ?>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <?php if ($currentSession['status'] !== 'completed'): ?>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> 保存
                                </button>
                                <button type="button" class="btn btn-success" id="completeBtn">
                                    <i class="fas fa-check-circle"></i> 完成盘点
                                </button>
                                <?php endif; ?>
                                <a href="stocktake.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i> 返回列表
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- 右侧：添加产品 -->
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title">
                                <i class="fas fa-plus-circle"></i> 添加产品
                            </h5>
                            <button type="button" class="btn btn-primary" id="scanBtn" data-bs-toggle="modal" data-bs-target="#scanModal">
                                <i class="fas fa-qrcode"></i> 扫码识别
                            </button>
                        </div>
                </div>
                
                <!-- 新增项目 -->
                <?php if ($currentSession['status'] !== 'completed'): ?>
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-plus-circle"></i> 新增项目
                        </h5>
                        
                        <form method="post" id="addItemForm">
                            <input type="hidden" name="action" value="add_item">
                            <input type="hidden" name="session_id" value="<?php echo $currentSession['id']; ?>">
                            
                            <div class="mb-3">
                                <label for="product_id" class="form-label">产品</label>
                                <select class="form-select" id="product_id" name="product_id" required>
                                    <option value="">请选择产品</option>
                                    <?php
                                    $stmt = $pdo->prepare("
                                        SELECT * FROM products 
                                        ORDER BY name ASC
                                    ");
                                    $stmt->execute();
                                    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($products as $product):
                                    ?>
                                    <option value="<?php echo $product['id']; ?>">
                                        <?php echo escapeHtml($product['name']); ?> (<?php echo escapeHtml($product['sku']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="quantity" class="form-label">数量</label>
                                <input type="number" class="form-control" id="quantity" name="quantity" 
                                       value="1" min="1" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="expiry_date" class="form-label">到期日期</label>
                                <input type="date" class="form-control" id="expiry_date" name="expiry_date" 
                                       required min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus"></i> 新增
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- 右侧：盘点项目列表 -->
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-list"></i> 盘点项目 (<?php 
                                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stocktake_items WHERE session_id = ?");
                                    $stmt->execute([$currentSessionId]);
                                    echo $stmt->fetchColumn();
                                ?> 条)
                            </h5>
                            
                            <?php if ($currentSession['status'] === 'completed'): ?>
                            <button type="button" class="btn btn-sm btn-primary" id="viewAnalysisBtn">
                                <i class="fas fa-chart-bar"></i> 查看分析
                            </button>
                            <?php endif; ?>
                        </div>
                        
                        <?php
                        $stmt = $pdo->prepare("
                            SELECT ssi.*, p.name as product_name, c.name as category_name
                            FROM stocktake_items ssi
                            LEFT JOIN products p ON ssi.product_id = p.id
                            LEFT JOIN categories c ON p.category_id = c.id
                            WHERE session_id = ?
                            ORDER BY ssi.created_at DESC
                        ");
                        $stmt->execute([$currentSessionId]);
                        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        
                        <?php if (empty($items)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                暂无盘点项目
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered data-table">
                                    <thead>
                                        <tr>
                                            <th>产品</th>
                                            <th>SKU</th>
                                            <th>数量</th>
                                            <th>到期日期</th>
                                            <th>分类</th>
                                            <?php if ($currentSession['status'] !== 'completed'): ?>
                                            <th>操作</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $item): ?>
                                        <tr>
                                            <td><?php echo escapeHtml($item['product_name']); ?></td>
                                            <td>
                                                <code><?php echo escapeHtml($item['sku'] ?? ''); ?></code>
                                            </td>
                                            <td><?php echo $item['quantity']; ?></td>
                                            <td>
                                                <span class="<?php 
                                                    $daysToExpiry = (strtotime($item['expiry_date']) - strtotime(date('Y-m-d'))) / (60*60*24);
                                                    echo $daysToExpiry < 0 ? 'text-danger' : 
                                                         ($daysToExpiry <= 7 ? 'text-warning' : 'text-success');
                                                ?>">
                                                    <?php echo formatDate($item['expiry_date'], 'Y-m-d'); ?>
                                                </span>
                                                <?php if ($daysToExpiry < 0): ?>
                                                <span class="badge bg-danger">已过期</span>
                                                <?php elseif ($daysToExpiry <= 7): ?>
                                                <span class="badge bg-warning">即将过期</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo escapeHtml($item['category_name']); ?>
                                            </td>
                                            <?php if ($currentSession['status'] !== 'completed'): ?>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-warning edit-item-btn" 
                                                        data-id="<?php echo $item['id']; ?>"
                                                        data-quantity="<?php echo $item['quantity']; ?>"
                                                        data-expiry-date="<?php echo $item['expiry_date']; ?>">
                                                    <i class="fas fa-edit"></i> 编辑
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger delete-item-btn" 
                                                        data-id="<?php echo $item['id']; ?>">
                                                    <i class="fas fa-trash"></i> 删除
                                                </button>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- 分析报告 -->
                <?php if ($currentSession['status'] === 'completed'): ?>
                <div class="card mb-4" id="analysisSection">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-chart-bar"></i> 分析报告
                        </h5>
                        
                        <?php
                        $stmt = $pdo->prepare("
                            SELECT * FROM stocktake_analysis 
                            WHERE session_id = ? 
                            ORDER BY created_at DESC LIMIT 1
                        ");
                        $stmt->execute([$currentSessionId]);
                        $analysis = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$analysis):
                        ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            暂无分析报告
                        </div>
                        <?php else: ?>
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="ai-content">
                                    <?php echo $analysis['analysis_content']; ?>
                                </div>
                                <div class="mt-3 text-muted text-sm">
                                    生成时间: <?php echo formatDate($analysis['created_at']); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
    <?php else: ?>
        <!-- 盘点单列表 -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list"></i> 盘点单列表
                    </h5>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                        <i class="fas fa-plus"></i> 新建盘点单
                    </button>
                </div>
                
                <?php
                $stmt = $pdo->prepare("
                    SELECT s.*, COUNT(i.id) as item_count 
                    FROM stocktake_sessions s 
                    LEFT JOIN stocktake_items i ON s.id = i.session_id 
                    GROUP BY s.id 
                    ORDER BY s.created_at DESC
                ");
                $stmt->execute();
                $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                
                <?php if (empty($sessions)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        暂无盘点单，请点击"新建盘点单"开始
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered data-table">
                            <thead>
                                <tr>
                                    <th>盘点编号</th>
                                    <th>项目数量</th>
                                    <th>状态</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sessions as $session): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo escapeHtml($session['session_code']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo $session['item_count']; ?> 条
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $session['status'] === 'completed' ? 'success' : 'warning'; 
                                        ?>">
                                            <?php echo $session['status'] === 'completed' ? '已完成' : '进行中'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo formatDate($session['created_at'], 'Y-m-d'); ?>
                                    </td>
                                    <td>
                                        <a href="stocktake.php?session_id=<?php echo $session['id']; ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i> 查看
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- 新建盘点单模态框 -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle"></i> 新建盘点单
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="createForm">
                <input type="hidden" name="action" value="create">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="session_code" class="form-label">盘点编号</label>
                        <input type="text" class="form-control" id="session_code" name="session_code" 
                               placeholder="例如：STK-20260224-0001" required>
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

<!-- 编辑项目模态框 -->
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit"></i> 编辑项目
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="editItemForm">
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" name="item_id" id="editItemId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="editQuantity" class="form-label">数量</label>
                        <input type="number" class="form-control" id="editQuantity" name="quantity" 
                               value="1" min="1" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editExpiryDate" class="form-label">到期日期</label>
                        <input type="date" class="form-control" id="editExpiryDate" name="expiry_date" 
                               required min="<?php echo date('Y-m-d'); ?>">
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

<!-- 扫码识别模态框 -->
<div class="modal fade" id="scanModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-qrcode"></i> 扫码识别
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <video id="video" width="100%" height="auto" autoplay playsinline></video>
                    <canvas id="canvas" style="display: none;"></canvas>
                </div>
                <div class="mb-3">
                    <button type="button" class="btn btn-primary w-100" id="startScanBtn">
                        <i class="fas fa-play"></i> 开始扫描
                    </button>
                    <button type="button" class="btn btn-secondary w-100 mt-2" id="stopScanBtn" style="display: none;">
                        <i class="fas fa-stop"></i> 停止扫描
                    </button>
                </div>
                <div class="mb-3">
                    <label class="form-label">扫描结果</label>
                    <input type="text" class="form-control" id="scanResult" readonly>
                </div>
                <div class="mb-3">
                    <button type="button" class="btn btn-success w-100" id="autoFillBtn" disabled>
                        <i class="fas fa-magic"></i> 自动填充
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
            </div>
        </div>
    </div>
</div>

<!-- 删除项目表单 -->
<form id="deleteItemForm" method="post" style="display: none;">
    <input type="hidden" name="action" value="delete_item">
    <input type="hidden" name="item_id" id="deleteItemId">
</form>

<!-- 完成盘点表单 -->
<form id="completeForm" method="post" style="display: none;">
    <input type="hidden" name="action" value="complete">
    <input type="hidden" name="session_id" id="completeSessionId">
</form>

<script>
    // 编辑项目按钮
    $('.edit-item-btn').click(function() {
        const id = $(this).data('id');
        const quantity = $(this).data('quantity');
        const expiryDate = $(this).data('expiryDate');
        
        $('#editItemId').val(id);
        $('#editQuantity').val(quantity);
        $('#editExpiryDate').val(expiryDate);
        $('#editItemModal').modal('show');
    });
    
    // 删除项目按钮
    $('.delete-item-btn').click(function() {
        const id = $(this).data('id');
        
        confirmAction('确定要删除这个项目吗？', function() {
            $('#deleteItemId').val(id);
            $('#deleteItemForm').submit();
        });
    });
    
    // 完成盘点按钮
    $('#completeBtn').click(function() {
        confirmAction('确定要完成盘点吗？', function() {
            $('#completeSessionId').val('<?php echo $currentSessionId; ?>');
            $('#completeForm').submit();
        });
    });
    
    // 查看分析按钮
    $('#viewAnalysisBtn').click(function() {
        $('#analysisSection').toggle();
    });
    
    // 扫码功能
    let scanner = null;
    
    // 初始化扫码器
    function initScanner() {
        if (!scanner) {
            scanner = new QuaggaJS();
        }
    }
    
    // 开始扫描
    function startScanner() {
        initScanner();
        
        scanner.init({
            inputStream: {
                type: "LiveStream",
                target: document.querySelector('#video'),
                constraints: {
                    facingMode: "environment" // 使用后置摄像头
                }
            },
            decoder: {
                readers: ["ean_reader", "ean_8_reader", "code_128_reader"]
            }
        }, function(err) {
            if (err) {
                console.error(err);
                showError('无法启动摄像头');
                return;
            }
            
            console.log('扫码器已启动');
            scanner.start();
        });
        
        // 扫描成功事件
        scanner.onDetected(function(data) {
            const code = data.codeResult.code;
            $('#scanResult').val(code);
            $('#autoFillBtn').prop('disabled', false);
            stopScanner();
        });
    }
    
    // 停止扫描
    function stopScanner() {
        if (scanner) {
            scanner.stop();
        }
    }
    
    // 开始扫描按钮点击事件
    $('#startScanBtn').click(function() {
        startScanner();
        $(this).hide();
        $('#stopScanBtn').show();
    });
    
    // 停止扫描按钮点击事件
    $('#stopScanBtn').click(function() {
        stopScanner();
        $(this).hide();
        $('#startScanBtn').show();
    });
    
    // 自动填充按钮点击事件
    $('#autoFillBtn').click(function() {
        const code = $('#scanResult').val();
        if (!code) {
            showError('请先扫描条形码');
            return;
        }
        
        // 模拟产品搜索和自动填充
        $.ajax({
            url: 'api/search_product.php',
            type: 'GET',
            data: {
                barcode: code
            },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.product) {
                    const product = response.product;
                    $('#product_id').val(product.id);
                    showSuccess('产品自动填充成功！');
                } else {
                    showError('未找到对应的产品');
                }
            },
            error: function(xhr, status, error) {
                console.error(error);
                showError('产品搜索失败');
            }
        });
    });
    
    // 模态框关闭事件
    $('#scanModal').on('hidden.bs.modal', function() {
        stopScanner();
        $('#startScanBtn').show();
        $('#stopScanBtn').hide();
        $('#scanResult').val('');
        $('#autoFillBtn').prop('disabled', true);
    });
    
    // 表单提交
    $('#sessionForm').on('submit', function() {
        return confirm('确定要更新盘点单信息吗？');
    });
    
    $('#addItemForm').on('submit', function() {
        const productId = $('#product_id').val();
        const expiryDate = $('#expiry_date').val();
        
        if (!productId) {
            showError('请选择产品');
            return false;
        }
        
        if (!expiryDate) {
            showError('请选择到期日期');
            return false;
        }
        
        return true;
    });
    
    $('#editItemForm').on('submit', function() {
        const quantity = $('#editQuantity').val();
        const expiryDate = $('#editExpiryDate').val();
        
        if (!quantity || quantity <= 0) {
            showError('请输入有效的数量');
            return false;
        }
        
        if (!expiryDate) {
            showError('请选择到期日期');
            return false;
        }
        
        return true;
    });
</script>

<?php include 'includes/footer.php'; ?>
