<?php
/**
 * ========================================
 * 保质期管理系统 - 综合管理后台
 * 文件名: index.php
 * 版本: v2.8.2
 * 创建日期: 2026-02-15
 * 更新日期: 2026-02-18
 * ========================================
 */

// 升级配置 - 使用安全的内网源
define('APP_VERSION', '2.8.2');
define('UPDATE_URL', null); // 禁用外部自动升级，改用手动升级
define('UPDATE_SERVER', 'feishu'); // 从飞书获取升级包

session_start();
require_once 'db.php';

// 自动迁移
function autoMigrate() {
    $conn = getDBConnection();
    if (!$conn) return;
    
    $cols = [
        'products' => [
            'category_id' => 'INT(11) UNSIGNED DEFAULT 0 AFTER id',
            'inventory_cycle' => "VARCHAR(20) DEFAULT 'none' AFTER removal_buffer",
            'last_inventory_at' => "DATETIME DEFAULT NULL AFTER inventory_cycle"
        ],
        'batches' => [
            'session_id' => 'VARCHAR(50) DEFAULT NULL AFTER quantity'
        ]
    ];
    foreach($cols as $table => $fields) {
        foreach($fields as $col => $def) {
            $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
            if ($res && $res->num_rows == 0) { $conn->query("ALTER TABLE `$table` ADD COLUMN `$col` $def"); }
        }
    }
    
    $conn->query("CREATE TABLE IF NOT EXISTS `categories` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) UNIQUE, type VARCHAR(20), rule TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("INSERT IGNORE INTO `categories` (name, type, rule) VALUES ('小食品', 'snack', '{\"need_buffer\":true, \"scrap_on_removal\":true}'), ('物料', 'material', '{\"need_buffer\":false, \"scrap_on_removal\":false}'), ('咖啡豆', 'coffee', '{\"need_buffer\":true, \"scrap_on_removal\":false, \"allow_gift\":true}')");
    $conn->query("CREATE TABLE IF NOT EXISTS `inventory_sessions` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, session_key VARCHAR(50) UNIQUE, user_id INT UNSIGNED, item_count INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
autoMigrate();

if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api']; $conn = getDBConnection();

    // 登录接口（不需要鉴权）
    if ($action === 'login') {
        $data = json_decode(file_get_contents('php://input'), true);
        $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
        $stmt->bind_param("s", $data['username']); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && password_verify($data['password'], $row['password'])) {
            $_SESSION['user_id'] = $row['id']; $_SESSION['username'] = $row['username'];
            echo json_encode(['success'=>true]); exit;
        }
        echo json_encode(['success'=>false, 'message'=>'账号或密码错误']); exit;
    }
    if ($action === 'logout') { session_destroy(); echo json_encode(['success'=>true]); exit; }
    
    // ⚠️ 安全修复：升级接口需要管理员权限
    if ($action === 'check_upgrade' || $action === 'execute_upgrade') {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success'=>false, 'message'=>'需要登录']); exit;
        }
        // 检查是否是管理员
        $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if (!$result || !$result['is_admin']) {
            echo json_encode(['success'=>false, 'message'=>'需要管理员权限']); exit;
        }
        
        if ($action === 'check_upgrade') {
            // 检查是否有新版本（从飞书或手动检查）
            echo json_encode([
                'success'=>true, 
                'current'=>APP_VERSION, 
                'latest'=>APP_VERSION,
                'has_update'=>false,
                'message'=>'请前往飞书查看最新版本'
            ]); exit;
        }
        
        if ($action === 'execute_upgrade') {
            // 禁用远程升级，需要手动上传文件
            echo json_encode([
                'success'=>false, 
                'message'=>'出于安全考虑，自动升级已禁用。请手动下载升级包并上传。'
            ]); exit;
        }
    }

    checkAuth();
    
    if ($action === 'search_products') {
        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }
        // 模糊搜索：SKU 或 品名
        $like = '%' . $q . '%';
        $stmt = $conn->prepare("SELECT id, sku, name FROM products WHERE sku LIKE ? OR name LIKE ? ORDER BY id DESC LIMIT 20");
        $stmt->bind_param('ss', $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $list = [];
        while ($row = $res->fetch_assoc()) {
            $list[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $list]);
        exit;
    }

    if ($action === 'get_product') {
        $sku = $_GET['sku'] ?? '';
        $stmt = $conn->prepare("SELECT p.*, c.rule as category_rule FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.sku = ? LIMIT 1");
        $stmt->bind_param("s", $sku); $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        if ($product) {
            $stmt_batch = $conn->prepare("SELECT * FROM batches WHERE product_id = ? ORDER BY expiry_date ASC");
            $stmt_batch->bind_param("i", $product['id']); $stmt_batch->execute();
            $batch_res = $stmt_batch->get_result(); $batches = [];
            while ($b = $batch_res->fetch_assoc()) {
                $rule = json_decode($product['category_rule'] ?? '{}', true);
                $buffer = ($rule['need_buffer'] ?? true) ? (int)$product['removal_buffer'] : 0;
                $remDate = date('Y-m-d', strtotime($b['expiry_date']." - $buffer days"));
                $diff = (strtotime($remDate) - strtotime(date('Y-m-d'))) / 86400;
                $b['status'] = $diff < 0 ? 'expired' : ($diff < 30 ? 'urgent' : 'healthy');
                $b['removal_date'] = $remDate; $b['days_left'] = $diff;
                $batches[] = $b;
            }
            $product['batches'] = $batches;
            echo json_encode(['success'=>true, 'exists'=>true, 'product'=>$product]);
        } else { echo json_encode(['success'=>true, 'exists'=>false]); }
        exit;
    }
    if ($action === 'save_product') {
        $data = json_decode(file_get_contents('php://input'), true);
        $sku = $data['sku']; $name = $data['name']; $catid = (int)$data['category_id']; $buffer = (int)$data['removal_buffer'];
        $sid = $data['session_id'] ?? null;
        $stmt = $conn->prepare("SELECT id FROM products WHERE sku = ?");
        $stmt->bind_param("s", $sku); $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            $pid = $res->fetch_assoc()['id'];
            $stmt = $conn->prepare("UPDATE products SET category_id=?, removal_buffer=? WHERE id=?");
            $stmt->bind_param("iii", $catid, $buffer, $pid); $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO products (sku, name, category_id, removal_buffer) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssii", $sku, $name, $catid, $buffer); $stmt->execute();
            $pid = $conn->insert_id;
        }
        foreach ($data['batches'] as $b) {
            $stmt = $conn->prepare("INSERT INTO batches (product_id, expiry_date, quantity, session_id) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isis", $pid, $b['expiry_date'], $b['quantity'], $sid); $stmt->execute();
        }
        echo json_encode(['success'=>true]); exit;
    }
    
    // ⚠️ 安全修复：使用prepared statement防止SQL注入
    if ($action === 'submit_session') {
        $data = json_decode(file_get_contents('php://input'), true);
        $sid = $data['session_id'];
        
        // 使用prepared statement
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM batches WHERE session_id = ?");
        $stmt->bind_param("s", $sid);
        $stmt->execute();
        $res = $stmt->get_result();
        $count = $res->fetch_assoc()['count'];
        
        $stmt = $conn->prepare("INSERT INTO inventory_sessions (session_key, user_id, item_count) VALUES (?, ?, ?)");
        $stmt->bind_param("sii", $sid, $_SESSION['user_id'], $count);
        $stmt->execute();
        
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'get_past_sessions') {
        $res = $conn->query("SELECT * FROM inventory_sessions ORDER BY created_at DESC LIMIT 50");
        $list = []; while($r = $res->fetch_assoc()) $list[] = $r;
        echo json_encode(['success'=>true, 'data'=>$list]); exit;
    }
    if ($action === 'get_session_details') {
        $sid = $_GET['session_id'];
        // ⚠️ 安全修复：使用prepared statement
        $stmt = $conn->prepare("SELECT p.sku, p.name, b.expiry_date, b.quantity, p.removal_buffer 
                                 FROM batches b 
                                 JOIN products p ON b.product_id = p.id 
                                 WHERE b.session_id = ? 
                                 ORDER BY DATE_SUB(b.expiry_date, INTERVAL p.removal_buffer DAY) ASC");
        $stmt->bind_param("s", $sid); $stmt->execute();
        $res = $stmt->get_result(); $list = []; while($r = $res->fetch_assoc()) $list[] = $r;
        echo json_encode(['success'=>true, 'data'=>$list]); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>保质期管理 v<?php echo APP_VERSION; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        :root { 
            --primary-color: #667eea; 
            --secondary-color: #764ba2;
            /* 淡蓝苹果风格 */
            --apple-blue: #007AFF;
            --apple-light-blue: #E3F2FD;
            --apple-bg: #F5F5F7;
        }
        body { 
            background: var(--apple-bg); 
            padding-bottom: 50px; 
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'Segoe UI', sans-serif;
        }
        .app-header { 
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 12px 0; 
            border-bottom: 1px solid rgba(0,0,0,0.1);
            position: sticky; 
            top: 0; 
            z-index: 100; 
        }
        .custom-card { 
            background: white; 
            border-radius: 16px; 
            padding: 16px; 
            margin-bottom: 15px; 
            border: 1px solid rgba(0,0,0,0.05);
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        }
        .portal-btn { 
            background: linear-gradient(135deg, #E3F2FD 0%, #BBDEFB 100%);
            border-radius: 16px; 
            padding: 25px 20px; 
            box-shadow: 0 4px 15px rgba(0,122,255,0.15); 
            margin-bottom: 15px; 
            display: flex; 
            align-items: center; 
            gap: 15px; 
            width: 100%; 
            border: none;
            transition: all 0.3s ease;
        }
        .portal-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,122,255,0.25);
        }
        .portal-btn i { 
            font-size: 2rem; 
            width: 50px; 
            height: 50px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            border-radius: 12px; 
            color: white;
        }
        .bg-new { 
            background: linear-gradient(135deg, #007AFF 0%, #0051D5 100%);
        }
        .bg-past { 
            background: linear-gradient(135deg, #34C759 0%, #248A3D 100%);
        }
        .view-section { display: none; } 
        .view-section.active { display: block; }
        #scanOverlay { 
            position: fixed; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            background: #000; 
            z-index: 2000; 
            display: none; 
            flex-direction: column; 
        }
        #reader { width: 100%; height: 100%; }
        .pending-item { 
            border-left: 4px solid var(--apple-blue); 
            padding: 12px; 
            background: #fff; 
            margin-bottom: 8px; 
            border-radius: 12px; 
            font-size: 0.9rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .btn-primary {
            background: var(--apple-blue);
            border-color: var(--apple-blue);
            border-radius: 12px;
            font-weight: 600;
        }
        .form-control, .form-select {
            border-radius: 12px;
            border: 1px solid rgba(0,0,0,0.1);
            padding: 12px 16px;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--apple-blue);
            box-shadow: 0 0 0 3px rgba(0,122,255,0.1);
        }
    </style>
</head>
<body>
    <div id="scanOverlay">
        <div class="p-3 d-flex justify-content-between text-white">
            <button class="btn btn-dark rounded-pill" id="stopScanBtn">
                <i class="bi bi-x-lg"></i>
            </button>
            <div class="fw-bold">扫一扫</div>
            <div style="width:40px"></div>
        </div>
        <div id="reader"></div>
    </div>
    <div class="app-header mb-3">
        <div class="container d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h5 mb-0 fw-bold" style="color: var(--apple-blue)">
                    保质期管理 v<?php echo APP_VERSION; ?>
                </h1>
            </div>
            <?php if(isset($_SESSION['user_id'])): ?>
            <div class="dropdown">
                <button class="btn btn-light btn-sm rounded-pill" data-bs-toggle="dropdown">
                    <i class="bi bi-list"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                    <li><a class="dropdown-item" href="admin.php">管理后台</a></li>
                    <li><a class="dropdown-item text-danger" href="#" id="logoutBtn">退出登录</a></li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="container">
        <?php if(!isset($_SESSION['user_id'])): ?>
        <div class="custom-card text-center mt-5">
            <h3 class="h4 mb-4 fw-bold">🔐 请登录</h3>
            <form id="loginForm">
                <input type="text" class="form-control mb-3" id="loginUser" placeholder="用户名" required>
                <input type="password" class="form-control mb-3" id="loginPass" placeholder="密码" required>
                <button type="submit" class="btn btn-primary w-100">进入系统</button>
            </form>
        </div>
        <?php else: ?>
        <div id="portalView" class="view-section active">
            <button class="portal-btn" onclick="switchView('new')">
                <i class="bi bi-plus-circle-fill bg-new"></i>
                <div class="text-start">
                    <span class="fw-bold text-dark">新增盘点录入</span><br>
                    <small class="text-muted">快速扫码记效期</small>
                </div>
            </button>
            <button class="portal-btn" onclick="switchView('past')">
                <i class="bi bi-clock-history bg-past"></i>
                <div class="text-start">
                    <span class="fw-bold text-dark">查看往期盘点</span><br>
                    <small class="text-muted">浏览历史记录</small>
                </div>
            </button>
            <div class="custom-card">
                <div class="progress mb-2" style="height:10px">
                    <div id="bar-expired" class="progress-bar bg-danger"></div>
                    <div id="bar-urgent" class="progress-bar bg-warning"></div>
                    <div id="bar-healthy" class="progress-bar bg-success"></div>
                </div>
                <div class="row text-center small g-0">
                    <div class="col-4 text-danger fw-bold" id="val-expired">0</div>
                    <div class="col-4 text-warning fw-bold" id="val-urgent">0</div>
                    <div class="col-4 text-success fw-bold" id="val-healthy">0</div>
                </div>
            </div>
        </div>
        <div id="newView" class="view-section">
            <button class="btn btn-link btn-sm text-decoration-none mb-2" onclick="switchView('portal')">
                <i class="bi bi-chevron-left"></i> 返回门户
            </button>
            <div class="scan-trigger-area mb-3 shadow-sm" 
                 id="startScanBtn" 
                 style="padding:40px 20px; 
                        background: linear-gradient(135deg, #E3F2FD, #BBDEFB); 
                        border-radius: 20px; 
                        text-align: center; 
                        color: #007AFF;">
                <i class="bi bi-qr-code-scan d-block h1"></i>
                <span class="fw-bold">点击添加 (扫一扫)</span>
            </div>

            <!-- 手动输入 / 模糊搜索（扫码失败备用） -->
            <div class="custom-card mb-3">
                <div class="fw-bold mb-2">手动输入 / 模糊搜索</div>
                <div class="input-group">
                    <input id="manualSearchInput" class="form-control" placeholder="输入SKU片段或品名关键词…">
                    <button id="manualSearchBtn" class="btn btn-outline-primary" type="button">搜索</button>
                </div>
                <div id="manualSearchResults" class="mt-2"></div>
                <div class="text-muted small mt-2">提示：也可以直接粘贴整段二维码内容（包含 #）再搜索。</div>
            </div>

            <div id="pendingList"></div>
            <div class="d-grid mt-3">
                <button class="btn btn-primary btn-lg shadow fw-bold" 
                        id="submitSessionBtn" 
                        disabled
                        style="border-radius: 16px;">
                    提交本次盘点单
                </button>
            </div>
        </div>
        <div id="pastView" class="view-section">
            <button class="btn btn-link btn-sm text-decoration-none mb-2" onclick="switchView('portal')">
                <i class="bi bi-chevron-left"></i> 返回门户
            </button>
            <div id="sessionList"></div>
        </div>
        <?php endif; ?>
    </div>
    <div class="modal fade" id="entryModal" data-bs-backdrop="static">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius: 16px; border: none;">
                <div class="modal-header border-bottom-0">
                    <h5 class="fw-bold">录入详情</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <form id="productForm">
                        <div class="custom-card mb-2">
                            <input type="text" class="form-control mb-2" id="sku" readonly>
                            <select class="form-select mb-2" id="categoryId">
                                <option value="0">分类</option>
                            </select>
                            <input type="text" class="form-control mb-2" id="productName" placeholder="商品名称">
                            <input type="number" class="form-control" id="removalBuffer" placeholder="缓冲天数">
                        </div>
                        <div id="batchesContainer"></div>
                        <button type="button" class="btn btn-outline-success btn-sm w-100" id="addBatchBtn">
                            + 批次
                        </button>
                    </form>
                </div>
                <div class="modal-footer border-top-0 d-grid">
                    <button class="btn btn-primary" id="confirmEntryBtn">确定添加</button>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="detailModal">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content" style="border-radius: 16px;">
                <div class="modal-header border-bottom-0">
                    <h5 class="fw-bold">盘点单明细 (AI 整理)</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <table class="table table-sm small mb-0">
                        <thead>
                            <tr>
                                <th>商品</th>
                                <th>效期</th>
                                <th>数</th>
                            </tr>
                        </thead>
                        <tbody id="inventoryDetailBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let html5QrCode = null, currentSessionId = 'S'+Date.now(), pendingData = [];
        function switchView(v) {
            document.querySelectorAll('.view-section').forEach(s => s.classList.remove('active'));
            document.getElementById(v+'View').classList.add('active');
            if(v==='past') loadPast();
            if(v==='new') loadCats();  // 切换到新增盘点视图时加载分类
        }
        function showAlert(m, t='info') { 
            const el = document.createElement('div'); 
            el.className = `alert alert-${t} fade show shadow position-fixed top-0 start-50 translate-middle-x mt-3`; 
            el.style.zIndex='3000'; 
            el.innerText=m; 
            document.body.appendChild(el); 
            setTimeout(()=>el.remove(), 2500); 
        }
        document.addEventListener('DOMContentLoaded', () => {
            if(document.getElementById('portalView')) { refreshHealth(); loadCats(); checkUpgrade(); }
            document.getElementById('loginForm')?.addEventListener('submit', async(e)=>{ 
                e.preventDefault(); 
                const res = await fetch('index.php?api=login',{
                    method:'POST', 
                    body:JSON.stringify({
                        username:document.getElementById('loginUser').value, 
                        password:document.getElementById('loginPass').value
                    })
                }); 
                if((await res.json()).success) location.reload(); 
                else showAlert('账号或密码错误','danger'); 
            });
            document.getElementById('logoutBtn')?.addEventListener('click', async () => { 
                await fetch('index.php?api=logout'); 
                location.reload(); 
            });
            document.getElementById('startScanBtn')?.addEventListener('click', ()=>{ 
                document.getElementById('scanOverlay').style.display='flex'; 
                if(!html5QrCode) html5QrCode = new Html5Qrcode("reader"); 
                html5QrCode.start({facingMode:"environment"}, {fps:15, qrbox:250}, (text)=>{ 
                    document.getElementById('sku').value=text; 
                    html5QrCode.stop(); 
                    document.getElementById('scanOverlay').style.display='none'; 
                    searchSKU(text); 
                }); 
            });
            document.getElementById('stopScanBtn')?.addEventListener('click', ()=>{ 
                if(html5QrCode) html5QrCode.stop(); 
                document.getElementById('scanOverlay').style.display='none'; 
            });
            document.getElementById('addBatchBtn')?.addEventListener('click', ()=>addBatchRow());

            // 手动输入 / 模糊搜索
            document.getElementById('manualSearchBtn')?.addEventListener('click', ()=>manualSearch());
            document.getElementById('manualSearchInput')?.addEventListener('keydown', (e)=>{
                if (e.key === 'Enter') {
                    e.preventDefault();
                    manualSearch();
                }
            });
            document.getElementById('confirmEntryBtn')?.addEventListener('click', ()=>{
                const batches = []; 
                document.querySelectorAll('.batch-row').forEach(r=>{ 
                    batches.push({
                        expiry_date:r.querySelector('.e-in').value, 
                        quantity:r.querySelector('.q-in').value
                    }); 
                });
                pendingData.push({
                    sku:document.getElementById('sku').value, 
                    name:document.getElementById('productName').value, 
                    category_id:document.getElementById('categoryId').value, 
                    removal_buffer:document.getElementById('removalBuffer').value, 
                    batches, 
                    session_id:currentSessionId
                });
                updatePendingList(); 
                bootstrap.Modal.getInstance(document.getElementById('entryModal')).hide();
            });
            document.getElementById('submitSessionBtn')?.addEventListener('click', async()=>{
                for(let item of pendingData) {
                    await fetch('index.php?api=save_product',{
                        method:'POST', 
                        body:JSON.stringify(item)
                    });
                }
                await fetch('index.php?api=submit_session',{
                    method:'POST', 
                    body:JSON.stringify({session_id:currentSessionId})
                });
                showAlert('提交成功','success'); 
                pendingData=[]; 
                currentSessionId='S'+Date.now(); 
                updatePendingList(); 
                switchView('portal'); 
                refreshHealth();
            });
        });
        async function searchSKU(qrCode) {
            // 从二维码中提取SKU
            let sku = qrCode;
            let expiryDateFromQR = null;

            // 解析二维码格式
            // 格式: 00 + SKU(8位) + 生产日期(8位) # 生产日期 # 到期日期
            if (qrCode.includes('#')) {
                const parts = qrCode.split('#');
                if (parts.length >= 3) {
                    let part1 = parts[0]; // 00 + SKU + 生产日期

                    // 去掉前缀 "00"
                    if (part1.startsWith('00')) {
                        part1 = part1.substring(2);
                    }

                    // 提取SKU（前8位）
                    if (part1.length >= 8) {
                        sku = part1.substring(0, 8);
                    }

                    // 解析到期日期（第三部分）
                    let expiryDatePart = parts[2];
                    if (expiryDatePart.length === 8 && /^\d+$/.test(expiryDatePart)) {
                        const year = expiryDatePart.substring(0, 4);
                        const month = expiryDatePart.substring(4, 6);
                        const day = expiryDatePart.substring(6, 8);
                        expiryDateFromQR = `${year}-${month}-${day}`;
                    }

                    console.log('扫码解析:', { qrCode, sku, expiryDate: expiryDateFromQR });
                }
            }

            // 查询商品信息
            const res = await fetch('index.php?api=get_product&sku='+encodeURIComponent(sku));
            const d = await res.json();
            document.getElementById('productForm').reset();
            document.getElementById('batchesContainer').innerHTML='';
            document.getElementById('sku').value = qrCode; // 显示原始二维码
            const fields = ['categoryId','productName','removalBuffer'];

            if(d.exists) {
                document.getElementById('productName').value=d.product.name;
                document.getElementById('categoryId').value=d.product.category_id;
                document.getElementById('removalBuffer').value=d.product.removal_buffer;
                fields.forEach(f => {
                    document.getElementById(f).readOnly=true;
                    if(document.getElementById(f).tagName==='SELECT')
                        document.getElementById(f).disabled=true;
                });
            } else {
                fields.forEach(f => {
                    document.getElementById(f).readOnly=false;
                    if(document.getElementById(f).tagName==='SELECT')
                        document.getElementById(f).disabled=false;
                });
            }
            addBatchRow(expiryDateFromQR);
            new bootstrap.Modal(document.getElementById('entryModal')).show();
        }
        function addBatchRow(defaultExpiryDate = null) {
            const row = document.createElement('div');
            row.className = 'batch-row input-group input-group-sm mb-2';
            row.innerHTML = `
                <span class="input-group-text">效期</span>
                <input type="date" class="form-control e-in" ${defaultExpiryDate ? `value="${defaultExpiryDate}"` : ''} required>
                <span class="input-group-text">数</span>
                <input type="number" class="form-control q-in" placeholder="数量" required>
                <button class="btn btn-outline-danger" onclick="this.parentElement.remove()">×</button>
            `;
            document.getElementById('batchesContainer').appendChild(row);
        }
        async function loadCats() {
            const res = await fetch('api.php?endpoint=categories');
            const d = await res.json();
            const sel = document.getElementById('categoryId');
            sel.innerHTML = '<option value="0">无分类</option>';
            d.categories.forEach(c => {
                sel.innerHTML += `<option value="${c.id}">${c.name}</option>`;
            });
        }
        async function manualSearch() {
            const q = (document.getElementById('manualSearchInput')?.value || '').trim();
            const box = document.getElementById('manualSearchResults');
            if (!box) return;
            box.innerHTML = '';
            if (!q) {
                showAlert('请输入SKU片段或品名关键词', 'warning');
                return;
            }

            // 如果用户粘贴了整段二维码（包含#），直接走录入流程
            if (q.includes('#')) {
                searchSKU(q);
                return;
            }

            const res = await fetch('index.php?api=search_products&q=' + encodeURIComponent(q));
            const d = await res.json();
            if (!d.success) {
                showAlert(d.message || '搜索失败', 'danger');
                return;
            }
            if (!d.data || d.data.length === 0) {
                showAlert('没搜到匹配项', 'warning');
                return;
            }

            const list = document.createElement('div');
            list.className = 'list-group mt-2';
            d.data.forEach((item) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action';
                btn.innerHTML = `<div class="fw-bold">${item.name || '(未命名)'}</div><div class="small text-muted">${item.sku}</div>`;
                btn.addEventListener('click', () => searchSKU(item.sku));
                list.appendChild(btn);
            });
            box.appendChild(list);
        }

        function updatePendingList() {
            const div = document.getElementById('pendingList');
            const btn = document.getElementById('submitSessionBtn');
            div.innerHTML = '';
            if(pendingData.length === 0) {
                btn.disabled = true;
                return;
            }
            btn.disabled = false;
            pendingData.forEach((item, idx) => {
                const el = document.createElement('div');
                el.className = 'pending-item';
                el.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${item.sku}</strong> ${item.name}
                            <br><small class="text-muted">${item.batches.length} 个批次</small>
                        </div>
                        <button class="btn btn-sm btn-outline-danger" onclick="pendingData.splice(${idx},1);updatePendingList()">×</button>
                    </div>
                `;
                div.appendChild(el);
            });
        }
        async function loadPast() {
            const res = await fetch('index.php?api=get_past_sessions');
            const d = await res.json();
            const div = document.getElementById('sessionList');
            div.innerHTML = '';
            d.data.forEach(s => {
                const card = document.createElement('div');
                card.className = 'custom-card';
                card.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>单号: ${s.session_key}</strong>
                            <br><small class="text-muted">${s.created_at}</small>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-primary">${s.item_count} 件</span>
                        </div>
                    </div>
                `;
                card.style.cursor = 'pointer';
                card.addEventListener('click', async() => {
                    const res = await fetch(`index.php?api=get_session_details&session_id=${s.session_key}`);
                    const d = await res.json();
                    const tbody = document.getElementById('inventoryDetailBody');
                    tbody.innerHTML = '';
                    d.data.forEach(item => {
                        tbody.innerHTML += `<tr><td>${item.sku}</td><td>${item.expiry_date}</td><td>${item.quantity}</td></tr>`;
                    });
                    new bootstrap.Modal(document.getElementById('detailModal')).show();
                });
                div.appendChild(card);
            });
        }
        async function refreshHealth() {
            const res = await fetch('api.php?endpoint=summary');
            const d = await res.json();
            document.getElementById('val-expired').innerText = d.summary.expired;
            document.getElementById('val-urgent').innerText = d.summary.urgent;
            document.getElementById('val-healthy').innerText = d.summary.healthy;
            const total = d.summary.expired + d.summary.urgent + d.summary.healthy || 1;
            document.getElementById('bar-expired').style.width = (d.summary.expired/total*100)+'%';
            document.getElementById('bar-urgent').style.width = (d.summary.urgent/total*100)+'%';
            document.getElementById('bar-healthy').style.width = (d.summary.healthy/total*100)+'%';
        }
        async function checkUpgrade() {
            const res = await fetch('index.php?api=check_upgrade');
            const d = await res.json();
            if(d.has_update) {
                showAlert('发现新版本: '+d.latest, 'info');
            }
        }
    </script>
</body>
</html>
