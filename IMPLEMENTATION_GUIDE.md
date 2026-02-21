# 编辑盘点单功能 - 实现指南

**角色**: TDD Developer 参考文档
**版本**: v1.0
**日期**: 2026-02-21

---

## 🎯 实现目标

为保质期管理系统添加编辑盘点单功能，允许用户：
1. 修改商品数量和到期日期
2. 删除盘点单中的商品
3. 向已有盘点单添加新商品

---

## 📁 文件修改清单

### 1. index.php

**位置**: `/home/ubuntu/.openclaw/workspace/expiry-clean/index.php`

**新增 API 端点** (在 `checkAuth()` 之后，约 line 110):

```php
// ========================================
// 编辑盘点单功能
// ========================================

if ($action === 'update_inventory_item') {
    $data = json_decode(file_get_contents('php://input'), true);
    $batchId = $data['batch_id'] ?? '';
    $quantity = $data['quantity'] ?? null;
    $expiryDate = $data['expiry_date'] ?? null;
    
    if (empty($batchId)) {
        echo json_encode(['success' => false, 'message' => '缺少batch_id参数']);
        exit;
    }
    
    if ($quantity === null && $expiryDate === null) {
        echo json_encode(['success' => false, 'message' => '至少需要提供quantity或expiry_date']);
        exit;
    }
    
    // 验证数据
    if ($quantity !== null && $quantity < 0) {
        echo json_encode(['success' => false, 'message' => '数量不能为负数']);
        exit;
    }
    
    if ($expiryDate !== null && strtotime($expiryDate) < strtotime(date('Y-m-d'))) {
        echo json_encode(['success' => false, 'message' => '到期日期不能早于今天']);
        exit;
    }
    
    // 检查权限
    $stmt = $conn->prepare("
        SELECT s.user_id, s.session_key 
        FROM inventory_sessions s
        JOIN batches b ON b.session_id = s.session_key
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $batchId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if (!$result) {
        echo json_encode(['success' => false, 'message' => '批次记录不存在']);
        exit;
    }
    
    if ($result['user_id'] != $_SESSION['user_id'] && !isAdmin()) {
        echo json_encode(['success' => false, 'message' => '只能编辑自己创建的盘点单']);
        exit;
    }
    
    // 更新数据
    $stmt = $conn->prepare("
        UPDATE batches 
        SET quantity = COALESCE(?, quantity),
            expiry_date = COALESCE(?, expiry_date),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("isi", $quantity, $expiryDate, $batchId);
    
    if ($stmt->execute()) {
        // 记录操作日志
        addLog('update_inventory_item', "batch_id: $batchId");
        echo json_encode(['success' => true, 'message' => '更新成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '更新失败']);
    }
    exit;
}

if ($action === 'delete_inventory_item') {
    $data = json_decode(file_get_contents('php://input'), true);
    $batchId = $data['batch_id'] ?? '';
    
    if (empty($batchId)) {
        echo json_encode(['success' => false, 'message' => '缺少batch_id参数']);
        exit;
    }
    
    // 检查权限并获取session_key
    $stmt = $conn->prepare("
        SELECT s.user_id, s.session_key, b.session_id
        FROM inventory_sessions s
        JOIN batches b ON b.session_id = s.session_key
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $batchId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if (!$result) {
        echo json_encode(['success' => false, 'message' => '批次记录不存在']);
        exit;
    }
    
    if ($result['user_id'] != $_SESSION['user_id'] && !isAdmin()) {
        echo json_encode(['success' => false, 'message' => '只能删除自己创建的盘点单中的商品']);
        exit;
    }
    
    $sessionKey = $result['session_key'];
    $sessionId = $result['session_id'];
    
    // 开始事务
    $conn->begin_transaction();
    
    try {
        // 删除批次
        $stmt = $conn->prepare("DELETE FROM batches WHERE id = ?");
        $stmt->bind_param("i", $batchId);
        $stmt->execute();
        
        // 更新计数
        $stmt = $conn->prepare("
            UPDATE inventory_sessions 
            SET item_count = (
                SELECT COUNT(*) 
                FROM batches 
                WHERE session_id = ?
            )
            WHERE session_key = ?
        ");
        $stmt->bind_param("ss", $sessionId, $sessionKey);
        $stmt->execute();
        
        $conn->commit();
        
        // 记录操作日志
        addLog('delete_inventory_item', "batch_id: $batchId, session: $sessionKey");
        
        // 获取剩余数量
        $stmt = $conn->prepare("SELECT item_count FROM inventory_sessions WHERE session_key = ?");
        $stmt->bind_param("s", $sessionKey);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['item_count'];
        
        echo json_encode([
            'success' => true,
            'message' => '删除成功',
            'remaining_count' => intval($count)
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => '删除失败: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'add_inventory_item') {
    $data = json_decode(file_get_contents('php://input'), true);
    $sessionKey = $data['session_key'] ?? '';
    $sku = $data['sku'] ?? '';
    $name = $data['name'] ?? '';
    $categoryId = $data['category_id'] ?? 0;
    $removalBuffer = $data['removal_buffer'] ?? 0;
    $batches = $data['batches'] ?? [];
    
    if (empty($sessionKey) || empty($sku) || empty($name) || empty($batches)) {
        echo json_encode(['success' => false, 'message' => '缺少必要参数']);
        exit;
    }
    
    // 验证批次数据
    foreach ($batches as $batch) {
        if (empty($batch['expiry_date']) || !isset($batch['quantity'])) {
            echo json_encode(['success' => false, 'message' => '批次数据不完整']);
            exit;
        }
        if ($batch['quantity'] < 0) {
            echo json_encode(['success' => false, 'message' => '数量不能为负数']);
            exit;
        }
        if (strtotime($batch['expiry_date']) < strtotime(date('Y-m-d'))) {
            echo json_encode(['success' => false, 'message' => '到期日期不能早于今天']);
            exit;
        }
    }
    
    // 检查盘点单是否存在
    $stmt = $conn->prepare("SELECT user_id FROM inventory_sessions WHERE session_key = ?");
    $stmt->bind_param("s", $sessionKey);
    $stmt->execute();
    $sessionResult = $stmt->get_result()->fetch_assoc();
    
    if (!$sessionResult) {
        echo json_encode(['success' => false, 'message' => '盘点单不存在']);
        exit;
    }
    
    // 检查权限
    if ($sessionResult['user_id'] != $_SESSION['user_id'] && !isAdmin()) {
        echo json_encode(['success' => false, 'message' => '只能向自己创建的盘点单添加商品']);
        exit;
    }
    
    // 开始事务
    $conn->begin_transaction();
    
    try {
        // 1. 检查或创建商品
        $stmt = $conn->prepare("
            INSERT INTO products (sku, name, category_id, removal_buffer)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                name = VALUES(name),
                category_id = VALUES(category_id),
                removal_buffer = VALUES(removal_buffer)
        ");
        $stmt->bind_param("ssii", $sku, $name, $categoryId, $removalBuffer);
        $stmt->execute();
        
        // 获取商品ID
        $stmt = $conn->prepare("SELECT id FROM products WHERE sku = ?");
        $stmt->bind_param("s", $sku);
        $stmt->execute();
        $productId = $stmt->get_result()->fetch_assoc()['id'];
        
        // 2. 插入批次
        $batchIds = [];
        $stmt = $conn->prepare("INSERT INTO batches (product_id, expiry_date, quantity, session_id) VALUES (?, ?, ?, ?)");
        
        foreach ($batches as $batch) {
            $stmt->bind_param("isis", $productId, $batch['expiry_date'], $batch['quantity'], $sessionKey);
            $stmt->execute();
            $batchIds[] = $conn->insert_id;
        }
        
        // 3. 更新盘点单计数
        $batchCount = count($batches);
        $stmt = $conn->prepare("
            UPDATE inventory_sessions 
            SET item_count = item_count + ?,
                updated_at = NOW()
            WHERE session_key = ?
        ");
        $stmt->bind_param("is", $batchCount, $sessionKey);
        $stmt->execute();
        
        $conn->commit();
        
        // 记录操作日志
        addLog('add_inventory_item', "session: $sessionKey, sku: $sku, batches: " . count($batches));
        
        // 获取新总数
        $stmt = $conn->prepare("SELECT item_count FROM inventory_sessions WHERE session_key = ?");
        $stmt->bind_param("s", $sessionKey);
        $stmt->execute();
        $newCount = $stmt->get_result()->fetch_assoc()['item_count'];
        
        echo json_encode([
            'success' => true,
            'message' => '添加成功',
            'batch_ids' => $batchIds,
            'new_count' => intval($newCount)
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => '添加失败: ' . $e->getMessage()]);
    }
    exit;
}
```

