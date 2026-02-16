<?php
/**
 * ========================================
 * 保质期管理系统 - 综合管理后台
 * 文件名: index.php
 * 版本: v2.7.3-alpha
 * 创建日期: 2026-02-15
 * ========================================
 */

// 升级配置
define('APP_VERSION', '2.7.3-alpha');
define('UPDATE_URL', 'https://raw.githubusercontent.com/JarvisAI-CN/expiry-management-system/main/');
define('FALLBACK_URL', 'http://150.109.204.23:8888/');

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
    
    if ($action === 'check_upgrade') {
        $latest = @file_get_contents(UPDATE_URL . 'VERSION.txt');
        if (!$latest) $latest = @file_get_contents(FALLBACK_URL . 'VERSION.txt');
        if ($latest) {
            $latest = trim($latest);
            echo json_encode(['success'=>true, 'current'=>APP_VERSION, 'latest'=>$latest, 'has_update'=>version_compare($latest, APP_VERSION, '>')]);
        } else { echo json_encode(['success'=>false]); }
        exit;
    }

    if ($action === 'execute_upgrade') {
        $files = ['index.php', 'db.php', 'install.php', 'admin.php', 'VERSION.txt'];
        foreach ($files as $f) {
            $ctx = stream_context_create(['http'=>['timeout'=>10]]);
            $c = @file_get_contents(UPDATE_URL . $f, false, $ctx);
            if (!$c) $c = @file_get_contents(FALLBACK_URL . $f);
            if ($c) @file_put_contents(__DIR__ . '/' . $f, $c);
        }
        echo json_encode(['success'=>true]); exit;
    }

    checkAuth();
    
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
                $b['removal_date'] = $remDate; $b['days_to_removal'] = floor($diff);
                $b['status'] = $diff < 0 ? 'expired' : ($diff <= 30 ? 'warning' : 'normal');
                $batches[] = $b;
            }
            echo json_encode(['success'=>true, 'exists'=>true, 'product'=>$product, 'batches'=>$batches]);
        } else { echo json_encode(['success'=>true, 'exists'=>false]); } exit;
    }

    if ($action === 'save_product') {
        $data = json_decode(file_get_contents('php://input'), true);
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT id FROM products WHERE sku = ?");
            $stmt->bind_param("s", $data['sku']); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $pid = $row['id'];
                $stmt = $conn->prepare("UPDATE products SET name=?, category_id=?, removal_buffer=? WHERE id=?");
                $stmt->bind_param("siii", $data['name'], $data['category_id'], $data['removal_buffer'], $pid);
                $stmt->execute();
            } else {
                $stmt = $conn->prepare("INSERT INTO products (sku, name, category_id, removal_buffer) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssii", $data['sku'], $data['name'], $data['category_id'], $data['removal_buffer']);
                $stmt->execute(); $pid = $conn->insert_id;
            }
            $stmt = $conn->prepare("INSERT INTO batches (product_id, expiry_date, quantity, session_id) VALUES (?, ?, ?, ?)");
            foreach ($data['batches'] as $b) { $stmt->bind_param("isis", $pid, $b['expiry_date'], $b['quantity'], $data['session_id']); $stmt->execute(); }
            $conn->query("UPDATE products SET last_inventory_at = NOW() WHERE id = $pid");
            $conn->commit(); echo json_encode(['success'=>true]);
        } catch (Exception $e) { $conn->rollback(); echo json_encode(['success'=>false, 'message'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'get_health_report') {
        $query = "SELECT SUM(CASE WHEN DATE_SUB(b.expiry_date, INTERVAL p.removal_buffer DAY) < CURDATE() THEN 1 ELSE 0 END) as expired, SUM(CASE WHEN DATE_SUB(b.expiry_date, INTERVAL p.removal_buffer DAY) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as urgent, SUM(CASE WHEN DATE_SUB(b.expiry_date, INTERVAL p.removal_buffer DAY) > DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as healthy FROM batches b JOIN products p ON b.product_id = p.id";
        echo json_encode(['success'=>true, 'report'=>$conn->query($query)->fetch_assoc()]); exit;
    }
    if ($action === 'submit_session') {
        $data = json_decode(file_get_contents('php://input'), true);
        $sid = $data['session_id']; $res = $conn->query("SELECT COUNT(*) as count FROM batches WHERE session_id = '$sid'");
        $count = $res->fetch_assoc()['count'];
        $conn->query("INSERT INTO inventory_sessions (session_key, user_id, item_count) VALUES ('$sid', {$_SESSION['user_id']}, $count)");
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'get_past_sessions') {
        $res = $conn->query("SELECT * FROM inventory_sessions ORDER BY created_at DESC");
        $list = []; while($r = $res->fetch_assoc()) $list[] = $r;
        echo json_encode(['success'=>true, 'sessions'=>$list]); exit;
    }
    if ($action === 'get_session_details') {
        $sid = $_GET['session_id'];
        $query = "SELECT p.sku, p.name, b.expiry_date, b.quantity, p.removal_buffer FROM batches b JOIN products p ON b.product_id = p.id WHERE b.session_id = ? ORDER BY DATE_SUB(b.expiry_date, INTERVAL p.removal_buffer DAY) ASC";
        $stmt = $conn->prepare($query); $stmt->bind_param("s", $sid); $stmt->execute();
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
        :root { --primary-color: #667eea; --secondary-color: #764ba2; }
        body { background: #f0f2f5; padding-bottom: 50px; font-family: sans-serif; }
        .app-header { background: #fff; padding: 12px 0; box-shadow: 0 2px 10px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 100; }
        .custom-card { background: white; border-radius: 12px; padding: 16px; margin-bottom: 15px; border: none; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .portal-btn { background: white; border-radius: 15px; padding: 25px 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 15px; display: flex; align-items: center; gap: 15px; width: 100%; border: none; }
        .portal-btn i { font-size: 2rem; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 10px; color: white; }
        .bg-new { background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%); }
        .bg-past { background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%); }
        .view-section { display: none; } .view-section.active { display: block; }
        #scanOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #000; z-index: 2000; display: none; flex-direction: column; }
        #reader { width: 100%; height: 100%; }
        .pending-item { border-left: 4px solid var(--primary-color); padding: 10px; background: #fff; margin-bottom: 8px; border-radius: 8px; font-size: 0.8rem; }
    </style>
</head>
<body>
    <div id="scanOverlay">
        <div class="p-3 d-flex justify-content-between text-white"><button class="btn btn-dark rounded-pill" id="stopScanBtn"><i class="bi bi-x-lg"></i></button><div class="fw-bold">扫一扫</div><div style="width:40px"></div></div>
        <div id="reader"></div>
    </div>
    <div class="app-header mb-3"><div class="container d-flex justify-content-between align-items-center">
        <div><h1 class="h5 mb-0 text-primary fw-bold">保质期管理 v<?php echo APP_VERSION; ?></h1></div>
        <?php if(isset($_SESSION['user_id'])): ?>
        <div class="dropdown"><button class="btn btn-light btn-sm rounded-pill" data-bs-toggle="dropdown"><i class="bi bi-list"></i></button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                <li><a class="dropdown-item" href="admin.php">管理后台</a></li>
                <li><a class="dropdown-item text-danger" href="#" id="logoutBtn">退出登录</a></li>
            </ul>
        </div><?php endif; ?>
    </div></div>
    <div class="container">
        <?php if(!isset($_SESSION['user_id'])): ?>
        <div class="custom-card text-center mt-5"><h3 class="h5 mb-4 fw-bold">⚡ 请登录</h3><form id="loginForm"><input type="text" class="form-control mb-3" id="loginUser" placeholder="用户名" required><input type="password" class="form-control mb-3" id="loginPass" placeholder="密码" required><button type="submit" class="btn btn-primary w-100">进入</button></form></div>
        <?php else: ?>
        <div id="portalView" class="view-section active">
            <button class="portal-btn" onclick="switchView('new')"><i class="bi bi-plus-circle-fill bg-new"></i><div class="text-start"><span class="fw-bold">新增盘点录入</span><br><small class="text-muted">快速扫码记效期</small></div></button>
            <button class="portal-btn" onclick="switchView('past')"><i class="bi bi-clock-history bg-past"></i><div class="text-start"><span class="fw-bold">查看往期盘点</span><br><small class="text-muted">浏览历史记录</small></div></button>
            <div class="custom-card"><div class="progress mb-2" style="height:10px"><div id="bar-expired" class="progress-bar bg-danger"></div><div id="bar-urgent" class="progress-bar bg-warning"></div><div id="bar-healthy" class="progress-bar bg-success"></div></div><div class="row text-center small g-0"><div class="col-4 text-danger fw-bold" id="val-expired">0</div><div class="col-4 text-warning fw-bold" id="val-urgent">0</div><div class="col-4 text-success fw-bold" id="val-healthy">0</div></div></div>
        </div>
        <div id="newView" class="view-section">
            <button class="btn btn-link btn-sm text-decoration-none mb-2" onclick="switchView('portal')"><i class="bi bi-chevron-left"></i> 返回门户</button>
            <div class="scan-trigger-area mb-3 shadow-sm" id="startScanBtn" style="padding:40px 20px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 15px; text-align: center; color: white;"><i class="bi bi-qr-code-scan d-block h1"></i><span>点击添加 (扫一扫)</span></div>
            <div id="pendingList"></div>
            <div class="d-grid mt-3"><button class="btn btn-primary btn-lg shadow fw-bold" id="submitSessionBtn" disabled>提交本次盘点单</button></div>
        </div>
        <div id="pastView" class="view-section"><button class="btn btn-link btn-sm text-decoration-none mb-2" onclick="switchView('portal')"><i class="bi bi-chevron-left"></i> 返回门户</button><div id="sessionList"></div></div>
        <?php endif; ?>
    </div>
    <div class="modal fade" id="entryModal" data-bs-backdrop="static"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>录入详情</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
        <form id="productForm"><div class="custom-card mb-2"><input type="text" class="form-control mb-2" id="sku" readonly><select class="form-select mb-2" id="categoryId"><option value="0">分类</option></select><input type="text" class="form-control mb-2" id="productName" placeholder="商品名称"><input type="number" class="form-control" id="removalBuffer" placeholder="缓冲天数"></div><div id="batchesContainer"></div><button type="button" class="btn btn-outline-success btn-sm w-100" id="addBatchBtn">+ 批次</button></form>
    </div><div class="modal-footer d-grid"><button class="btn btn-primary" id="confirmEntryBtn">确定添加</button></div></div></div></div>
    <div class="modal fade" id="detailModal"><div class="modal-dialog modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5>盘点单明细 (AI 整理)</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><table class="table table-sm small mb-0"><thead><tr><th>商品</th><th>效期</th><th>数</th></tr></thead><tbody id="inventoryDetailBody"></tbody></table></div></div></div></div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let html5QrCode = null, currentSessionId = 'S'+Date.now(), pendingData = [];
        function switchView(v) { document.querySelectorAll('.view-section').forEach(s => s.classList.remove('active')); document.getElementById(v+'View').classList.add('active'); if(v==='past') loadPast(); }
        function showAlert(m, t='info') { const el = document.createElement('div'); el.className = `alert alert-${t} fade show shadow position-fixed top-0 start-50 translate-middle-x mt-3`; el.style.zIndex='3000'; el.innerText=m; document.body.appendChild(el); setTimeout(()=>el.remove(), 2500); }
        document.addEventListener('DOMContentLoaded', () => {
            if(document.getElementById('portalView')) { refreshHealth(); loadCats(); checkUpgrade(); }
            document.getElementById('loginForm')?.addEventListener('submit', async(e)=>{ e.preventDefault(); const res = await fetch('index.php?api=login',{method:'POST', body:JSON.stringify({username:document.getElementById('loginUser').value, password:document.getElementById('loginPass').value})}); if((await res.json()).success) location.reload(); else showAlert('错误','danger'); });
            document.getElementById('logoutBtn')?.addEventListener('click', async () => { await fetch('index.php?api=logout'); location.reload(); });
            document.getElementById('startScanBtn')?.addEventListener('click', ()=>{ document.getElementById('scanOverlay').style.display='flex'; if(!html5QrCode) html5QrCode = new Html5Qrcode("reader"); html5QrCode.start({facingMode:"environment"}, {fps:15, qrbox:250}, (text)=>{ document.getElementById('sku').value=text; html5QrCode.stop(); document.getElementById('scanOverlay').style.display='none'; searchSKU(text); }); });
            document.getElementById('stopScanBtn')?.addEventListener('click', ()=>{ if(html5QrCode) html5QrCode.stop(); document.getElementById('scanOverlay').style.display='none'; });
            document.getElementById('addBatchBtn')?.addEventListener('click', ()=>addBatchRow());
            document.getElementById('confirmEntryBtn')?.addEventListener('click', ()=>{
                const batches = []; document.querySelectorAll('.batch-row').forEach(r=>{ batches.push({expiry_date:r.querySelector('.e-in').value, quantity:r.querySelector('.q-in').value}); });
                pendingData.push({sku:document.getElementById('sku').value, name:document.getElementById('productName').value, category_id:document.getElementById('categoryId').value, removal_buffer:document.getElementById('removalBuffer').value, batches, session_id:currentSessionId});
                updatePendingList(); bootstrap.Modal.getInstance(document.getElementById('entryModal')).hide();
            });
            document.getElementById('submitSessionBtn')?.addEventListener('click', async()=>{
                for(let item of pendingData) await fetch('index.php?api=save_product',{method:'POST', body:JSON.stringify(item)});
                await fetch('index.php?api=submit_session',{method:'POST', body:JSON.stringify({session_id:currentSessionId})});
                showAlert('提交成功','success'); pendingData=[]; currentSessionId='S'+Date.now(); updatePendingList(); switchView('portal'); refreshHealth();
            });
        });
        async function searchSKU(sku) {
            const res = await fetch('index.php?api=get_product&sku='+sku); const d = await res.json();
            document.getElementById('productForm').reset(); document.getElementById('batchesContainer').innerHTML='';
            document.getElementById('sku').value = sku; const fields = ['categoryId','productName','removalBuffer'];
            if(d.exists) {
                document.getElementById('productName').value=d.product.name; document.getElementById('categoryId').value=d.product.category_id; document.getElementById('removalBuffer').value=d.product.removal_buffer;
                fields.forEach(f => { document.getElementById(f).readOnly=true; if(document.getElementById(f).tagName==='SELECT') document.getElementById(f).disabled=true; });
            } else { fields.forEach(f => { document.getElementById(f).readOnly=false; if(document.getElementById(f).tagName==='SELECT') document.getElementById(f).disabled=false; }); }
            addBatchRow(); new bootstrap.Modal(document.getElementById('entryModal')).show();
        }
        function addBatchRow(data=null) {
            const div = document.createElement('div'); div.className='batch-row row g-1 mb-2';
            div.innerHTML=`<div class="col-7"><input type="date" class="form-control form-control-sm e-in" value="${data?data.expiry_date:''}" required></div><div class="col-3"><input type="number" class="form-control form-control-sm q-in" placeholder="数" value="${data?data.quantity:''}" required></div><div class="col-2"><button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="this.parentElement.parentElement.remove()"><i class="bi bi-trash"></i></button></div>`;
            document.getElementById('batchesContainer').appendChild(div);
        }
        function updatePendingList() {
            document.getElementById('submitSessionBtn').disabled = pendingData.length===0;
            document.getElementById('pendingList').innerHTML = pendingData.map(i=>`<div class="pending-item shadow-sm"><div><b>${i.name}</b></div><small class="text-muted">${i.sku} · ${i.batches.length}批</small></div>`).join('') || '<div class="text-center py-5 text-muted small">暂无数据</div>';
        }
        async function loadPast() {
            const res = await fetch('index.php?api=get_past_sessions'); const d = await res.json();
            document.getElementById('sessionList').innerHTML = d.sessions.map(s=>`<div class="custom-card mb-2" onclick="showSessionDetail('${s.session_key}')"><div class="d-flex justify-content-between align-items-center"><div><b>盘点单 ${s.session_key}</b><br><small class="text-muted">${s.created_at} · ${s.item_count}品项</small></div><i class="bi bi-chevron-right"></i></div></div>`).join('');
        }
        async function showSessionDetail(sid) {
            const res = await fetch('index.php?api=get_session_details&session_id='+sid); const d = await res.json();
            document.getElementById('inventoryDetailBody').innerHTML = d.data.map(i=>`<tr><td><b>${i.name}</b><br><small>${i.sku}</small></td><td>${i.expiry_date}</td><td>${i.quantity}</td></tr>`).join('');
            new bootstrap.Modal(document.getElementById('detailModal')).show();
        }
        async function loadCats() {
            const res = await fetch('index.php?api=get_categories'); const d = await res.json();
            document.getElementById('categoryId').innerHTML = '<option value="0">选择分类</option>' + d.categories.map(c=>`<option value="${c.id}">${c.name}</option>`).join('');
        }
        async function refreshHealth() {
            const res = await fetch('index.php?api=get_health_report'); const d = (await res.json()).report;
            const t = parseInt(d.expired)+parseInt(d.urgent)+parseInt(d.healthy);
            if(t>0) { document.getElementById('bar-expired').style.width=(d.expired/t*100)+'%'; document.getElementById('bar-urgent').style.width=(d.urgent/t*100)+'%'; document.getElementById('bar-healthy').style.width=(d.healthy/t*100)+'%'; }
            document.getElementById('val-expired').innerText=d.expired; document.getElementById('val-urgent').innerText=d.urgent; document.getElementById('val-healthy').innerText=d.healthy;
        }
        async function checkUpgrade() {
            const res = await fetch('index.php?api=check_upgrade'); const d = await res.json();
            if(d.has_update) {
                const b = document.createElement('button'); b.className='btn btn-warning btn-sm w-100 mb-3'; b.innerText='发现新版本 v'+d.latest+', 点击升级';
                b.onclick = async() => { b.disabled=true; b.innerText='升级中...'; await fetch('index.php?api=execute_upgrade'); location.reload(); };
                document.getElementById('portalView').prepend(b);
            }
        }
    </script>
</body>
</html>
