<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 首页看板
 * 功能：展示系统概览、快捷入口、AI智能简报
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

session_start();

// 引入必要的类文件
require_once 'core/Database.php';
require_once 'core/AuthService.php';
require_once 'core/AIService.php';

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

// 获取当前用户信息
$currentUser = $authService->getCurrentUser();

// 获取系统配置
$stmt = $pdo->prepare("SELECT * FROM system_configs");
$stmt->execute();
$systemConfigs = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $systemConfigs[$row['config_key']] = $row['config_value'];
}

// 检查数据库迁移状态
require_once 'core/MigrationManager.php';
$migrationManager = new MigrationManager($pdo, __DIR__ . '/migrations');
$currentVersion = $migrationManager->getCurrentVersion();
$availableMigrations = $migrationManager->getAvailableMigrations();
$pendingMigrations = $migrationManager->getPendingMigrations();

// 统计数据
$stats = [
    'total_products' => 0,
    'total_stocktakes' => 0,
    'total_categories' => 0,
    'expiring_products' => 0
];

// 获取产品总数
$stats['total_products'] = $database->count('products');

// 获取盘点会话总数
$stats['total_stocktakes'] = $database->count('stocktake_sessions');

// 获取分类总数
$stats['total_categories'] = $database->count('categories');

// 获取即将过期的产品
$expiringQuery = "
    SELECT COUNT(*) FROM stocktake_items 
    WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND expiry_date >= CURDATE()
";
$stats['expiring_products'] = (int)$pdo->query($expiringQuery)->fetchColumn();