**修改现有 API** (约 line 217):

```php
// 修改 get_session_details，增加更多字段
if ($action === 'get_session_details') {
    $sid = $_GET['session_id'] ?? '';
    
    if (empty($sid)) {
        echo json_encode(['success' => false, 'message' => '缺少session_id参数']);
        exit;
    }
    
    $stmt = $conn->prepare("
        SELECT 
            b.id as batch_id,
            b.product_id,
            p.sku, 
            p.name, 
            b.expiry_date, 
            b.quantity, 
            p.category_id,
            p.removal_buffer
        FROM batches b 
        JOIN products p ON b.product_id = p.id 
        WHERE b.session_id = ? 
        ORDER BY DATE_SUB(b.expiry_date, INTERVAL p.removal_buffer DAY) ASC
    ");
    $stmt->bind_param("s", $sid); 
    $stmt->execute();
    $res = $stmt->get_result(); 
    $list = []; 
    while($r = $res->fetch_assoc()) {
        $list[] = $r;
    }
    
    echo json_encode([
        'success' => true, 
        'data'=>$list,
        'debug'=>[
            'session_id'=>$sid,
            'count'=>count($list)
        ]
    ]); 
    exit;
}
```

**辅助函数** (添加到文件末尾，在 `?>` 之前):

```php
/**
 * 检查当前用户是否是管理员
 */
function isAdmin() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return $result && $result['is_admin'] == 1;
}
```

