/**
 * ========================================
 * 编辑盘点单功能 - 前端界面代码
 * 版本: v1.0.0
 * 说明: 此代码需要插入到 index.php 的相应位置
 * ========================================
 */

// ========================================
// 1. 在往期盘点列表中添加"编辑"按钮
// 位置: loadPast() 函数中的卡片HTML
// 修改前:
//   <button class="btn btn-sm btn-outline-danger ms-2" onclick="deleteInventorySession('${s.session_key}', event)" title="删除盘点单">
// 修改后:
//   <button class="btn btn-sm btn-outline-primary ms-2" onclick="editSession('${s.session_key}', event)" title="编辑盘点单">
//     <i class="bi bi-pencil"></i>
//   </button>
//   <button class="btn btn-sm btn-outline-danger ms-1" onclick="deleteInventorySession('${s.session_key}', event)" title="删除盘点单">
//     <i class="bi bi-trash"></i>
//   </button>
// ========================================

// ========================================
// 2. 编辑盘点单相关函数
// 添加位置: loadPast() 函数之后
// ========================================

/**
 * 进入编辑模式
 */
async function editSession(sessionId, event) {
    event.stopPropagation(); // 阻止触发卡片点击事件

    try {
        const res = await fetch(`index.php?api=get_editable_session&session_id=${sessionId}`);
        const d = await res.json();

        if (!d.success) {
            showAlert('❌ ' + (d.message || '加载失败'), 'danger');
            return;
        }

        // 保存当前编辑的盘点单数据
        window.currentEditSession = {
            session_id: d.data.session_id,
            items: d.data.items,
            item_count: d.data.item_count
        };

        // 显示编辑界面
        showEditInterface(d.data);

    } catch (error) {
        console.error('加载编辑数据失败:', error);
        showAlert('❌ 加载失败，请稍后重试', 'danger');
    }
}

/**
 * 显示编辑界面
 */