// 获取最近一次盘点数据
$recentStocktake = $database->fetchOne("
    SELECT * FROM stocktake_sessions 
    WHERE status = 'completed'
    ORDER BY created_at DESC
    LIMIT 1
");

$aiAnalysis = '';
$aiLoading = false;

// 处理AI分析请求
if (isset($_GET['action']) && $_GET['action'] === 'generate_ai_analysis') {
    $aiLoading = true;
    
    if ($recentStocktake) {
        $stocktakeItems = $database->fetchAll("
            SELECT si.*, p.name as product_name, c.name as category_name
            FROM stocktake_items si
            LEFT JOIN products p ON si.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE si.session_id = ?
        ", [$recentStocktake['id']]);
        
        if (!empty($stocktakeItems)) {
            // 初始化 AI 服务
            $aiConfig = [
                'endpoint' => $systemConfigs['ai_endpoint'] ?? 'https://api.openai.com/v1',
                'api_key' => $systemConfigs['ai_api_key'] ?? '',
                'model' => $systemConfigs['ai_model'] ?? 'gpt-4o',
                'timeout' => 30
            ];
            
            $aiService = new AIService($aiConfig);
            $aiAnalysis = $aiService->analyzeStockHealth($stocktakeItems);
        } else {
            $aiAnalysis = '<div class="alert alert-warning">暂无最近盘点数据</div>';
        }
    } else {
        $aiAnalysis = '<div class="alert alert-info">暂无盘点记录，请先进行盘点</div>';
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>首页 - 星巴克门店智能效期管理系统</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.2rem;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .stat-card h3 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0;
        }
        
        .stat-card p {
            margin: 0;
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .action-card {
            cursor: pointer;
            border-left: 4px solid #667eea;
        }
        
        .action-card:hover {
            border-left-width: 6px;
        }
        
        .action-icon {
            font-size: 2rem;
            color: #667eea;
            margin-bottom: 15px;
        }
        
        .ai-analysis-card {
            border-top: 4px solid #667eea;
        }
        
        .ai-content {
            min-height: 200px;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 10px;
            margin-top: 15px;
        }
        
        .loading-spinner {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 200px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            color: white;
        }
        
        .user-avatar {
            width: 35px;
            height: 35px;
            background-color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
            font-weight: 700;
            margin-right: 10px;
        }
        
        /* 移动端适配 */
        @media (max-width: 768px) {
            /* 导航栏调整 */
            .navbar-brand {
                font-size: 1rem;
            }
            
            .user-info span {
                display: none;
            }
            
            /* 侧边栏调整 */
            .col-md-3 {
                display: none;
            }
            
            .col-md-9 {
                width: 100%;
            }
            
            /* 统计卡片调整 */
            .stat-card h3 {
                font-size: 2rem;
            }
            
            /* 页面标题调整 */
            .page-title {
                font-size: 1.3rem;
            }
            
            /* 按钮调整 */
            .btn {
                font-size: 14px;
                padding: 8px 16px;
            }
            
            /* 卡片样式调整 */
            .card {
                border-radius: 12px;
            }
            
            /* 数据库迁移状态调整 */
            .text-center h4 {
                font-size: 1.5rem;
            }
        }
        
        /* 平板设备适配 */
        @media (min-width: 769px) and (max-width: 1024px) {
            .stat-card h3 {
                font-size: 2.2rem;
            }
            
            .page-title {
                font-size: 1.4rem;
            }
        }
        
        /* 小屏幕平板设备 */
        @media (min-width: 576px) and (max-width: 768px) {
            .col-md-3 {
                display: block;
            }
            
            .col-md-9 {
                width: 75%;
            }
        }
    </style>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-store"></i> 星巴克效期管理
            </a>
            
            <div class="ms-auto">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($currentUser['username'], 0, 1)); ?>
                    </div>
                    <span><?php echo htmlspecialchars($currentUser['username']); ?></span>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- 左侧边栏 -->
            <div class="col-md-3 mb-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">快速菜单</h5>
                        <div class="list-group list-group-flush">
                            <a href="dashboard.php" class="list-group-item list-group-item-action active">
                                <i class="fas fa-tachometer-alt"></i> 首页
                            </a>
                            <a href="stocktake.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-boxes"></i> 盘点系统
                            </a>
                            <a href="admin/categories.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-tags"></i> 分类管理
                            </a>
                            <a href="admin/products.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-box"></i> 物料管理
                            </a>
                            <a href="admin/import_todo.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-upload"></i> 数据导入
                            </a>
                            <a href="admin/ai_config.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-robot"></i> AI 配置
                            </a>
                            <a href="admin/email_config.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-envelope"></i> 邮件配置
                            </a>
                            <hr>
                            <a href="login.php?logout=true" class="list-group-item list-group-item-action text-danger">
                                <i class="fas fa-sign-out-alt"></i> 退出登录
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 右侧主要内容 -->
            <div class="col-md-9">
                <!-- 统计卡片 -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body text-center">
                                <h3><?php echo number_format($stats['total_products']); ?></h3>
                                <p><i class="fas fa-box"></i> 产品总数</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                            <div class="card-body text-center">
                                <h3><?php echo number_format($stats['total_stocktakes']); ?></h3>
                                <p><i class="fas fa-clipboard-check"></i> 盘点记录</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                            <div class="card-body text-center">
                                <h3><?php echo number_format($stats['total_categories']); ?></h3>
                                <p><i class="fas fa-tags"></i> 分类数量</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                            <div class="card-body text-center">
                                <h3><?php echo number_format($stats['expiring_products']); ?></h3>
                                <p><i class="fas fa-exclamation-triangle"></i> 即将过期</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 数据库迁移状态 -->
                <div class="card mb-4">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title">
                            <i class="fas fa-database"></i> 数据库迁移状态
                        </h5>
                    </div>
                    
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <div class="text-center">
                                    <h6 class="text-muted">当前版本</h6>
                                    <h4 class="text-primary"><?php echo $currentVersion; ?></h4>
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <div class="text-center">
                                    <h6 class="text-muted">总迁移数</h6>
                                    <h4 class="text-success"><?php echo count($availableMigrations); ?></h4>
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <div class="text-center">
                                    <h6 class="text-muted">待执行</h6>
                                    <h4 class="<?php echo count($pendingMigrations) > 0 ? 'text-warning' : 'text-success'; ?>">
                                        <?php echo count($pendingMigrations); ?>
                                    </h4>
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <div class="text-center">
                                    <h6 class="text-muted">迁移状态</h6>
                                    <h4 class="<?php echo count($pendingMigrations) > 0 ? 'text-warning' : 'text-success'; ?>">
                                        <?php echo count($pendingMigrations) > 0 ? '需要更新' : '已同步'; ?>
                                    </h4>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (count($pendingMigrations) > 0): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> 
                                数据库结构需要更新。系统会在下一次访问时自动应用迁移。
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> 
                                数据库结构与代码保持一致。
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- 快捷操作 -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <div class="card action-card">
                            <div class="card-body text-center">
                                <i class="fas fa-plus-circle action-icon"></i>
                                <h5 class="card-title">新建盘点单</h5>
                                <p class="card-text">创建新的效期盘点记录</p>
                                <a href="stocktake.php?action=new" class="btn btn-primary">
                                    <i class="fas fa-arrow-right"></i> 开始盘点
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <div class="card action-card">
                            <div class="card-body text-center">
                                <i class="fas fa-history action-icon"></i>
                                <h5 class="card-title">查看往期记录</h5>
                                <p class="card-text">浏览历史盘点数据</p>
                                <a href="stocktake.php?action=history" class="btn btn-primary">
                                    <i class="fas fa-arrow-right"></i> 查看记录
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- AI 智能简报 -->
                <div class="card ai-analysis-card">
                    <div class="card-header bg-transparent">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-robot"></i> AI 智能简报
                            </h5>
                            <?php if (!$aiLoading): ?>
                                <a href="dashboard.php?action=generate_ai_analysis" class="btn btn-sm btn-primary">
                                    <i class="fas fa-sync-alt"></i> 生成分析
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <?php if ($aiLoading): ?>
                            <div class="loading-spinner">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-3">正在生成 AI 分析...</p>
                            </div>
                        <?php elseif (!empty($aiAnalysis)): ?>
                            <div class="ai-content">
                                <?php echo $aiAnalysis; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                点击"生成分析"按钮，AI 将分析最近一次盘点数据并给出库存健康度建议。
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script>
        // 页面加载完成后的初始化
        $(document).ready(function() {
            // 自动刷新统计数据（每5分钟）
            setInterval(function() {
                location.reload();
            }, 300000); // 5分钟 = 300000毫秒
        });
    </script>
</body>
</html>
