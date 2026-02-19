# 开发者快速参考 - v2.8.3

## 🎯 新增功能概览

### 1. 模糊搜索系统
- **位置**：SKU输入框（`#sku`）
- **触发方式**：输入字符后300ms自动搜索
- **显示方式**：Bootstrap Modal弹窗
- **API端点**：`index.php?api=fuzzy_search`

### 2. 手电筒控制
- **位置**：扫码界面右上角（`#flashlightBtn`）
- **触发方式**：点击按钮切换
- **API**：HTML5 MediaStreamTrack.applyConstraints
- **检测方式**：capabilities.torch

---

## 📁 修改文件清单

### 核心文件
```
expiry-clean/
├── index.php                    # 主文件（已修改）
│   ├── 版本号更新：2.8.2 → 2.8.3
│   ├── 新增API：fuzzy_search
│   ├── 新增HTML：搜索弹窗、手电筒按钮
│   ├── 新增CSS：搜索样式、手电筒样式
│   └── 新增JS：搜索逻辑、手电筒控制
├── VERSION.txt                  # 版本号文件（已修改）
├── CHANGELOG_v2.8.3.md         # 更新日志（新增）
└── TEST_CHECKLIST_v2.8.3.md    # 测试清单（新增）
```

---

## 🔧 核心代码片段

### API端点：fuzzy_search

**位置**：index.php (第84-112行)

```php
if ($action === 'fuzzy_search') {
    $query = trim($_GET['q'] ?? '');
    
    // 验证输入长度
    if (strlen($query) < 1) {
        echo json_encode(['success' => true, 'results' => []]);
        exit;
    }
    
    // 限制搜索关键词长度，防止滥用
    if (strlen($query) > 50) {
        echo json_encode(['success' => false, 'message' => '搜索关键词过长']);
        exit;
    }
    
    // 使用预处理语句，支持SKU部分匹配和品名模糊搜索
    $searchTerm = '%' . $query . '%';
    $stmt = $conn->prepare("
        SELECT sku, name, created_at 
        FROM products 
        WHERE sku LIKE ? OR name LIKE ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->bind_param("ss", $searchTerm, $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = [
            'sku' => $row['sku'],
            'name' => $row['name'],
            'created_at' => date('Y-m-d', strtotime($row['created_at']))
        ];
    }
    
    echo json_encode(['success' => true, 'results' => $products]);
    exit;
}
```

**关键点**：
- ✅ 使用预处理语句防止SQL注入
- ✅ 输入长度验证（1-50字符）
- ✅ 限制结果数量（最多10条）
- ✅ 支持SKU和品名双向搜索
- ✅ 需要登录验证（checkAuth）

---

### 前端：模糊搜索逻辑

**位置**：index.php (JavaScript部分)

```javascript
// SKU输入框监听
const skuInput = document.getElementById('sku');
if (skuInput) {
    skuInput.addEventListener('input', function(e) {
        const query = e.target.value.trim();
        
        // 清除之前的定时器（防抖）
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }
        
        // 输入为空时不搜索
        if (query.length < 1) {
            return;
        }
        
        // 延迟300ms执行搜索（防抖）
        searchTimeout = setTimeout(() => {
            performFuzzySearch(query);
        }, 300);
    });
}

// 执行模糊搜索
async function performFuzzySearch(query) {
    try {
        const res = await fetch(
            `index.php?api=fuzzy_search&q=${encodeURIComponent(query)}`
        );
        const data = await res.json();
        
        if (data.success && data.results.length > 0) {
            showSearchResults(data.results);
        }
    } catch (err) {
        console.error('搜索失败:', err);
    }
}

// 显示搜索结果
function showSearchResults(results) {
    const listContainer = document.getElementById('searchResultsList');
    listContainer.innerHTML = '';
    
    results.forEach(item => {
        const div = document.createElement('div');
        div.className = 'search-result-item';
        div.innerHTML = `
            <div class="search-sku">📦 ${item.sku}</div>
            <div class="search-name">${item.name}</div>
            <div class="search-date">入库时间: ${item.created_at}</div>
        `;
        
        // 点击选择该项
        div.addEventListener('click', () => {
            selectSearchResult(item);
        });
        
        listContainer.appendChild(div);
    });
    
    // 显示弹窗
    const modal = new bootstrap.Modal(
        document.getElementById('searchResultsModal')
    );
    modal.show();
}

// 选择搜索结果
function selectSearchResult(item) {
    document.getElementById('sku').value = item.sku;
    
    const modal = bootstrap.Modal.getInstance(
        document.getElementById('searchResultsModal')
    );
    if (modal) {
        modal.hide();
    }
    
    // 触发SKU查询
    searchSKU(item.sku);
}
```

