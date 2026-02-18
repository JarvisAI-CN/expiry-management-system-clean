<?php
/**
 * ========================================
 * 保质期管理系统 - 智能管理看板
 * 文件名: dashboard.php
 * 版本: v2.8.0-alpha
 * ========================================
 */
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

define('APP_VERSION', '2.8.0-alpha');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>智能管理看板 - 保质期管理系统</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f5f7fa; }
        .dashboard-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .stat-card {
            padding: 20px;
            border-radius: 12px;
            color: white;
            margin-bottom: 15px;
        }
        .stat-critical { background: linear-gradient(135deg, #dc3545, #c82333); }
        .stat-warning { background: linear-gradient(135deg, #ffc107, #e0a800); color: #000; }
        .stat-reminder { background: linear-gradient(135deg, #17a2b8, #138496); }
        .stat-lowstock { background: linear-gradient(135deg, #6f42c1, #5a32a3); }
        .stat-success { background: linear-gradient(135deg, #28a745, #218838); }
        .warning-item {
            padding: 12px;
            border-left: 4px solid #dc3545;
            background: #fff5f5;
            margin-bottom: 10px;
            border-radius: 6px;
        }
        .warning-item.warning { border-left-color: #ffc107; background: #fffbf0; }
        .warning-item.reminder { border-left-color: #17a2b8; background: #f0fbfc; }
        .warning-item.low_stock { border-left-color: #6f42c1; background: #f8f5ff; }
        .task-item {
            padding: 12px;
            border-left: 4px solid #6c757d;
            background: #f8f9fa;
            margin-bottom: 10px;
            border-radius: 6px;
        }
        .task-item.overdue { border-left-color: #dc3545; background: #fff5f5; }
        .task-item.completed { border-left-color: #28a745; background: #f0fff4; opacity: 0.6; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">
                <i class="bi bi-shield-check"></i> 保质期管理
                <span class="badge bg-success ms-2">v<?php echo APP_VERSION; ?></span>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="index.php"><i class="bi bi-house"></i> 首页</a>
                <a class="nav-link" href="admin.php"><i class="bi bi-gear"></i> 管理后台</a>
                <a class="nav-link" href="#" id="logoutBtn"><i class="bi bi-box-arrow-right"></i> 退出</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h2 class="mb-4"><i class="bi bi-speedometer2"></i> 智能管理看板</h2>

        <!-- 预警统计卡片 -->
        <div class="row">
            <div class="col-md-3">
                <div class="stat-card stat-critical">
                    <h3><i class="bi bi-exclamation-triangle-fill"></i> <span id="criticalCount">0</span></h3>
                    <p class="mb-0">严重预警（≤7天）</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-warning">
                    <h3><i class="bi bi-exclamation-circle-fill"></i> <span id="warningCount">0</span></h3>
                    <p class="mb-0">警告预警（≤15天）</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-reminder">
                    <h3><i class="bi bi-info-circle-fill"></i> <span id="reminderCount">0</span></h3>
                    <p class="mb-0">提醒预警（≤30天）</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-lowstock">
                    <h3><i class="bi bi-box-seam"></i> <span id="lowStockCount">0</span></h3>
                    <p class="mb-0">低库存预警</p>
                </div>
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-md-6">
                <div class="dashboard-card p-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="bi bi-bell"></i> 最新预警</h5>
                        <button class="btn btn-sm btn-primary" onclick="scanWarnings()">
                            <i class="bi bi-arrow-clockwise"></i> 扫描预警
                        </button>
                    </div>
                    <div id="warningsList">
                        <p class="text-muted text-center">加载中...</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="dashboard-card p-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="bi bi-calendar-check"></i> 盘点任务</h5>
                        <button class="btn btn-sm btn-success" onclick="generateTasks()">
                            <i class="bi bi-plus-circle"></i> 生成任务
                        </button>
                    </div>
                    <div id="tasksList">
                        <p class="text-muted text-center">加载中...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 预警配置 -->
        <div class="dashboard-card p-3 mt-3">
            <h5 class="mb-3"><i class="bi bi-gear"></i> 预警配置</h5>
            <form id="warningConfigForm">
                <div class="row">
                    <div class="col-md-3">
                        <label class="form-label">一级预警（天）</label>
                        <input type="number" class="form-control" id="config_level1" value="7">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">二级预警（天）</label>
                        <input type="number" class="form-control" id="config_level2" value="15">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">三级预警（天）</label>
                        <input type="number" class="form-control" id="config_level3" value="30">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">低库存阈值</label>
                        <input type="number" class="form-control" id="config_lowstock" value="10">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3">
                    <i class="bi bi-check-circle"></i> 保存配置
                </button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 加载预警统计
        async function loadWarningStats() {
            const res = await fetch('warnings_api.php?api=get_warning_stats');
            const data = await res.json();
            if (data.success) {
                document.getElementById('criticalCount').textContent = data.stats.critical || 0;
                document.getElementById('warningCount').textContent = data.stats.warning || 0;
                document.getElementById('reminderCount').textContent = data.stats.reminder || 0;
                document.getElementById('lowStockCount').textContent = data.stats.low_stock || 0;
            }
        }

        // 加载预警列表
        async function loadWarnings() {
            const res = await fetch('warnings_api.php?api=get_warnings&limit=10');
            const data = await res.json();
            const container = document.getElementById('warningsList');

            if (data.success && data.warnings.length > 0) {
                container.innerHTML = data.warnings.map(w => `
                    <div class="warning-item ${w.warning_level}">
                        <div class="d-flex justify-content-between">
                            <div>
                                <strong>${w.sku}</strong> - ${w.name}
                                <br><small class="text-muted">${new Date(w.created_at).toLocaleString()}</small>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary" onclick="resolveWarning(${w.id})">
                                <i class="bi bi-check"></i> 解决
                            </button>
                        </div>
                        <p class="mb-0 mt-1">${w.message}</p>
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<p class="text-muted text-center">暂无预警</p>';
            }
        }

        // 扫描预警
        async function scanWarnings() {
            const res = await fetch('warnings_api.php?api=scan_warnings');
            const data = await res.json();
            alert(data.message);
            loadWarningStats();
            loadWarnings();
        }

        // 解决预警
        async function resolveWarning(id) {
            const res = await fetch('warnings_api.php?api=resolve_warning', {
                method: 'POST',
                body: JSON.stringify({ warning_id: id })
            });
            const data = await res.json();
            if (data.success) {
                loadWarningStats();
                loadWarnings();
            }
        }

        // 加载任务列表
        async function loadTasks() {
            const res = await fetch('tasks_api.php?api=get_tasks&limit=10');
            const data = await res.json();
            const container = document.getElementById('tasksList');

            if (data.success && data.tasks.length > 0) {
                const today = new Date().toISOString().split('T')[0];
                container.innerHTML = data.tasks.map(t => {
                    const isOverdue = t.scheduled_date < today && t.status !== 'completed';
                    return `
                    <div class="task-item ${isOverdue ? 'overdue' : ''} ${t.status === 'completed' ? 'completed' : ''}">
                        <div class="d-flex justify-content-between">
                            <div>
                                <strong>${t.sku}</strong> - ${t.name}
                                <br><small class="text-muted">计划: ${t.scheduled_date} | 类型: ${t.task_type}</small>
                            </div>
                            <button class="btn btn-sm ${t.status === 'completed' ? 'btn-success' : 'btn-primary'}"
                                    onclick="updateTaskStatus(${t.id}, '${t.status === 'completed' ? 'pending' : 'completed'}')">
                                ${t.status === 'completed' ? '<i class="bi bi-check-circle"></i> 已完成' : '<i class="bi bi-circle"></i> 标记完成'}
                            </button>
                        </div>
                    </div>
                `}).join('');
            } else {
                container.innerHTML = '<p class="text-muted text-center">暂无任务</p>';
            }
        }

        // 生成任务
        async function generateTasks() {
            const res = await fetch('tasks_api.php?api=generate_tasks');
            const data = await res.json();
            alert(data.message);
            loadTasks();
        }

        // 更新任务状态
        async function updateTaskStatus(id, status) {
            const res = await fetch('tasks_api.php?api=update_task_status', {
                method: 'POST',
                body: JSON.stringify({ task_id: id, status: status })
            });
            const data = await res.json();
            if (data.success) {
                loadTasks();
            }
        }

        // 加载配置
        async function loadConfig() {
            const res = await fetch('warnings_api.php?api=get_warning_config');
            const data = await res.json();
            if (data.success) {
                document.getElementById('config_level1').value = data.config.warning_days_level1;
                document.getElementById('config_level2').value = data.config.warning_days_level2;
                document.getElementById('config_level3').value = data.config.warning_days_level3;
                document.getElementById('config_lowstock').value = data.config.low_stock_threshold;
            }
        }

        // 保存配置
        document.getElementById('warningConfigForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const config = {
                warning_days_level1: document.getElementById('config_level1').value,
                warning_days_level2: document.getElementById('config_level2').value,
                warning_days_level3: document.getElementById('config_level3').value,
                low_stock_threshold: document.getElementById('config_lowstock').value
            };
            const res = await fetch('warnings_api.php?api=update_warning_config', {
                method: 'POST',
                body: JSON.stringify(config)
            });
            const data = await res.json();
            alert(data.message);
            loadWarningStats();
            loadWarnings();
        });

        // 登出
        document.getElementById('logoutBtn').addEventListener('click', async (e) => {
            e.preventDefault();
            await fetch('index.php?api=logout');
            window.location.href = 'index.php';
        });

        // 初始化加载
        loadWarningStats();
        loadWarnings();
        loadTasks();
        loadConfig();
    </script>
</body>
</html>
