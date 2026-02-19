# 保质期管理系统 - API接口升级指南

**版本**: v2.9.0 (API接口版本)
**升级日期**: 2026-02-18
**开发者**: 贾维斯 ⚡

---

## 🎯 升级目标

为保质期管理系统添加**RESTful API接口**，让AI助手（贾维斯）可以通过API密钥访问站点数据，进行智能分析和汇总，**无需额外配置AI功能**。

**保留现有功能**: 后台AI功能完全保留，其他人继续正常使用。

---

## ✨ 新功能概览

### 1. API密钥管理系统
- ✅ 后台生成/管理API密钥
- ✅ 密钥权限控制
- ✅ 使用日志追踪
- ✅ 状态切换（启用/禁用）

### 2. RESTful API接口
- ✅ 标准HTTP接口
- ✅ Bearer Token认证
- ✅ JSON数据格式
- ✅ 多端点支持

### 3. Python API客户端
- ✅ 开箱即用的Python脚本
- ✅ 自动数据汇总
- ✅ AI友好格式输出
- ✅ 命令行工具

---

## 📦 升级内容

### 新增文件

| 文件名 | 用途 |
|--------|------|
| `api.php` | API数据接口（6个endpoint） |
| `api_key_manager.php` | API密钥管理核心函数 |
| `api_keys.php` | API密钥管理页面（Web界面） |
| `api_keys.sql` | 数据库升级脚本 |
| `scripts/expiry_api_client.py` | Python API客户端 |
| `upgrade_to_api.sh` | 一键升级脚本 |

---

## 🚀 升级步骤

### 步骤1: 备份数据库

```bash
# 导出数据库
mysqldump -u用户名 -p密码 expiry_system > backup_$(date +%Y%m%d).sql
```

### 步骤2: 执行数据库升级

```bash
# 进入项目目录
cd /home/ubuntu/.openclaw/workspace/PARA/Archives/保质期管理系统

# 执行SQL脚本
mysql -u用户名 -p密码 expiry_system < api_keys.sql
```

### 步骤3: 上传新文件到服务器

```bash
# 如果是远程服务器，使用scp或sftp上传
# 或者在宝塔面板文件管理器中上传
```

### 步骤4: 访问API管理页面

```
http://你的域名/api_keys.php
```

### 步骤5: 创建API密钥

1. 点击"创建新密钥"
2. 输入密钥名称（例如："贾维斯访问密钥"）
3. 点击"创建"
4. **重要**: 复制生成的API密钥并保存

---

## 📖 API接口文档

### 认证方式

在请求头中添加:

```
Authorization: Bearer YOUR_API_KEY
```

### 支持的Endpoint

#### 1. summary - 汇总统计
```bash
GET /api.php?endpoint=summary
```

返回数据:
```json
{
  "success": true,
  "statistics": {
    "total_products": 100,
    "total_batches": 250,
    "total_stock": 5000,
    "expired": 5,
    "critical": 10,
    "warning": 25
  }
}
```

#### 2. products - 所有产品
```bash
GET /api.php?endpoint=products
```

#### 3. batches - 所有批次
```bash
GET /api.php?endpoint=batches
```

#### 4. expiring - 即将过期
```bash
GET /api.php?endpoint=expiring&days=30
```

#### 5. categories - 分类数据
```bash
GET /api.php?endpoint=categories
```

#### 6. all - 完整导出
```bash
GET /api.php?endpoint=all
```

---

## 🐍 Python客户端使用

### 安装依赖

```bash
pip3 install requests
```

### 基本使用

```python
from expiry_api_client import ExpiryAPI

# 创建客户端
client = ExpiryAPI(
    base_url="http://ceshi.dhmip.cn",
    api_key="your_api_key_here"
)

# 获取汇总统计
summary = client.get_summary()
print(summary)

# 生成AI友好报告
report = client.generate_report()
print(report)

# 获取需要处理的项目
critical = client.get_critical_items()
print(f"已过期: {len(critical['expired'])}")
print(f"7天内过期: {len(critical['critical'])}")
```

### 命令行使用

```bash
# 生成报告
python3 /home/ubuntu/.openclaw/workspace/scripts/expiry_api_client.py \
  http://ceshi.dhmip.cn \
  YOUR_API_KEY
```

---

## 🤖 贾维斯集成

主人，当你创建好API密钥后，告诉我：

```
主人：站点是 http://ceshi.dhmip.cn，API密钥是 xxxxxx
```

我就能：
1. 自动访问你的保质期数据
2. 生成智能汇总报告
3. 分析过期风险
4. 提出处理建议

**示例对话**:

```
你: 帮我看看保质期系统有什么需要处理的

贾维斯: 我来检查一下...
      ✅ 已获取数据
      📊 总商品: 150个
      ⚠️  5个已过期
      🔴 12个7天内过期
      💡 建议: 立即处理已过期的5个商品...
```

---

## 🔒 安全建议

1. **保护API密钥** - 不要在公开代码中暴露
2. **设置过期时间** - 临时密钥建议设置过期日期
3. **定期轮换** - 重要密钥定期更换
4. **监控日志** - 定期查看API访问日志
5. **IP白名单** (可选) - 限制API访问来源IP

---

## 📊 现有功能保留

**后台AI功能完全保留**！

- ✅ 现有的AI OCR识别功能不受影响
- ✅ AI销售方案生成功能正常使用
- ✅ 其他管理员功能全部保留
- ✅ 只新增了API接口，不改变现有逻辑

---

## 🐛 故障排查

### 问题1: API返回401/403错误

**原因**: API密钥无效或已禁用

**解决**:
1. 检查API密钥是否正确复制
2. 确认密钥状态为"启用"
3. 检查密钥是否已过期

### 问题2: API返回空数据

**原因**: 数据库中无数据或权限问题

**解决**:
1. 确认数据库中有数据
2. 检查数据库连接配置
3. 查看API日志表

### 问题3: Python脚本报错

**原因**: 缺少requests库

**解决**:
```bash
pip3 install requests
```

---

## 📝 更新日志

### v2.9.0 (2026-02-18)
- ✨ 新增API密钥管理系统
- ✨ 新增RESTful API接口（6个endpoint）
- ✨ 新增Python API客户端
- ✨ 新增API访问日志功能
- 📝 完善API使用文档
- 🔒 API密钥按 SHA256 哈希存储在 `api_keys.api_key_hash` 字段
- 🔒 Bearer Token认证

---

## 💡 下一步计划

- [ ] 添加Webhook通知功能
- [ ] 支持批量数据导入API
- [ ] 添加数据导出（CSV/Excel）
- [ ] API速率限制
- [ ] IP白名单功能

---

**开发者**: 贾维斯 ⚡
**支持**: jarvis.openclaw@email.cn
**项目**: 保质期管理系统 v2.9.0
