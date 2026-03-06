<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 盘点历史记录页面
 * 功能：查看所有盘点历史记录
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-03-06
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
$authService = new AuthService($pdo, [
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => isset($_SERVER['HTTPS'])
]);

// 检查用户登录状态
if (!$authService->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// 获取所有盘点记录
try {
    $stmt = $pdo->query("
        SELECT
            si.id,
            si.session_code,
            si.description,
            si.status,
            si.created_at,
            COUNT(se.id) as item_count,
            SUM(CASE
                WHEN se.expiry_date < CURDATE() THEN 1
                ELSE 0
            END) as expired_count,
            SUM(CASE
                WHEN se.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1
                ELSE 0
            END) as expiring_soon_count
        FROM stocktake_sessions si
        LEFT JOIN stocktake_entries se ON si.id = se.session_id
        GROUP BY si.id
        ORDER BY si.created_at DESC
    ");

    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sessions = [];
    $error = "获取盘点记录失败: " . $e->getMessage();
}

// 页面标题
$pageTitle = "盘点历史 - 星巴克效期管理系统";
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #f5f5f5;
        }

        .navbar {
            background: linear-gradient(135deg, #00704A 0%, #005c3d 100%);
        }

        .navbar-brand {
            color: white !important;
            font-weight: bold;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.85) !important;
        }

        .nav-link:hover {
            color: white !important;
        }

        .card {
            border: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .card-header {
            background: linear-gradient(135deg, #00704A 0%, #005c3d 100%);
            color: white;
            font-weight: bold;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-draft {
            background-color: #ffc107;
            color: #000;
        }

        .status-completed {
            background-color: #198754;
            color: #fff;
        }

        .btn-export {
            background: linear-gradient(135deg, #00704A 0%, #005c3d 100%);
            border: none;
            color: white;
        }

        .btn-export:hover {
            background: linear-gradient(135deg, #005c3d 0%, #004530 100%);
            color: white;
        }
    </style>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-coffee"></i> 星巴克效期管理系统
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> 首页
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="stocktake.php">
                            <i class="fas fa-clipboard-list"></i> 盘点
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="export_history.php">
                            <i class="fas fa-history"></i> 历史记录
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-cogs"></i> 管理
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="admin/categories.php">分类管理</a></li>
                            <li><a class="dropdown-item" href="admin/products.php">物料管理</a></li>
                            <li><a class="dropdown-item" href="admin/import_todo.php">数据导入</a></li>
                        </ul>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle"></i>
                            <?php echo htmlspecialchars($authService->getCurrentUser()['username']); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="admin/ai_config.php">AI配置</a></li>
                            <li><a class="dropdown-item" href="admin/email_config.php">邮件配置</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php">退出登录</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- 主内容区 -->
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-history"></i> 盘点历史记录</span>
                        <button class="btn btn-light btn-sm" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> 刷新
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($sessions)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                暂无盘点记录
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table id="historyTable" class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>盘点单号</th>
                                            <th>描述</th>
                                            <th>状态</th>
                                            <th>创建时间</th>
                                            <th>商品数</th>
                                            <th>已过期</th>
                                            <th>即将过期</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($sessions as $session): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($session['session_code']); ?></td>
                                                <td><?php echo htmlspecialchars($session['description'] ?? '-'); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $session['status']; ?>">
                                                        <?php echo $session['status'] === 'completed' ? '已完成' : '草稿'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('Y-m-d H:i', strtotime($session['created_at'])); ?></td>
                                                <td><?php echo $session['item_count']; ?></td>
                                                <td class="text-danger"><?php echo $session['expired_count']; ?></td>
                                                <td class="text-warning"><?php echo $session['expiring_soon_count']; ?></td>
                                                <td>
                                                    <?php if ($session['status'] === 'completed'): ?>
                                                        <button class="btn btn-export btn-sm"
                                                                onclick="exportStocktake(<?php echo $session['id']; ?>)">
                                                            <i class="fas fa-file-export"></i> 导出
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#historyTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/zh.json'
                },
                pageLength: 25,
                order: [[3, 'desc']]
            });
        });

        function exportStocktake(sessionId) {
            if (!confirm('确定要导出此盘点数据吗？')) {
                return;
            }

            // 调用导出API
            $.ajax({
                url: 'api/export_stocktake.php',
                method: 'GET',
                data: { session_id: sessionId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // 下载文件
                        const link = document.createElement('a');
                        link.href = response.data.download_url;
                        link.download = response.data.filename;
                        link.click();

                        alert('导出成功！共 ' + response.data.record_count + ' 条记录');
                    } else {
                        alert('导出失败：' + response.message);
                    }
                },
                error: function() {
                    alert('导出失败，请稍后重试');
                }
            });
        }
    </script>
</body>
</html>