### 2. 前端界面修改 (index.php)

**修改 detailModal** (约 line 632):

```html
<div class="modal fade" id="detailModal">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header border-bottom-0">
                <h5 class="fw-bold">盘点单明细 <span class="badge bg-secondary" id="editModeBadge" style="display:none;">编辑模式</span></h5>
                <div>
                    <button class="btn btn-sm btn-outline-primary me-2" id="toggleEditBtn" onclick="toggleEditMode()">
                        <i class="bi bi-pencil me-1"></i>编辑
                    </button>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0">
                <table class="table table-sm small mb-0" id="inventoryTable">
                    <thead>
                        <tr>
                            <th>商品SKU</th>
                            <th>商品名称</th>
                            <th>到期日期</th>
                            <th>数量</th>
                            <th class="action-col" style="display:none;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="inventoryDetailBody"></tbody>
                </table>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button class="btn btn-success btn-sm me-2 action-col" style="display:none;" id="addNewItemBtn" onclick="showAddItemModal()">
                    <i class="bi bi-plus-lg me-1"></i>添加商品
                </button>
                <button class="btn btn-primary btn-sm" id="sendEmailBtn" onclick="sendInventoryEmail()">
                    <i class="bi bi-envelope me-1"></i>发送到邮箱
                </button>
            </div>
        </div>
    </div>
</div>
```

**JavaScript 函数** (添加到 `<script>` 标签内):

