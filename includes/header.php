<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 页面头部组件
 * 功能：包含页面通用的CSS、JavaScript和导航栏
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

// 检查是否已加载会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 获取当前用户信息（如果已登录）
$currentUser = null;

if (isset($_SESSION['user'])) {
    $currentUser = $_SESSION['user'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? '效期管理系统'; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    <!-- Custom CSS -->
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
        
        .sidebar {
            background-color: white;
            border-right: 1px solid #e9ecef;
            min-height: calc(100vh - 60px);
        }
        
        .sidebar .nav-link {
            color: #495057;
            font-weight: 500;
            border-left: 3px solid transparent;
            padding: 12px 20px;
        }
        
        .sidebar .nav-link:hover {
            background-color: #f8f9fa;
            border-left-color: #667eea;
        }
        
        .sidebar .nav-link.active {
            background-color: #e7f1ff;
            border-left-color: #667eea;
            color: #667eea;
            font-weight: 600;
        }
        
        .main-content {
            padding: 20px;
            background-color: #f8f9fa;
            min-height: calc(100vh - 60px);
        }
        
        .page-title {
            color: #333;
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a3f8f 100%);
            transform: translateY(-2px);
        }
        
        .btn-outline-primary {
            border-color: #667eea;
            color: #667eea;
            font-weight: 600;
        }
        
        .btn-outline-primary:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: #667eea;
        }
        
        .table-container {
            background-color: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }
        
        .table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .table thead th {
            border: none;
            padding: 12px 20px;
            font-weight: 600;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .alert {
            border-radius: 10px;
            border-left: 4px solid;
        }
        
        .alert-primary {
            border-color: #667eea;
        }
        
        .alert-success {
            border-color: #28a745;
        }
        
        .alert-warning {
            border-color: #ffc107;
        }
        
        .alert-danger {
            border-color: #dc3545;
        }
        
        .form-control {
            border-radius: 10px;
            border: 1px solid #dee2e6;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .nav-pills .nav-link {
            border-radius: 10px;
            margin-right: 10px;
        }
        
        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
    </style>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-store"></i> 星巴克效期管理
            </a>
            
            <div class="ms-auto">
                <?php if ($currentUser): ?>
                    <div class="d-flex align-items-center">
                        <div class="user-info me-3">
                            <div class="user-avatar" style="display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; background-color: white; border-radius: 50%; color: #667eea; font-weight: 700; font-size: 18px;">
                                <?php echo strtoupper(substr($currentUser['username'], 0, 1)); ?>
                            </div>
                            <span style="color: white; margin-left: 10px; font-weight: 600;">
                                <?php echo htmlspecialchars($currentUser['username']); ?>
                            </span>
                        </div>
                        <a href="login.php?logout=true" class="btn btn-sm btn-outline-light">
                            <i class="fas fa-sign-out-alt"></i> 退出
                        </a>
                    </div>
                <?php else: ?>
                    <a href="login.php" class="btn btn-sm btn-outline-light">
                        <i class="fas fa-sign-in-alt"></i> 登录
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="d-flex">
        <!-- 左侧边栏 -->
        <?php if ($currentUser): ?>
        <nav class="col-md-3 col-lg-2 sidebar">
            <div class="list-group list-group-flush mt-3">
                <a href="dashboard.php" class="list-group-item list-group-item-action <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt me-2"></i> 首页
                </a>
                
                <a href="stocktake.php" class="list-group-item list-group-item-action <?php echo basename($_SERVER['PHP_SELF']) == 'stocktake.php' ? 'active' : ''; ?>">
                    <i class="fas fa-boxes me-2"></i> 盘点系统
                </a>
                
                <div class="dropdown">
                    <a href="#" class="list-group-item list-group-item-action dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-cog me-2"></i> 系统管理
                    </a>
                    <ul class="dropdown-menu">
                        <li><a href="admin/categories.php" class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'categories.php' ? 'active' : ''; ?>">
                            <i class="fas fa-tags me-2"></i> 分类管理
                        </a></li>
                        <li><a href="admin/products.php" class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'products.php' ? 'active' : ''; ?>">
                            <i class="fas fa-box me-2"></i> 物料管理
                        </a></li>
                        <li><a href="admin/import_todo.php" class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'import_todo.php' ? 'active' : ''; ?>">
                            <i class="fas fa-upload me-2"></i> 数据导入
                        </a></li>
                        <li><a href="admin/ai_config.php" class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'ai_config.php' ? 'active' : ''; ?>">
                            <i class="fas fa-robot me-2"></i> AI 配置
                        </a></li>
                        <li><a href="admin/email_config.php" class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'email_config.php' ? 'active' : ''; ?>">
                            <i class="fas fa-envelope me-2"></i> 邮件配置
                        </a></li>
                    </ul>
                </div>
            </div>
        </nav>
        <?php endif; ?>

        <!-- 主要内容区域 -->
        <main class="flex-grow main-content">