**关键点**：
- ✅ 防抖机制减少API请求
- ✅ 动态创建搜索结果列表
- ✅ 使用Bootstrap Modal展示结果
- ✅ 点击自动填充并触发查询

---

### 前端：手电筒控制

**位置**：index.php (JavaScript部分)

```javascript
let flashlightState = false;
let videoTrack = null;

// 检测手电筒支持
async function checkFlashlightSupport() {
    try {
        // 获取视频轨道
        const stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment' }
        });
        
        videoTrack = stream.getVideoTracks()[0];
        const capabilities = videoTrack.getCapabilities();
        
        // 检查是否支持torch（手电筒）
        if (capabilities.torch) {
            document.getElementById('flashlightBtn').style.display = 'flex';
        } else {
            document.getElementById('flashlightBtn').style.display = 'none';
        }
    } catch (err) {
        console.error('无法检测手电筒支持:', err);
        document.getElementById('flashlightBtn').style.display = 'none';
    }
}

// 切换手电筒
async function toggleFlashlight() {
    if (!videoTrack) {
        showAlert('相机未启动', 'warning');
        return;
    }
    
    try {
        flashlightState = !flashlightState;
        
        await videoTrack.applyConstraints({
            advanced: [{ torch: flashlightState }]
        });
        
        // 更新按钮样式
        const btn = document.getElementById('flashlightBtn');
        if (flashlightState) {
            btn.classList.add('active');
            btn.innerHTML = '<i class="bi bi-lightbulb-fill"></i>';
        } else {
            btn.classList.remove('active');
            btn.innerHTML = '<i class="bi bi-lightbulb"></i>';
        }
    } catch (err) {
        console.error('切换手电筒失败:', err);
        showAlert('手电筒控制失败', 'danger');
        flashlightState = false;
    }
}

// 在扫码启动时检测
document.getElementById('startScanBtn')?.addEventListener('click', async ()=>{
    // ... 启动扫码 ...
    
    // 检测手电筒支持
    await checkFlashlightSupport();
});

// 手电筒按钮事件
document.getElementById('flashlightBtn')?.addEventListener('click', async function() {
    await toggleFlashlight();
});
```

**关键点**：
- ✅ 使用MediaStreamTrack API
- ✅ 检测capabilities.torch判断支持性
- ✅ 使用applyConstraints控制开关
- ✅ CSS状态同步（active class）
- ✅ 错误处理完善

---

### CSS：搜索结果样式

**位置**：index.php (<style>标签)

```css
/* 搜索结果弹窗样式 */
#searchResultsModal .modal-content { 
    border-radius: 15px; 
    border: none; 
}

#searchResultsList { 
    max-height: 300px; 
    overflow-y: auto; 
}

.search-result-item { 
    padding: 12px; 
    border-bottom: 1px solid #f0f0f0; 
    cursor: pointer; 
    transition: background 0.2s; 
}

.search-result-item:hover { 
    background: #f8f9fa; 
}

.search-result-item:last-child { 
    border-bottom: none; 
}

.search-sku { 
    font-weight: bold; 
    color: var(--primary-color); 
    font-size: 1.1rem; 
}

.search-name { 
    color: #333; 
    margin-top: 4px; 
}

.search-date { 
    font-size: 0.85rem; 
    color: #999; 
    margin-top: 4px; 
}
```

**关键点**：
- ✅ 圆角卡片设计
- ✅ Hover效果
- ✅ 层次结构清晰
- ✅ 符合淡蓝苹果风格

---

### CSS：手电筒按钮样式

```css
/* 手电筒按钮样式 */
#flashlightBtn { 
    position: absolute; 
    top: 70px; 
    right: 20px; 
    z-index: 2100; 
    background: rgba(255,255,255,0.9); 
    border: none; 
    border-radius: 50%; 
    width: 50px; 
    height: 50px; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    box-shadow: 0 2px 10px rgba(0,0,0,0.3); 
    cursor: pointer; 
    font-size: 1.5rem; 
    transition: all 0.3s; 
}

#flashlightBtn.active { 
    background: #ffd700; 
    box-shadow: 0 0 20px rgba(255,215,0,0.8); 
}

#flashlightBtn:hover { 
    transform: scale(1.1); 
}
```