```javascript
let isEditMode = false;
let originalData = []; // 保存原始数据用于取消编辑

// 切换编辑模式
function toggleEditMode() {
    isEditMode = !isEditMode;
    const badge = document.getElementById('editModeBadge');
    const btn = document.getElementById('toggleEditBtn');
    const actionCols = document.querySelectorAll('.action-col');
    
    if (isEditMode) {
        badge.style.display = 'inline';
        btn.innerHTML = '<i class="bi bi-x-lg me-1"></i>取消编辑';
        btn.classList.remove('btn-outline-primary');
        btn.classList.add('btn-outline-secondary');
        actionCols.forEach(col => col.style.display = 'table-cell');
        makeTableEditable();
    } else {
        badge.style.display = 'none';
        btn.innerHTML = '<i class="bi bi-pencil me-1"></i>编辑';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-outline-primary');
        actionCols.forEach(col => col.style.display = 'none');
        reloadTableData(); // 重新加载数据
    }
}

// 使表格可编辑
function makeTableEditable() {
    const tbody = document.getElementById('inventoryDetailBody');
    const rows = tbody.querySelectorAll('tr');
    
    rows.forEach((row, index) => {
        const item = currentInventoryData.items[index];
        
        // 到期日期改为输入框
        const dateCell = row.querySelector('td:nth-child(3)');
        dateCell.innerHTML = `<input type="date" class="form-control form-control-sm" value="${item.expiry_date}" data-field="expiry_date">`;
        
        // 数量改为输入框
        const qtyCell = row.querySelector('td:nth-child(4)');
        qtyCell.innerHTML = `<input type="number" class="form-control form-control-sm" value="${item.quantity}" min="0" data-field="quantity">`;
        
        // 操作列
        const actionCell = row.querySelector('td:nth-child(5)');
        actionCell.innerHTML = `
            <button class="btn btn-sm btn-success me-1" onclick="saveItem(${item.batch_id}, this)">
                <i class="bi bi-check-lg"></i>
            </button>
            <button class="btn btn-sm btn-danger" onclick="deleteItem(${item.batch_id}, '${item.name}', this)">
                <i class="bi bi-trash"></i>
            </button>
        `;
    });
}

// 保存单项
async function saveItem(batchId, btn) {
    const row = btn.closest('tr');
    const expiryDate = row.querySelector('input[data-field="expiry_date"]').value;
    const quantity = parseInt(row.querySelector('input[data-field="quantity"]').value);
    
    if (quantity < 0) {
        showAlert('❌ 数量不能为负数', 'danger');
        return;
    }
    
    const today = new Date().toISOString().split('T')[0];
    if (expiryDate < today) {
        showAlert('❌ 到期日期不能早于今天', 'danger');
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    
    try {
        const res = await fetch('index.php?api=update_inventory_item', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                batch_id: batchId,
                quantity: quantity,
                expiry_date: expiryDate
            })
        });
        
        const d = await res.json();
        
        if (d.success) {
            showAlert('✅ 保存成功', 'success');
            // 重新加载数据
            await reloadSessionData();
        } else {
            showAlert('❌ ' + d.message, 'danger');
        }
    } catch (error) {
        console.error('保存失败:', error);
        showAlert('❌ 保存失败，请稍后重试', 'danger');
    } finally {
        btn.disabled = false;
    }
}

// 删除单项
async function deleteItem(batchId, itemName, btn) {
    if (!confirm(`确定要删除 "${itemName}" 吗？`)) {
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    
    try {
        const res = await fetch('index.php?api=delete_inventory_item', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                batch_id: batchId
            })
        });
        
        const d = await res.json();
        
        if (d.success) {
            showAlert(`✅ 删除成功，剩余 ${d.remaining_count} 件商品`, 'success');
            await reloadSessionData();
        } else {
            showAlert('❌ ' + d.message, 'danger');
        }
    } catch (error) {
        console.error('删除失败:', error);
        showAlert('❌ 删除失败，请稍后重试', 'danger');
    } finally {
        btn.disabled = false;
    }
}

// 重新加载盘点单数据
async function reloadSessionData() {
    if (!currentInventoryData || !currentInventoryData.session_id) {
        return;
    }
    
    const res = await fetch(`index.php?api=get_session_details&session_id=${currentInventoryData.session_id}`);
    const d = await res.json();
    
    if (d.success) {
        currentInventoryData.items = d.data;
        reloadTableData();
    }
}

// 重新渲染表格
function reloadTableData() {
    const tbody = document.getElementById('inventoryDetailBody');
    tbody.innerHTML = '';
    
    if (!currentInventoryData.items) {
        return;
    }
    
    currentInventoryData.items.forEach(item => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${item.sku || ''}</td>
            <td>${item.name || ''}</td>
            <td>${item.expiry_date || ''}</td>
            <td>${item.quantity || 0}</td>
            <td class="action-col" style="display: ${isEditMode ? 'table-cell' : 'none'};">
                <button class="btn btn-sm btn-success me-1" onclick="saveItem(${item.batch_id}, this)">
                    <i class="bi bi-check-lg"></i>
                </button>
                <button class="btn btn-sm btn-danger" onclick="deleteItem(${item.batch_id}, '${item.name}', this)">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });
    
    // 如果是编辑模式，重新应用可编辑状态
    if (isEditMode) {
        makeTableEditable();
    }
}

// 显示添加商品弹窗（复用现有的entryModal）
function showAddItemModal() {
    // 清空表单
    document.getElementById('sku').value = '';
    document.getElementById('productName').value = '';
    document.getElementById('categoryId').value = '';
    document.getElementById('removalBuffer').value = '';
    
    // 清空批次行
    const batchContainer = document.getElementById('batchContainer');
    batchContainer.innerHTML = `
        <div class="batch-row row mb-2">
            <div class="col-6">
                <input type="date" class="form-control form-control-sm e-in" required>
            </div>
            <div class="col-6">
                <input type="number" class="form-control form-control-sm q-in" placeholder="数量" min="0" required>
            </div>
        </div>
    `;
    
    // 修改确定按钮的行为
    const confirmBtn = document.getElementById('confirmEntryBtn');
    confirmBtn.onclick = addNewItemToSession;
    
    new bootstrap.Modal(document.getElementById('entryModal')).show();
}

// 添加新商品到盘点单
async function addNewItemToSession() {
    const sku = document.getElementById('sku').value.trim();
    const name = document.getElementById('productName').value.trim();
    const categoryId = document.getElementById('categoryId').value || 0;
    const removalBuffer = document.getElementById('removalBuffer').value || 0;
    
    if (!sku || !name) {
        showAlert('❌ 请填写商品SKU和名称', 'danger');
        return;
    }
    
    const batches = [];
    document.querySelectorAll('.batch-row').forEach(row => {
        const expiryDate = row.querySelector('.e-in').value;
        const quantity = parseInt(row.querySelector('.q-in').value);
        
        if (expiryDate && !isNaN(quantity)) {
            batches.push({
                expiry_date: expiryDate,
                quantity: quantity
            });
        }
    });
    
    if (batches.length === 0) {
        showAlert('❌ 请至少添加一个批次', 'danger');
        return;
    }
    
    try {
        const res = await fetch('index.php?api=add_inventory_item', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                session_key: currentInventoryData.session_id,
                sku: sku,
                name: name,
                category_id: parseInt(categoryId),
                removal_buffer: parseInt(removalBuffer),
                batches: batches
            })
        });
        
        const d = await res.json();
        
        if (d.success) {
            showAlert(`✅ 添加成功，当前共 ${d.new_count} 件商品`, 'success');
            
            // 关闭弹窗
            const modal = bootstrap.Modal.getInstance(document.getElementById('entryModal'));
            if (modal) modal.hide();
            
            // 重新加载数据
            await reloadSessionData();
        } else {
            showAlert('❌ ' + d.message, 'danger');
        }
    } catch (error) {
        console.error('添加失败:', error);
        showAlert('❌ 添加失败，请稍后重试', 'danger');
    }
}
```

