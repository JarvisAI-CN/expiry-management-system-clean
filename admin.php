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

// 字符编码转换函数（GBK/GB2312 -> UTF-8）
function convertToUtf8($str) {
    if (!$str) return $str;
    
    // 检测编码
    $encoding = mb_detect_encoding($str, ['UTF-8', 'GBK', 'GB2312', 'ASCII'], true);
    
    // 如果不是UTF-8，转换为UTF-8
    if ($encoding && $encoding !== 'UTF-8') {
        $str = mb_convert_encoding($str, 'UTF-8', $encoding);
    }
    
    return $str;
}

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

// 处理传统表单提交（上传SKU文件）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_sku') {
    $conn = getDBConnection();
    
    if (!isset($_FILES['sku_file'])) {
        $error = json_encode(['success'=>false, 'message'=>'未选择文件']);
        header("Location: admin.php?page=sku&upload_result=" . urlencode($error));
        exit;
    }

    $file = $_FILES['sku_file'];
    
    // 详细的错误信息
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => '文件超过php.ini设置的大小',
            UPLOAD_ERR_FORM_SIZE => '文件超过表单设置的大小',
            UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
            UPLOAD_ERR_NO_FILE => '没有文件被上传',
            UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
            UPLOAD_ERR_CANT_WRITE => '文件写入失败',
            UPLOAD_ERR_EXTENSION => 'PHP扩展停止了文件上传',
        ];
        $errorMsg = $errorMessages[$file['error']] ?? "未知错误(错误代码:{$file['error']})";
        $error = json_encode(['success'=>false, 'message'=>"上传失败: $errorMsg"]);
        header("Location: admin.php?page=sku&upload_result=" . urlencode($error));
        exit;
    }

    // 保存文件
    $filename = 'sku_upload_' . time() . '_' . basename($file['name']);
    $filepath = __DIR__ . '/uploads/' . $filename;
    if (!is_dir(__DIR__ . '/uploads')) {
        mkdir(__DIR__ . '/uploads', 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        $error = json_encode(['success'=>false, 'message'=>'文件保存失败，请检查uploads目录权限']);
        header("Location: admin.php?page=sku&upload_result=" . urlencode($error));
        exit;
    }

    // 同步处理文件（立即解析，不使用exec）
    try {
        // 根据文件扩展名选择解析方式
        $fileExt = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

        if ($fileExt === 'csv') {
            // 解析CSV文件
            $handle = fopen($filepath, 'r');
            if (!$handle) {
                throw new Exception("无法打开文件");
            }

            $uploaded_skus = [];
            $row_count = 0;

            while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                $row_count++;

                if (empty($data[0])) continue;

                if ($row_count === 1 && !preg_match('/^\d+$/', $data[0])) {
                    continue;
                }

                $sku = trim($data[0]);
                $name = trim($data[1] ?? '');
                $category_name = trim($data[2] ?? ''); // 第三列：分类

                // 字符编码转换（GBK -> UTF-8）
                $sku = convertToUtf8($sku);
                $name = convertToUtf8($name);
                $category_name = convertToUtf8($category_name);

                if ($sku) {
                    $uploaded_skus[$sku] = [
                        'name' => $name,
                        'category_name' => $category_name
                    ];
                }
            }

            fclose($handle);

        } elseif ($fileExt === 'xlsx' || $fileExt === 'xls') {
            // 解析Excel文件（需要扩展）
            if (!class_exists('ZipArchive')) {
                throw new Exception("Excel解析需要PHP扩展（zip），请联系管理员安装");
            }

            require_once __DIR__ . '/xlsx_parser.php';
            $rows = parseXlsxFile($filepath);

            $uploaded_skus = [];
            $row_count = 0;

            foreach ($rows as $rowData) {
                $row_count++;

                if (empty($rowData[0])) continue;

                if ($row_count === 1 && !preg_match('/^\d+$/', $rowData[0])) {
                    continue;
                }

                $sku = trim($rowData[0]);
                $name = trim($rowData[1] ?? '');
                $category_name = trim($rowData[2] ?? ''); // 第三列：分类

                // 字符编码转换（GBK -> UTF-8）
                $sku = convertToUtf8($sku);
                $name = convertToUtf8($name);
                $category_name = convertToUtf8($category_name);

                if ($sku) {
                    $uploaded_skus[$sku] = [
                        'name' => $name,
                        'category_name' => $category_name
                    ];
                }
            }

        } else {
            throw new Exception("不支持的文件格式：$fileExt");
        }

        // 对比数据库
        $new_skus = [];
        $missing_skus = [];
        $duplicate_skus = [];

        // 检查新增和重复SKU
        $checkStmt = $conn->prepare("SELECT sku FROM products WHERE sku = ?");
        foreach ($uploaded_skus as $sku => $data) {
            $checkStmt->bind_param("s", $sku);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->num_rows > 0;

            if (!$exists) {
                $new_skus[] = [
                    'sku' => $sku,
                    'name' => $data['name'],
                    'category_name' => $data['category_name']
                ];
            }
        }

        // 检查缺失SKU（数据库中有但文件中没有）
        $allDbRes = $conn->query("SELECT sku, name FROM products");
        $allDbSkus = [];
        while ($row = $allDbRes->fetch_assoc()) {
            $allDbSkus[$row['sku']] = $row['name'];
        }

        foreach ($allDbSkus as $sku => $name) {
            if (!isset($uploaded_skus[$sku])) {
                $missing_skus[] = ['sku' => $sku, 'name' => $name, 'category_name' => ''];
            }
        }

        // 检查并添加category_name字段
        $checkColumn = $conn->query("SHOW COLUMNS FROM sku_todos LIKE 'category_name'");
        if ($checkColumn->num_rows == 0) {
            $conn->query("ALTER TABLE sku_todos ADD COLUMN category_name VARCHAR(100) DEFAULT '' AFTER name");
        }

        // 保存到sku_todos表
        $clearStmt = $conn->prepare("DELETE FROM sku_todos WHERE source_file = ?");
        $clearStmt->bind_param("s", $filename);
        $clearStmt->execute();

        $insertStmt = $conn->prepare("INSERT INTO sku_todos (sku, name, category_name, status, source_file) VALUES (?, ?, ?, 'pending', ?)");
        foreach ($new_skus as $item) {
            $insertStmt->bind_param("ssss", $item['sku'], $item['name'], $item['category_name'], $filename);
            $insertStmt->execute();
        }
        foreach ($missing_skus as $item) {
            $insertStmt->bind_param("ssss", $item['sku'], $item['name'], $item['category_name'], $filename);
            $insertStmt->execute();
        }

        // 更新任务状态
        $resultData = json_encode([
            'total_rows' => $row_count,
            'new_skus' => count($new_skus),
            'missing_skus' => count($missing_skus),
            'duplicate_skus' => count($duplicate_skus)
        ]);

        $updateStmt = $conn->prepare("UPDATE sku_upload_tasks SET status = 'completed', total_rows = ?, new_skus = ?, missing_skus = ?, duplicate_skus = ?, result_data = ? WHERE id = ?");
        $newCount = count($new_skus);
        $missingCount = count($missing_skus);
        $dupCount = count($duplicate_skus);
        $updateStmt->bind_param("iiiisi", $row_count, $newCount, $missingCount, $dupCount, $resultData, $task_id);
        $updateStmt->execute();

        $successResult = json_encode([
            'success'=>true,
            'message'=>"✅ 处理完成！新增{$newCount}个，缺失{$missingCount}个",
            'filename'=>$filename,
            'task'=>['filename'=>$filename, 'result_data'=>$resultData]
        ]);
        header("Location: admin.php?page=sku&upload_result=" . urlencode($successResult));
        exit;

    } catch (Exception $e) {
        $error = json_encode(['success'=>false, 'message'=>"处理失败: " . $e->getMessage()]);
        header("Location: admin.php?page=sku&upload_result=" . urlencode($error));
        exit;
    }
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

    // 5. SKU维护相关API
    if ($action === 'upload_sku_csv') {
        if (!isset($_FILES['csv_file'])) {
            echo json_encode(['success'=>false, 'message'=>'未选择文件']); exit;
        }

        $file = $_FILES['csv_file'];
        
        // 详细的错误信息
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => '文件超过php.ini设置的大小',
                UPLOAD_ERR_FORM_SIZE => '文件超过表单设置的大小',
                UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                UPLOAD_ERR_NO_FILE => '没有文件被上传',
                UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
                UPLOAD_ERR_CANT_WRITE => '文件写入失败',
                UPLOAD_ERR_EXTENSION => 'PHP扩展停止了文件上传',
            ];
            $errorMsg = $errorMessages[$file['error']] ?? "未知错误(错误代码:{$file['error']})";
            echo json_encode(['success'=>false, 'message'=>"上传失败: $errorMsg"]); exit;
        }

        // 保存文件
        $filename = 'sku_upload_' . time() . '_' . basename($file['name']);
        $filepath = __DIR__ . '/uploads/' . $filename;
        if (!is_dir(__DIR__ . '/uploads')) {
            mkdir(__DIR__ . '/uploads', 0755, true);
        }

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            echo json_encode(['success'=>false, 'message'=>'文件保存失败，请检查uploads目录权限']); exit;
        }

        // 创建上传任务记录
        $stmt = $conn->prepare("INSERT INTO sku_upload_tasks (filename, status) VALUES (?, 'pending')");
        $stmt->bind_param("s", $filename);
        $stmt->execute();
        $task_id = $conn->insert_id;

        // 触发异步处理
        $php_path = exec('which php8.3');
        $script_path = __DIR__ . '/process_sku_upload.php';
        exec("$php_path $script_path $task_id > /dev/null 2>&1 &");

        echo json_encode(['success'=>true, 'task_id'=>$task_id, 'message'=>'文件上传成功，正在后台处理...']);
        exit;
    }

    if ($action === 'get_upload_tasks') {
        $res = $conn->query("SELECT * FROM sku_upload_tasks ORDER BY created_at DESC LIMIT 20");
        $list = [];
        while ($r = $res->fetch_assoc()) {
            $list[] = $r;
        }
        echo json_encode(['success'=>true, 'tasks'=>$list]);
        exit;
    }

    if ($action === 'get_task_result') {
        $task_id = intval($_GET['task_id']);
        $stmt = $conn->prepare("SELECT * FROM sku_upload_tasks WHERE id = ?");
        $stmt->bind_param("i", $task_id);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc();

        if (!$task) {
            echo json_encode(['success'=>false, 'message'=>'任务不存在']); exit;
        }

        $result = json_decode($task['result_data'] ?: '{}', true);
        echo json_encode(['success'=>true, 'task'=>$task, 'result'=>$result]);
        exit;
    }

    if ($action === 'get_sku_todos') {
        $page = intval($_GET['page'] ?? 1);
        $limit = 20;
        $offset = ($page - 1) * $limit;

        // 搜索条件
        $where = "1=1";
        $params = [];
        if (!empty($_GET['search'])) {
            $search = '%' . $_GET['search'] . '%';
            $where .= " AND (sku LIKE ? OR name LIKE ?)";
            $params[] = $search;
            $params[] = $search;
        }

        // 按分类筛选
        if (!empty($_GET['category_filter'])) {
            $category_filter = $_GET['category_filter'];
            if ($category_filter === 'none') {
                $where .= " AND (category_name IS NULL OR category_name = '')";
            } else {
                $where .= " AND category_name = ?";
                $params[] = $category_filter;
            }
        }

        // 获取总数
        $count_sql = "SELECT COUNT(*) as total FROM sku_todos WHERE $where";
        if (!empty($params)) {
            $stmt = $conn->prepare($count_sql);
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $total = $stmt->get_result()->fetch_assoc()['total'];
        } else {
            $total = $conn->query($count_sql)->fetch_assoc()['total'];
        }

        // 获取数据
        $sql = "SELECT st.*, c.name as category_name FROM sku_todos st LEFT JOIN categories c ON st.category_id = c.id WHERE $where ORDER BY st.created_at DESC LIMIT $limit OFFSET $offset";
        $list = [];
        if (!empty($params)) {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $conn->query($sql);
        }
        while ($r = $res->fetch_assoc()) {
            $list[] = $r;
        }

        echo json_encode([
            'success'=>true,
            'data'=>$list,
            'total'=>$total,
            'page'=>$page,
            'pages'=>ceil($total/$limit)
        ]);
        exit;
    }

    if ($action === 'get_upload_categories') {
        // 获取上传文件中的所有分类
        $res = $conn->query("SELECT DISTINCT category_name FROM sku_todos WHERE category_name IS NOT NULL AND category_name != '' ORDER BY category_name");
        $categories = [];
        while ($row = $res->fetch_assoc()) {
            $categories[] = $row['category_name'];
        }
        echo json_encode(['success'=>true, 'categories'=>$categories]);
        exit;
    }

    if ($action === 'update_sku_todo') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = intval($data['id']);
        $category_id = empty($data['category_id']) ? null : intval($data['category_id']);
        $inventory_cycle = $data['inventory_cycle'] ?? 'none';

        $stmt = $conn->prepare("UPDATE sku_todos SET category_id = ?, inventory_cycle = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("isi", $category_id, $inventory_cycle, $id);
        echo json_encode(['success'=>$stmt->execute()]);
        exit;
    }

    if ($action === 'batch_update_sku_todos') {
        $data = json_decode(file_get_contents('php://input'), true);
        $ids = $data['ids'] ?? [];
        $category_id = empty($data['category_id']) ? null : intval($data['category_id']);
        $inventory_cycle = $data['inventory_cycle'] ?? null;

        if (empty($ids)) {
            echo json_encode(['success'=>false, 'message'=>'未选择任何SKU']); exit;
        }

        $updates = [];
        $params = [];
        $types = '';

        if ($category_id !== null) {
            $updates[] = "category_id = ?";
            $params[] = $category_id;
            $types .= 'i';
        }
        if ($inventory_cycle !== null) {
            $updates[] = "inventory_cycle = ?";
            $params[] = $inventory_cycle;
            $types .= 's';
        }

        if (empty($updates)) {
            echo json_encode(['success'=>false, 'message'=>'未设置任何更新']); exit;
        }

        $sql = "UPDATE sku_todos SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
        $params = array_merge($params, $ids);
        $types .= str_repeat('i', count($ids));

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        echo json_encode(['success'=>$stmt->execute(), 'affected'=>$stmt->affected_rows]);
        exit;
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
                    <button class="nav-link text-start" data-bs-toggle="pill" data-bs-target="#tab-sku"><i class="bi bi-upc-scan me-2"></i>SKU维护</button>
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
                    <div class="tab-pane fade" id="tab-sku">
                        <div class="d-flex justify-content-between mb-4"><h4>SKU维护</h4></div>

                        <!-- 上传区域 -->
                        <div class="admin-card p-4 mb-4">
                            <h5 class="mb-3">📤 上传SKU清单（支持Excel/CSV）</h5>
                            <p class="text-muted small mb-3">
                                格式：两列（SKU, 商品名），支持 .xlsx、.xls、.csv 格式。<br>
                                系统将自动对比数据库，识别新增/缺失/重复的SKU。
                            </p>
                            
                            <?php if (isset($_GET['upload_result'])): ?>
                                <?php 
                                $result = json_decode($_GET['upload_result'], true);
                                if ($result && $result['success']): 
                                    $task = $result['task'];
                                ?>
                                <div class="alert alert-success">
                                    <h6>✅ 上传成功！</h6>
                                    <p>文件：<strong><?php echo htmlspecialchars($task['filename']); ?></strong></p>
                                    <p>正在后台处理中，请稍候刷新页面查看结果...</p>
                                    <a href="admin.php?page=sku" class="btn btn-primary btn-sm">刷新页面</a>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-danger">
                                    ❌ <?php echo isset($result) ? $result['message'] : '上传失败'; ?>
                                </div>
                            <?php endif; ?>
                            <?php endif; ?>
                            
                            <form method="POST" action="admin.php?page=sku" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="upload_sku">
                                <div class="row g-2">
                                    <div class="col-8">
                                        <input type="file" name="sku_file" accept=".csv,.xlsx,.xls" class="form-control" required>
                                    </div>
                                    <div class="col-4">
                                        <button type="submit" class="btn btn-primary w-100">开始上传</button>
                                    </div>
                                </div>
                            </form>
                            <div id="uploadStatus" class="mt-3" style="display:none;">
                                <div class="alert alert-info">
                                    <i class="bi bi-hourglass-split me-2"></i>正在处理中，请稍候...
                                </div>
                            </div>
                            <div id="uploadResult" class="mt-3"></div>
                        </div>

                        <!-- 任务历史 -->
                        <div class="admin-card p-4 mb-4">
                            <h5 class="mb-3">📋 上传历史</h5>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead><tr><th>时间</th><th>文件名</th><th>状态</th><th>新增/缺失/重复</th><th>操作</th></tr></thead>
                                    <tbody id="uploadHistoryBody"></tbody>
                                </table>
                            </div>
                        </div>

                        <!-- SKU列表 -->
                        <div class="admin-card p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">📦 SKU列表</h5>
                                <div>
                                    <button id="batchSetBtn" class="btn btn-sm btn-outline-primary me-2">批量设置</button>
                                    <button id="exportSkuBtn" class="btn btn-sm btn-outline-secondary">导出CSV</button>
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-4">
                                    <select id="batchCategory" class="form-select form-select-sm">
                                        <option value="">批量设置分类...</option>
                                    </select>
                                </div>
                                <div class="col-4">
                                    <select id="batchCycle" class="form-select form-select-sm">
                                        <option value="">批量设置频次...</option>
                                        <option value="weekly">每周</option>
                                        <option value="monthly">每月</option>
                                        <option value="quarterly">每季</option>
                                        <option value="yearly">每年</option>
                                        <option value="none">不盘点</option>
                                    </select>
                                </div>
                                <div class="col-4">
                                    <button id="applyBatchBtn" class="btn btn-sm btn-success w-100">应用批量设置</button>
                                </div>
                            </div>
                            
                            <!-- 搜索和筛选 -->
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <input type="text" id="skuSearchInput" class="form-control form-control-sm" placeholder="搜索SKU或商品名...">
                                </div>
                                <div class="col-6">
                                    <select id="categoryFilter" class="form-select form-select-sm">
                                        <option value="">所有分类</option>
                                        <option value="none">未分类</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead><tr><th><input type="checkbox" id="selectAllSku"></th><th>SKU</th><th>商品名</th><th>分类</th><th>盘点频次</th><th>状态</th><th>操作</th></tr></thead>
                                    <tbody id="skuListBody"></tbody>
                                </table>
                            </div>
                            <nav><ul class="pagination justify-content-center mt-3" id="skuPagination"></ul></nav>
                        </div>
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

        // SKU维护相关函数
        let skuPollTimer = null;

        // 轮询任务状态（检查后台处理进度）
        async function checkTaskProgress() {
            try {
                const res = await fetch('admin.php?api=get_upload_tasks');
                const d = await res.json();

                if (d.success && d.tasks.length > 0) {
                    const latestTask = d.tasks[0];
                    if (latestTask.status === 'completed' && document.getElementById('uploadResult').innerHTML === '') {
                        showTaskResult(latestTask, JSON.parse(latestTask.result_data || '{}'));
                        loadUploadHistory();
                        loadSkuTodos();
                    }
                }
            } catch (e) {
                console.error('Check task error:', e);
            }
        }

        // 页面加载时开始轮询任务进度
        if (document.querySelector('[data-bs-target="#tab-sku"]')) {
            setInterval(checkTaskProgress, 3000);
        }

        // 显示任务结果
        function showTaskResult(task, result) {
            const statusDiv = document.getElementById('uploadStatus');
            const resultDiv = document.getElementById('uploadResult');

            statusDiv.style.display = 'none';

            let html = '<div class="alert alert-success">';
            html += `<h6>✅ 处理完成！</h6>`;
            html += '<ul class="mb-0">';
            html += `<li>新增SKU：<strong class="text-success">${task.new_skus}</strong> 个</li>`;
            html += `<li>缺失SKU：<strong class="text-danger">${task.missing_skus}</strong> 个</li>`;
            html += `<li>重复SKU：<strong class="text-muted">${task.duplicate_skus}</strong> 个</li>`;
            html += '</ul>';

            if (result && result.missing_skus && Object.keys(result.missing_skus).length > 0) {
                html += '<div class="mt-3"><strong>缺失SKU列表（前10个）：</strong>';
                html += '<ul class="small">';
                let count = 0;
                for (let [sku, name] of Object.entries(result.missing_skus)) {
                    if (count++ >= 10) break;
                    html += `<li>${sku} - ${name}</li>`;
                }
                if (Object.keys(result.missing_skus).length > 10) {
                    html += `<li>... 还有 ${Object.keys(result.missing_skus).length - 10} 个</li>`;
                }
                html += '</ul></div>';
            }

            html += '</div>';
            resultDiv.innerHTML = html;
        }

        // 加载上传历史
        async function loadUploadHistory() {
            try {
                const res = await fetch('admin.php?api=get_upload_tasks');
                const d = await res.json();

                if (d.success) {
                    const tbody = document.getElementById('uploadHistoryBody');
                    tbody.innerHTML = d.tasks.map(t => {
                        let statusBadge = '';
                        switch(t.status) {
                            case 'completed': statusBadge = '<span class="badge bg-success">完成</span>'; break;
                            case 'processing': statusBadge = '<span class="badge bg-warning">处理中</span>'; break;
                            case 'failed': statusBadge = '<span class="badge bg-danger">失败</span>'; break;
                            default: statusBadge = '<span class="badge bg-secondary">等待</span>';
                        }
                        return `<tr>
                            <td>${t.created_at}</td>
                            <td>${t.filename}</td>
                            <td>${statusBadge}</td>
                            <td>${t.new_skus}/${t.missing_skus}/${t.duplicate_skus}</td>
                            <td>${t.status === 'completed' ? '<button class="btn btn-sm btn-link" onclick="alert(\'详情功能开发中\')">查看</button>' : '-'}</td>
                        </tr>`;
                    }).join('');
                }
            } catch (e) {
                console.error('Load history error:', e);
            }
        }

        // 加载SKU列表
        async function loadSkuTodos(page = 1) {
            try {
                const search = document.getElementById('skuSearchInput')?.value || '';
                const categoryFilter = document.getElementById('categoryFilter')?.value || '';
                let url = `admin.php?api=get_sku_todos&page=${page}`;
                if (search) url += `&search=${encodeURIComponent(search)}`;
                if (categoryFilter) url += `&category_filter=${encodeURIComponent(categoryFilter)}`;

                const res = await fetch(url);
                const d = await res.json();

                if (d.success) {
                    const tbody = document.getElementById('skuListBody');
                    tbody.innerHTML = d.data.map(item => {
                        const categorySelect = document.getElementById('batchCategory')?.innerHTML ||
                            '<option value="">无分类</option>';
                        return `<tr>
                            <td><input type="checkbox" class="sku-checkbox" data-id="${item.id}"></td>
                            <td><code>${item.sku}</code></td>
                            <td>${item.name}</td>
                            <td>
                                <small class="text-muted">${item.category_name || '-'}</small>
                            </td>
                            <td><select class="form-select form-select-sm" onchange="updateSkuTodo(${item.id}, 'cycle', this.value)">
                                <option value="weekly" ${item.inventory_cycle === 'weekly' ? 'selected' : ''}>每周</option>
                                <option value="monthly" ${item.inventory_cycle === 'monthly' ? 'selected' : ''}>每月</option>
                                <option value="quarterly" ${item.inventory_cycle === 'quarterly' ? 'selected' : ''}>每季</option>
                                <option value="yearly" ${item.inventory_cycle === 'yearly' ? 'selected' : ''}>每年</option>
                                <option value="none" ${item.inventory_cycle === 'none' ? 'selected' : ''}>不盘点</option>
                            </select></td>
                            <td>${item.status === 'done' ? '<span class="badge bg-success">已完成</span>' : '<span class="badge bg-warning">待办</span>'}</td>
                            <td><button class="btn btn-sm btn-outline-danger" onclick="deleteSkuTodo(${item.id})">删除</button></td>
                        </tr>`;
                    }).join('');

                    // 更新分页
                    const pagination = document.getElementById('skuPagination');
                    let pagesHtml = '';
                    for (let i = 1; i <= d.pages; i++) {
                        pagesHtml += `<li class="page-item ${i === d.page ? 'active' : ''}"><a class="page-link" href="#" onclick="loadSkuTodos(${i}); return false;">${i}</a></li>`;
                    }
                    pagination.innerHTML = pagesHtml;
                }
            } catch (e) {
                console.error('Load SKU list error:', e);
            }
        }

        // 更新单个SKU
        async function updateSkuTodo(id, field, value) {
            const data = { id };
            if (field === 'category') {
                data.category_id = value;
            } else if (field === 'cycle') {
                data.inventory_cycle = value;
            }

            try {
                await fetch('admin.php?api=update_sku_todo', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
            } catch (e) {
                console.error('Update error:', e);
            }
        }

        // 批量应用设置
        document.getElementById('applyBatchBtn')?.addEventListener('click', async () => {
            const checkboxes = document.querySelectorAll('.sku-checkbox:checked');
            if (!checkboxes.length) {
                alert('请选择要批量设置的SKU');
                return;
            }

            const ids = Array.from(checkboxes).map(cb => cb.dataset.id);
            const category_id = document.getElementById('batchCategory').value || null;
            const inventory_cycle = document.getElementById('batchCycle').value || null;

            if (!category_id && !inventory_cycle) {
                alert('请至少选择一个批量设置项');
                return;
            }

            try {
                const res = await fetch('admin.php?api=batch_update_sku_todos', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids, category_id, inventory_cycle })
                });
                const d = await res.json();

                if (d.success) {
                    alert(`已更新 ${d.affected} 个SKU`);
                    loadSkuTodos();
                } else {
                    alert(d.message);
                }
            } catch (e) {
                alert('批量更新失败');
            }
        });

        // 全选/取消全选
        document.getElementById('selectAllSku')?.addEventListener('change', (e) => {
            document.querySelectorAll('.sku-checkbox').forEach(cb => {
                cb.checked = e.target.checked;
            });
        });

        // 切换到SKU维护标签时加载分类选项
        document.querySelector('[data-bs-target="#tab-sku"]')?.addEventListener('click', () => {
            loadCategoriesToSelect();
            loadUploadCategories();
            loadSkuTodos();
            loadUploadHistory();
        });

        // 搜索和筛选监听器
        let searchTimer = null;
        document.getElementById('skuSearchInput')?.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => loadSkuTodos(1), 300);
        });
        
        document.getElementById('categoryFilter')?.addEventListener('change', () => {
            loadSkuTodos(1);
        });

        // 加载分类到下拉框
        async function loadCategoriesToSelect() {
            try {
                const res = await fetch('admin.php?api=get_categories');
                const d = await res.json();

                if (d.success) {
                    const options = '<option value="">无分类</option>' +
                        d.categories.map(c => `<option value="${c.id}">${c.name}</option>`).join('');

                    document.getElementById('batchCategory').innerHTML = '<option value="">批量设置分类...</option>' +
                        d.categories.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
                }
            } catch (e) {
                console.error('Load categories error:', e);
            }
        }

        // 加载上传文件中的分类到筛选框
        async function loadUploadCategories() {
            try {
                const res = await fetch('admin.php?api=get_upload_categories');
                const d = await res.json();

                if (d.success && d.categories.length > 0) {
                    const select = document.getElementById('categoryFilter');
                    if (select) {
                        // 保留"所有分类"和"未分类"选项
                        select.innerHTML = '<option value="">所有分类</option><option value="none">未分类</option>';
                        // 添加上传文件中的分类
                        d.categories.forEach(cat => {
                            const option = document.createElement('option');
                            option.value = cat;
                            option.textContent = cat;
                            select.appendChild(option);
                        });
                    }
                }
            } catch (e) {
                console.error('Load upload categories error:', e);
            }
        }
    </script>
</body>
</html>