**关键点**：
- ✅ 圆形按钮设计
- ✅ 开启时金色高亮
- ✅ 光晕效果
- ✅ Hover缩放动画

---

## 🔌 API接口文档

### fuzzy_search - 模糊搜索

**请求示例**：
```bash
# SKU部分搜索
curl "http://your-domain/index.php?api=fuzzy_search&q=568" \
  -H "Cookie: PHPSESSID=your_session_id"

# 品名关键词搜索
curl "http://your-domain/index.php?api=fuzzy_search&q=北京产地" \
  -H "Cookie: PHPSESSID=your_session_id"
```

**响应示例**：
```json
{
    "success": true,
    "results": [
        {
            "sku": "22250568",
            "name": "北京产地的巧克力粉",
            "created_at": "2026-02-10"
        },
        {
            "sku": "33350568",
            "name": "北京产地的摩卡粉",
            "created_at": "2026-02-12"
        }
    ]
}
```

**错误响应**：
```json
{
    "success": false,
    "message": "搜索关键词过长"
}
```

**未登录响应**：
```json
{
    "success": false,
    "message": "请先登录"
}
```

---

## 🛠️ 调试技巧

### 1. 查看搜索请求
```javascript
// 在performFuzzySearch函数中添加
console.log('搜索关键词:', query);
console.log('搜索结果:', data);
```

### 2. 查看手电筒状态
```javascript
// 在toggleFlashlight函数中添加
console.log('手电筒状态:', flashlightState);
console.log('视频轨道:', videoTrack);
console.log('设备能力:', videoTrack.getCapabilities());
```

### 3. 测试API
```bash
# 使用curl测试搜索API
curl -v "http://localhost/index.php?api=fuzzy_search&q=test" \
  -H "Cookie: PHPSESSID=your_session_id"
```

### 4. 浏览器控制台
```javascript
// 检查是否支持torch
navigator.mediaDevices.getSupportedConstraints()
// 返回: { torch: true/false }

// 检查当前视频轨道
html5QrCode.getRunningTrackCapabilities()
```

---

## ⚠️ 常见问题

### Q1: 搜索不工作？
**检查项**：
- ✅ 是否已登录？
- ✅ 输入长度是否>=1？
- ✅ 输入长度是否<=50？
- ✅ 浏览器控制台是否有错误？

### Q2: 手电筒按钮不显示？
**检查项**：
- ✅ 设备是否支持torch？
- ✅ 是否已授予摄像头权限？
- ✅ 是否在HTTPS环境或localhost？

### Q3: 手电筒无法开启？
**检查项**：
- ✅ 浏览器是否支持MediaStreamTrack API？
- ✅ 设备驱动是否支持torch控制？
- ✅ 检查浏览器控制台错误信息

### Q4: 搜索结果格式错误？
**检查项**：
- ✅ 数据库created_at字段格式是否正确？
- ✅ 日期格式化函数是否正常？

---

## 📊 性能优化建议

### 1. 添加搜索索引
```sql
-- 如果还没有索引，可以添加
ALTER TABLE products ADD INDEX idx_sku (sku);
ALTER TABLE products ADD INDEX idx_name (name);
```

### 2. 启用查询缓存
```php
// 在db.php中添加
$conn->query("SET SESSION query_cache_type = ON");
```

### 3. 前端缓存
```javascript
// 缓存搜索结果
let searchCache = {};
async function performFuzzySearch(query) {
    if (searchCache[query]) {
        showSearchResults(searchCache[query]);
        return;
    }
    // ... 执行搜索 ...
    searchCache[query] = data.results;
}
```

---

## 🚀 未来扩展方向

### 1. 搜索增强
- 支持拼音搜索（需要引入拼音库）
- 支持多关键词组合（空格分隔）
- 添加搜索历史记录
- 添加热门搜索推荐

### 2. 手电筒增强
- 添加亮度调节
- 添加闪烁模式（SOS）
- 添加定时关闭功能

### 3. UI优化
- 添加加载动画
- 添加骨架屏
- 添加搜索建议（自动补全）
- 添加语音搜索

---

**开发者**: 贾维斯 ⚡  
**更新日期**: 2026-02-19  
**版本**: v2.8.3  
**项目**: 保质期管理系统