function showEditInterface(data) {
    // 隐藏其他视图，显示编辑视图
    document.querySelectorAll('.view-section').forEach(el => el.classList.remove('active'));
    const editView = document.getElementById('editView');
    if (!editView) {
        // 如果编辑视图不存在，创建它
        createEditView();
        showEditInterface(data);
        return;
    }

    editView.classList.add('active');

    // 填充数据
    const tbody = document.getElementById('editTableBody');
    tbody.innerHTML = '';

    data.items.forEach((item, index) => {
        const row = document.createElement('tr');
        row.dataset.batchId = item.batch_id;
        row.innerHTML = `
            <td>
                <strong>${item.name || ''}</strong><br>
                <small class="text-muted">${item.sku || ''}</small>
            </td>
            <td>
                <input type="date" class="form-control form-control-sm" value="${item.expiry_date || ''}" id="edit-expiry-${index}">
            </td>
            <td>
                <input type="number" class="form-control form-control-sm" value="${item.quantity || 0}" min="1" id="edit-qty-${index}">
            </td>
            <td>
                <button class="btn btn-sm btn-success" onclick="saveBatchEdit(${item.batch_id}, ${index})">
                    <i class="bi bi-check"></i>
                </button>
                <button class="btn btn-sm btn-danger" onclick="deleteBatchItem(${item.batch_id})">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });

    // 更新商品数量显示
    document.getElementById('editItemCount').innerText = data.item_count;
    document.getElementById('editSessionId').innerText = data.session_id;
}

/**
 * 创建编辑视图HTML（首次使用时创建）
 */
function createEditView() {
    const editHtml = `
        <div id="editView" class="view-section">
            <div class="app-header">
                <div class="container">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-pencil-square me-2"></i>编辑盘点单
                        </h5>
                        <button class="btn btn-outline-secondary btn-sm" onclick="cancelEdit()">
                            <i class="bi bi-arrow-left me-1"></i>返回
                        </button>
                    </div>
                </div>
            </div>

            <div class="container mt-4">
                <div class="custom-card">
                    <h6 class="mb-3">盘点单信息</h6>
                    <p class="mb-1">
                        <strong>单号:</strong> <span id="editSessionId"></span>
                    </p>
                    <p class="mb-0">
                        <strong>商品数量:</strong> <span id="editItemCount">0</span> 件
                    </p>
                </div>

                <div class="custom-card">
                    <h6 class="mb-3">商品列表</h6>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>商品</th>
                                    <th>有效期</th>
                                    <th>数量</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="editTableBody"></tbody>
                        </table>
                    </div>

                    <div class="mt-3">
                        <button class="btn btn-success w-100" onclick="showAddProductModal()">
                            <i class="bi bi-plus-circle me-1"></i>添加商品
                        </button>
                    </div>
                </div>

                <div class="custom-card">
                    <button class="btn btn-primary w-100" onclick="finishEdit()">
                        <i class="bi bi-check-circle me-1"></i>完成编辑
                    </button>
                </div>
            </div>
        </div>
    `;

    // 插入到主内容区域
    const mainContent = document.querySelector('.container-fluid');
    if (mainContent) {
        const editDiv = document.createElement('div');
        editDiv.innerHTML = editHtml;
        mainContent.appendChild(editDiv);
    }
}

/**
 * 保存批次编辑
 */
async function saveBatchEdit(batchId, index) {
    const expiryDate = document.getElementById(`edit-expiry-${index}`).value;
    const quantity = parseInt(document.getElementById(`edit-qty-${index}`).value);

    if (!expiryDate) {
        showAlert('❌ 请选择有效期', 'danger');
        return;
    }

    if (quantity <= 0 || !Number.isInteger(quantity)) {
        showAlert('❌ 数量必须大于0的整数', 'danger');
        return;
    }

    try {
        const res = await fetch('index.php?api=update_batch', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                batch_id: batchId,
                expiry_date: expiryDate,
                quantity: quantity
            })
        });

        const d = await res.json();

        if (d.success) {
            showAlert('✅ 保存成功', 'success');
            // 重新加载当前编辑界面
            const sessionId = window.currentEditSession.session_id;
            editSession(sessionId, { stopPropagation: () => {} });
        } else {
            showAlert('❌ ' + (d.message || '保存失败'), 'danger');
        }
    } catch (error) {
        console.error('保存失败:', error);
        showAlert('❌ 保存失败，请稍后重试', 'danger');
    }
}

/**
 * 删除批次
 */
async function deleteBatchItem(batchId) {
    if (!confirm('确定要删除这个商品吗？')) {
        return;
    }

    try {
        const res = await fetch('index.php?api=delete_batch', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ batch_id: batchId })
        });

        const d = await res.json();

        if (d.success) {
            showAlert('✅ 删除成功', 'success');
            // 重新加载当前编辑界面
            const sessionId = window.currentEditSession.session_id;
            editSession(sessionId, { stopPropagation: () => {} });
        } else {
            showAlert('❌ ' + (d.message || '删除失败'), 'danger');
        }
    } catch (error) {
        console.error('删除失败:', error);
        showAlert('❌ 删除失败，请稍后重试', 'danger');
    }
}

/**
 * 显示添加商品模态框
 */
function showAddProductModal() {
    // 复用现有的扫描界面
    const scanOverlay = document.getElementById('scanOverlay');
    if (scanOverlay) {
        // 设置一个标志，表示这是在编辑模式下添加商品
        window.isEditingAddProduct = true;
        scanOverlay.style.display = 'flex';
        // 启动扫描
        startScan();
    } else {
        showAlert('❌ 扫描功能不可用', 'danger');
    }
}

/**
 * 取消编辑，返回往期盘点列表
 */
function cancelEdit() {
    document.querySelectorAll('.view-section').forEach(el => el.classList.remove('active'));
    const portalView = document.getElementById('portalView');
    if (portalView) {
        portalView.classList.add('active');
    }
    loadPast(); // 重新加载列表
}

/**
 * 完成编辑
 */
function finishEdit() {
    showAlert('✅ 编辑完成！', 'success');
    cancelEdit();
}

// ========================================
// 3. 修改扫描成功后的处理逻辑
// 位置: onScanSuccess() 函数
// 说明: 当在编辑模式下扫描成功时，调用 addProductToSession() 而不是常规处理
// ========================================

/**
 * 在编辑模式下，将扫描的商品添加到盘点单
 */
async function addProductToSession(sku, expiryDate, quantity) {
    if (!window.currentEditSession) {
        showAlert('❌ 编辑会话丢失，请重新进入编辑模式', 'danger');
        return;
    }

    try {
        const res = await fetch('index.php?api=add_to_session', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                session_id: window.currentEditSession.session_id,
                sku: sku,
                batches: [{
                    expiry_date: expiryDate,
                    quantity: quantity
                }]
            })
        });

        const d = await res.json();

        if (d.success) {
            showAlert('✅ 商品添加成功', 'success');
            // 重新加载编辑界面
            const sessionId = window.currentEditSession.session_id;
            editSession(sessionId, { stopPropagation: () => {} });
        } else {
            showAlert('❌ ' + (d.message || '添加失败'), 'danger');
        }
    } catch (error) {
        console.error('添加商品失败:', error);
        showAlert('❌ 添加失败，请稍后重试', 'danger');
    }
}
