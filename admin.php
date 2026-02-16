<?php
/**
 * ========================================
 * 保质期管理系统 - 管理员控制台
 * 文件名: admin.php
 * 版本: v2.7.3-alpha
 * ========================================
 */
session_start();
require_once 'db.php';

// 严格权限检查
if (!isset($_SESSION['user_id'])) { 
    if (isset($_GET['api'])) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false, 'message'=>'Session Expired']);
        exit;
    }
    header("Location: index.php");
    exit; 
}

define('APP_VERSION', '2.7.3-alpha');
define('UPDATE_URL', 'https://raw.githubusercontent.com/JarvisAI-CN/expiry-management-system/main/');
define('FALLBACK_URL', 'http://150.109.204.23:8888/');

// 处理管理端 API 请求
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];
    $conn = getDBConnection();

    // 1. 用户管理
    if ($action === 'get_users') {
        $res = $conn->query("SELECT id, username, role, created_at FROM users");
        $list = []; while($r = $res->fetch_assoc()) $list[] = $r;
        echo json_encode(['success'=>true, 'users'=>$list]); exit;
    }
    if ($action === 'add_user') {
        $data = json_decode(file_get_contents('php://input'), true);
        $hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        $stmt->bind_param("ss", $data['username'], $hash);
        echo json_encode(['success'=>$stmt->execute()]); exit;
    }

    // 2. 分类管理
    if ($action === 'get_categories') {
        $res = $conn->query("SELECT * FROM categories"); $list = [];
        while($r = $res->fetch_assoc()) $list[] = $r;
        echo json_encode(['success'=>true, 'categories'=>$list]); exit;
    }
    if ($action === 'save_category') {
        $data = json_decode(file_get_contents('php://input'), true);
        $stmt = $conn->prepare("INSERT INTO categories (name, type, rule) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE type=VALUES(type), rule=VALUES(rule)");
        $stmt->bind_param("sss", $data['name'], $data['type'], $data['rule']);
        echo json_encode(['success'=>$stmt->execute()]); exit;
    }

    // 3. 商品资料管理
    if ($action === 'get_all_products') {
        $res = $conn->query("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.created_at DESC");
        $list = []; while($r = $res->fetch_assoc()) $list[] = $r;
        echo json_encode(['success'=>true, 'data'=>$list]); exit;
    }
    if ($action === 'update_product_meta') {
        $data = json_decode(file_get_contents('php://input'), true);
        $stmt = $conn->prepare("UPDATE products SET category_id = ?, inventory_cycle = ? WHERE id = ?");
        $stmt->bind_param("isi", $data['category_id'], $data['inventory_cycle'], $data['id']);
        echo json_encode(['success'=>$stmt->execute()]); exit;
    }

    // 4. AI & 系统设置
    if ($action === 'get_settings') {
        echo json_encode(['success'=>true, 'settings'=>['ai_api_url'=>getSetting('ai_api_url'), 'ai_api_key'=>getSetting('ai_api_key'), 'ai_model'=>getSetting('ai_model')]]); exit;
    }
    if ($action === 'save_settings') {
        $data = json_decode(file_get_contents('php://input'), true);
        foreach($data as $k=>$v) setSetting($k, $v);
        echo json_encode(['success'=>true]); exit;
    }

    // AI 测试接口
    if ($action === 'test_ai') {
        $data = json_decode(file_get_contents('php://input'), true);
        $url = rtrim($data['ai_api_url'] ?? '', '/');
        $key = $data['ai_api_key'] ?? '';
        $model = $data['ai_model'] ?: 'gpt-4o';
        if(!$url || !$key) { echo json_encode(['success'=>false, 'message'=>'请填写 URL 和 Key']); exit; }

        $ch = curl_init($url . "/chat/completions");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["model"=>$model, "messages"=>[["role"=>"user", "content"=>"hi"]], "max_tokens"=>5]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer $key"]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if($code === 200) echo json_encode(['success'=>true, 'message'=>'✅ AI 连接成功！']);
        else echo json_encode(['success'=>false, 'message'=>'❌ 连接失败 (HTTP '.$code.')']);
        exit;
    }

    // 5. 强制修复升级 (带 GitHub -> 本地 自动回退)
    if ($action === 'force_repair') {
        $files = ['index.php', 'db.php', 'install.php', 'admin.php', 'VERSION.txt'];
        foreach ($files as $f) {
            $ctx = stream_context_create(['http'=>['timeout'=>10]]);
            $c = @file_get_contents(UPDATE_URL . $f, false, $ctx);
            if (!$c) $c = @file_get_contents(FALLBACK_URL . $f); // 3秒超时后自动切换到本地服务器
            if ($c) @file_put_contents(__DIR__ . '/' . $f, $c);
        }
        echo json_encode(['success'=>true, 'message'=>'系统文件已强制修复']); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台 - 保质期管理系统</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root { --primary-color: #667eea; }
        body { background: #f4f7f6; font-family: sans-serif; }
        .sidebar { background: #fff; min-height: 100vh; border-right: 1px solid #eee; }
        .nav-link { color: #555; padding: 12px 20px; border-radius: 0; }
        .nav-link.active { background: #f8f9fa; color: var(--primary-color); border-right: 3px solid var(--primary-color); font-weight: 600; }
        .admin-card { background: white; border-radius: 12px; border: none; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-2 sidebar p-0 pt-3 sticky-top">
                <div class="px-3 mb-4"><h5 class="fw-bold text-primary">管理中心</h5></div>
                <div class="nav flex-column nav-pills" id="adminTabs">
                    <button class="nav-link active text-start" data-bs-toggle="pill" data-bs-target="#tab-products"><i class="bi bi-box me-2"></i>商品管理</button>
                    <button class="nav-link text-start" data-bs-toggle="pill" data-bs-target="#tab-cats"><i class="bi bi-grid me-2"></i>分类规则</button>
                    <button class="nav-link text-start" data-bs-toggle="pill" data-bs-target="#tab-users"><i class="bi bi-people me-2"></i>用户管理</button>
                    <button class="nav-link text-start" data-bs-toggle="pill" data-bs-target="#tab-ai"><i class="bi bi-robot me-2"></i>AI 配置</button>
                    <button class="nav-link text-start" data-bs-toggle="pill" data-bs-target="#tab-system"><i class="bi bi-tools me-2"></i>系统维护</button>
                    <hr><a href="index.php" class="nav-link text-start"><i class="bi bi-arrow-left me-2"></i>返回前台</a>
                </div>
            </div>
            <div class="col-md-10 p-4">
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tab-products">
                        <div class="d-flex justify-content-between mb-4"><h4>商品资料管理</h4></div>
                        <div class="admin-card p-3"><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>SKU/名称</th><th>分类</th><th>周期</th><th>操作</th></tr></thead><tbody id="pListBody"></tbody></table></div></div>
                    </div>
                    <div class="tab-pane fade" id="tab-cats">
                        <div class="d-flex justify-content-between mb-4"><h4>分类规则引擎</h4></div>
                        <div class="row">
                            <div class="col-md-7"><div class="admin-card p-3" id="catListContainer"></div></div>
                            <div class="col-md-5"><div class="admin-card p-3"><h5>新增分类</h5><form id="catForm"><input type="text" id="catName" class="form-control mb-2" placeholder="分类名" required><select id="catType" class="form-select mb-2"><option value="snack">小食品</option><option value="material">物料</option><option value="coffee">咖啡豆</option></select><button class="btn btn-primary w-100">保存规则</button></form></div></div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tab-users">
                        <div class="d-flex justify-content-between mb-4"><h4>管理员账号</h4></div>
                        <div class="admin-card p-3"><table class="table"><thead><tr><th>用户名</th><th>创建时间</th></tr></thead><tbody id="uListBody"></tbody></table><hr><h5>添加账号</h5><form id="addUserForm" class="row g-2"><div class="col-5"><input type="text" id="nU" class="form-control" placeholder="用户名"></div><div class="col-5"><input type="password" id="nP" class="form-control" placeholder="密码"></div><div class="col-2"><button class="btn btn-success w-100">添加</button></div></form></div>
                    </div>
                    <div class="tab-pane fade" id="tab-ai">
                        <div class="d-flex justify-content-between mb-4"><h4>AI 接口设置</h4></div>
                        <div class="admin-card p-4 mx-auto" style="max-width: 600px;"><form id="aiForm"><div class="mb-3"><label class="form-label">API URL</label><input type="text" id="ai_url" class="form-control" placeholder="https://api.openai.com/v1"></div><div class="mb-3"><label class="form-label">API Key</label><input type="password" id="ai_key" class="form-control"></div><div class="mb-3"><label class="form-label">Model</label><input type="text" id="ai_model" class="form-control" placeholder="gpt-4o"></div><div class="d-flex gap-2"><button class="btn btn-primary flex-grow-1">保存设置</button><button type="button" id="testAi" class="btn btn-outline-info" style="min-width: 150px;">测试连接</button></div></form></div>
                    </div>
                    <div class="tab-pane fade" id="tab-system">
                        <div class="d-flex justify-content-between mb-4"><h4>系统维护</h4></div>
                        <div class="admin-card p-4 text-center">
                            <h5 class="mb-3">全自动智能升级</h5>
                            <p class="text-muted small">系统将优先从 GitHub 拉取，若 10 秒内无响应将自动通过 Jarvis 节点完成强制修复。</p>
                            <button id="forceUpdateBtn" class="btn btn-danger px-5 py-2 fw-bold">立即执行系统修复/升级</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            loadProducts(); loadCats(); loadUsers(); loadSettings();
            document.getElementById('catForm').addEventListener('submit', async (e)=>{
                e.preventDefault(); const rule = JSON.stringify({need_buffer: true, scrap_on_removal: true});
                await fetch('admin.php?api=save_category', {method:'POST', body:JSON.stringify({name:document.getElementById('catName').value, type:document.getElementById('catType').value, rule})});
                loadCats(); e.target.reset();
            });
            document.getElementById('aiForm').addEventListener('submit', async (e)=>{
                e.preventDefault(); await fetch('admin.php?api=save_settings', {method:'POST', body:JSON.stringify({ai_api_url:document.getElementById('ai_url').value, ai_api_key:document.getElementById('ai_key').value, ai_model:document.getElementById('ai_model').value})});
                alert('设置已保存');
            });
            document.getElementById('testAi').addEventListener('click', async ()=>{
                const btn = document.getElementById('testAi'); const originalText = btn.innerText; let timeLeft = 50;
                btn.disabled = true; btn.innerText = `测试中... (${timeLeft}s)`;
                const timer = setInterval(() => { timeLeft--; btn.innerText = `测试中... (${timeLeft}s)`; if (timeLeft <= 0) clearInterval(timer); }, 1000);
                try {
                    const res = await fetch('admin.php?api=test_ai', { method: 'POST', body: JSON.stringify({ ai_api_url: document.getElementById('ai_url').value, ai_api_key: document.getElementById('ai_key').value, ai_model: document.getElementById('ai_model').value }) });
                    const d = await res.json(); clearInterval(timer); btn.innerText = originalText; btn.disabled = false; alert(d.message);
                } catch (e) { clearInterval(timer); btn.innerText = originalText; btn.disabled = false; alert('测试失败'); }
            });
            document.getElementById('forceUpdateBtn').addEventListener('click', async ()=>{
                if(!confirm('确定强制升级吗？')) return;
                const btn = document.getElementById('forceUpdateBtn'); btn.disabled = true; btn.innerText = '升级中...请勿关闭页面';
                const res = await fetch('admin.php?api=force_repair');
                if((await res.json()).success) { alert('升级修复成功！'); location.reload(); }
            });
        });
        async function loadProducts() {
            const res = await fetch('admin.php?api=get_all_products'); const d = await res.json();
            document.getElementById('pListBody').innerHTML = d.data.map(p => `<tr><td><b>${p.name}</b><br><small>${p.sku}</small></td><td>${p.category_name||'-'}</td><td>${p.inventory_cycle}</td><td><button class="btn btn-sm btn-link" onclick="editP(${p.id},${p.category_id},'${p.inventory_cycle}')">编辑</button></td></tr>`).join('');
        }
        function editP(id, cid, cycle) {
            const newCid = prompt("分类ID (1:小食品, 2:物料, 3:咖啡豆):", cid);
            const newCycle = prompt("周期 (daily/weekly/monthly/yearly/none):", cycle);
            if(newCid!==null && newCycle!==null) fetch('admin.php?api=update_product_meta',{method:'POST', body:JSON.stringify({id, category_id:newCid, inventory_cycle:newCycle})}).then(()=>loadProducts());
        }
        async function loadCats() {
            const res = await fetch('admin.php?api=get_categories'); const d = await res.json();
            document.getElementById('catListContainer').innerHTML = d.categories.map(c => `<div class="list-group-item d-flex justify-content-between">${c.name} <span class="badge bg-secondary">${c.type}</span></div>`).join('');
        }
        async function loadUsers() {
            const res = await fetch('admin.php?api=get_users'); const d = await res.json();
            document.getElementById('uListBody').innerHTML = d.users.map(u => `<tr><td>${u.username}</td><td>${u.created_at}</td></tr>`).join('');
        }
        async function loadSettings() {
            const res = await fetch('admin.php?api=get_settings'); const d = await res.json();
            if(d.success) { document.getElementById('ai_url').value=d.settings.ai_api_url; document.getElementById('ai_key').value=d.settings.ai_api_key; document.getElementById('ai_model').value=d.settings.ai_model; }
        }
    </script>
</body>
</html>
