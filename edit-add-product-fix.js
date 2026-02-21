/**
 * 编辑界面添加商品功能修复补丁
 * 问题：编辑页面点击"添加商品"只能扫一扫，不能手动输入SKU
 * 解决：添加手动输入SKU的界面，保留扫一扫功能
 */

// 修复代码：在index.php中找到 showAddProductModal() 函数并替换

// 原来的代码（大约在2459行）：
/*
function showAddProductModal() {
    // 复用现有的扫描界面
    const scanOverlay = document.getElementById('scanOverlay');
    if (scanOverlay) {
        // 设置一个标志，表示这是在编辑模式下添加商品
        window.isEditingAddProduct = true;
        scanOverlay.style.display = 'flex';
        // 启动扫描
        if(typeof startScan === 'function') {
            startScan();
        }
    } else {
        showAlert('❌ 扫描功能不可用', 'danger');
    }
}
*/

// 替换为：
function showAddProductModal() {
    // 创建添加商品模态框（如果不存在）
    let modal = document.getElementById('editAddProductModal');
    if (!modal) {
        const modalHtml = `
            <div class="modal fade" id="editAddProductModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">添加商品到盘点单</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <!-- SKU输入区域 -->
                            <div class="mb-3">
                                <label class="form-label">商品SKU</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="editAddSkuInput" placeholder="输入SKU或扫码">
                                    <button class="btn btn-outline-secondary" type="button" onclick="startEditScan()">
                                        <i class="bi bi-qr-code-scan"></i> 扫一扫
                                    </button>
                                </div>
                                <div id="editAddSkuSuggestions" class="list-group mt-2" style="display:none; max-height: 200px; overflow-y: auto;"></div>
                            </div>

                            <!-- 商品信息显示 -->
                            <div id="editAddProductInfo" class="mb-3" style="display:none;">
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title" id="editAddProductName"></h6>
                                        <p class="card-text mb-0">
                                            <strong>SKU:</strong> <span id="editAddProductSku"></span>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- 批次信息 -->
                            <div id="editAddBatchContainer" style="display:none;">
                                <label class="form-label">批次信息</label>
                                <div class="batch-row mb-2">
                                    <div class="mb-2">
                                        <label class="form-label small">到期日期</label>
                                        <input type="date" class="form-control form-control-sm" id="editAddExpiryDate">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small">数量</label>
                                        <input type="number" class="form-control form-control-sm" id="editAddQuantity" min="1" value="1">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="button" class="btn btn-primary" onclick="confirmEditAddProduct()">确定添加</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        // 绑定SKU输入框事件
        const skuInput = document.getElementById('editAddSkuInput');
        skuInput.addEventListener('input', function() {
            const sku = this.value.trim();
            if (sku.length >= 3) {
                searchEditProductSku(sku);
            } else {
                document.getElementById('editAddSkuSuggestions').style.display = 'none';
            }
        });
    }

    // 显示模态框
    const bsModal = new bootstrap.Modal(document.getElementById('editAddProductModal'));
    bsModal.show();

    // 重置表单
    document.getElementById('editAddSkuInput').value = '';
    document.getElementById('editAddProductInfo').style.display = 'none';
    document.getElementById('editAddBatchContainer').style.display = 'none';
    document.getElementById('editAddSkuSuggestions').style.display = 'none';
    document.getElementById('editAddExpiryDate').value = '';
    document.getElementById('editAddQuantity').value = '1';
}

// 模糊搜索SKU
async function searchEditProductSku(sku) {
    try {
        const res = await fetch(`index.php?api=manual_search&sku=${encodeURIComponent(sku)}`);
        const d = await res.json();

        const suggestionsDiv = document.getElementById('editAddSkuSuggestions');
        suggestionsDiv.innerHTML = '';

        if (d.success && d.data && d.data.length > 0) {
            d.data.forEach(product => {
                const item = document.createElement('a');
                item.href = '#';
                item.className = 'list-group-item list-group-item-action';
                item.innerHTML = `
                    <div class="d-flex w-100 justify-content-between">
                        <strong>${product.sku}</strong>
                        <small>${product.name}</small>
                    </div>
                `;
                item.onclick = (e) => {
                    e.preventDefault();
                    selectEditProduct(product.sku, product.name);
                };
                suggestionsDiv.appendChild(item);
            });
            suggestionsDiv.style.display = 'block';
        } else {
            suggestionsDiv.style.display = 'none';
        }
    } catch (error) {
        console.error('搜索失败:', error);
    }
}

// 选择商品
function selectEditProduct(sku, name) {
    document.getElementById('editAddSkuInput').value = sku;
    document.getElementById('editAddSkuSuggestions').style.display = 'none';

    // 显示商品信息
    document.getElementById('editAddProductName').textContent = name;
    document.getElementById('editAddProductSku').textContent = sku;
    document.getElementById('editAddProductInfo').style.display = 'block';

    // 显示批次输入框
    document.getElementById('editAddBatchContainer').style.display = 'block';
}

// 启动扫描
function startEditScan() {
    // 设置标志
    window.isEditingAddProduct = true;

    // 隐藏模态框
    const modal = bootstrap.Modal.getInstance(document.getElementById('editAddProductModal'));
    if (modal) {
        modal.hide();
    }

    // 显示扫描界面
    const scanOverlay = document.getElementById('scanOverlay');
    if (scanOverlay) {
        scanOverlay.style.display = 'flex';
        if (typeof startScan === 'function') {
            startScan();
        }
    }
}

// 确认添加商品
async function confirmEditAddProduct() {
    const sku = document.getElementById('editAddSkuInput').value.trim();
    const expiryDate = document.getElementById('editAddExpiryDate').value;
    const quantity = parseInt(document.getElementById('editAddQuantity').value);

    if (!sku) {
        showAlert('❌ 请输入商品SKU', 'danger');
        return;
    }

    if (!expiryDate) {
        showAlert('❌ 请选择到期日期', 'danger');
        return;
    }

    if (quantity <= 0) {
        showAlert('❌ 数量必须大于0', 'danger');
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

            // 关闭模态框
            const modal = bootstrap.Modal.getInstance(document.getElementById('editAddProductModal'));
            if (modal) {
                modal.hide();
            }

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

console.log('编辑界面添加商品功能修复补丁已加载');