---

## 🗄️ 数据库迁移

```sql
-- 添加更新时间戳字段
ALTER TABLE inventory_sessions 
ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- 添加索引以提高查询性能
ALTER TABLE batches 
ADD INDEX idx_session_id (session_id);
```

---

## ✅ 测试步骤

### 1. 后端测试
```bash
# 1. 启动服务器
cd /home/ubuntu/.openclaw/workspace/expiry-clean
php -S localhost:8000

# 2. 测试更新接口
curl -X POST http://localhost:8000/index.php?api=update_inventory_item \
  -H "Content-Type: application/json" \
  -d '{"batch_id":1,"quantity":50}'

# 3. 测试删除接口
curl -X POST http://localhost:8000/index.php?api=delete_inventory_item \
  -H "Content-Type: application/json" \
  -d '{"batch_id":1}'

# 4. 测试添加接口
curl -X POST http://localhost:8000/index.php?api=add_inventory_item \
  -H "Content-Type: application/json" \
  -d '{"session_key":"S123","sku":"123456","name":"测试","batches":[{"expiry_date":"2026-12-31","quantity":10}]}'
```

### 2. 前端测试
1. 登录系统
2. 进入"查看往期盘点"
3. 点击一个盘点单
4. 点击"编辑"按钮
5. 修改商品数量或日期
6. 点击"保存"按钮
7. 验证数据已更新
8. 点击"删除"按钮
9. 验证商品已删除
10. 点击"添加商品"按钮
11. 填写表单并提交
12. 验证新商品已添加

---

## 📝 代码审查要点 (Regression Guard)

### 安全性
- [ ] 所有SQL使用prepared statements
- [ ] 权限检查正确（只能编辑自己的盘点单）
- [ ] 数据验证完整（数量≥0，日期≥今天）
- [ ] 事务处理正确

### 一致性
- [ ] 删除商品后计数正确更新
- [ ] 添加商品后计数正确更新
- [ ] 操作日志正确记录

### 用户体验
- [ ] 编辑模式切换流畅
- [ ] Loading状态显示
- [ ] 错误提示清晰
- [ ] 成功操作有反馈

### 性能
- [ ] 数据库查询使用索引
- [ ] 避免N+1查询问题
- [ ] 大盘点单加载速度可接受

---

**文档结束**
