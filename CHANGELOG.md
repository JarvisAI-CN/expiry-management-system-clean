# 更新日志 (CHANGELOG)

## [v2.8.4] - 2026-02-19

### 🆕 新增功能

**一键自动安装**
- ✨ 安装引导页（install.php）全自动初始化
  - 自动创建所有数据库表（包括API密钥管理表）
  - 自动配置数据库连接
  - 自动创建管理员账号
  - 无需手动导入SQL文件

**API密钥管理集成**
- ✨ 安装时自动创建 `api_keys` 和 `api_logs` 表
- ✨ 主菜单新增"API密钥管理"入口
- ✨ 完整的API密钥生命周期管理

### 🔧 改进优化
- 安装流程更简洁，只需填写数据库信息和管理员账号
- 所有表结构一次性创建完成
- 适合零基础用户快速部署

### 📝 部署说明
1. 上传所有文件到服务器
2. 访问 `install.php` 按引导操作
3. 删除 `install.php` 保安全
4. 开始使用！

---

## [v2.8.2] - 2026-02-19

### 🔒 安全修复

- 强化在线升级接口安全：
  - `index.php?api=check_upgrade` 与 `index.php?api=execute_upgrade` 现均必须在登录状态下、通过 `checkAuth()` 后才能调用，未登录访问将返回统一 JSON 错误：`{"success": false, "message": "请先登录"}`。
  - 升级与强制修复仅从官方 GitHub HTTPS 源拉取文件：`https://raw.githubusercontent.com/JarvisAI-CN/expiry-management-system/main/`，彻底移除 HTTP 明文本机回退源。
  - 升级拉取/写入任意文件失败时，会返回 `success: false` 及错误提示，不再静默失败。
- 修复 `submit_session` SQL 注入风险：
  - 对前端盘点会话 `session_id` 进行严格校验，仅允许 `[A-Za-z0-9_-]` 且长度 1–64。
  - 使用预处理语句完成 `SELECT COUNT(*) FROM batches WHERE session_id = ?` 与 `INSERT INTO inventory_sessions (session_key, user_id, item_count) VALUES (?, ?, ?)`，杜绝字符串拼接 SQL。
- API 密钥改用哈希存储：
  - 新建密钥时生成 32 字节随机数（hex 后 64 字符）作为明文 key，仅在创建成功响应中返回一次。
  - 数据库仅存储 `sha256(明文 key)` 至 `api_key_hash` 字段，原 `api_key` 字段标记为兼容字段，不再参与校验。
  - `api.php` 在校验时从 Authorization Bearer 或参数中取明文 key，计算 SHA256 后按 `api_key_hash = ? AND is_active = 1` 检查。
  - Web 管理界面不再显示或复制完整明文 key，仅展示遮罩占位文本（如 `********-********`），避免后台泄露。

## [v2.8.1] - 2026-02-18

### 🆕 新增功能

**API接口系统**
- ✨ RESTful API接口 (`api.php`)
  - 支持6个数据端点：summary, products, batches, expiring, categories, all
  - Bearer Token认证机制
  - JSON数据格式输出
  - 自动CORS支持

**API密钥管理**
- ✨ API密钥管理页面 (`api_keys.php`)
  - 密钥创建、删除、启用/禁用
  - 使用日志查看
  - 最后使用时间追踪
  - SHA256哈希存储

**Python API客户端**
- ✨ 开箱即用的客户端 (`scripts/expiry_api_client.py`)
  - 自动数据汇总
  - AI友好格式输出
  - 命令行工具支持

### 📝 文档更新
- 新增 `API_UPGRADE_GUIDE.md` - API升级指南
- 新增 `api_keys.sql` - 数据库升级脚本
- 更新 `README.md` - 添加API功能说明
- 新增 `api_key_manager.php` - API管理核心函数

### 🔧 技术改进
- API访问日志表 (`api_logs`)
- API密钥表 (`api_keys`)
- 请求参数验证
- 错误处理优化

### 🐛 Bug修复
- 修复dashboard.php在某些情况下的布局问题
- 优化API响应速度

---

## [v2.8.0-alpha] - 2026-02-17

### 🆕 新增功能

**智能管理看板**
- ✨ 统一管理仪表板 (`dashboard.php`)
  - 预警统计概览
  - 盘点任务管理
  - 预警配置调整
  - 一站式管理体验

**自动化盘点提醒**
- 🔄 根据 `inventory_cycle` 自动生成盘点任务
- ⏰ 超期自动预警，不再遗漏重要盘点
- 📋 任务看板管理

**智能多级预警系统**
- ⚠️ 三级过期预警（7/15/30天）
- 📦 库存预警（低库存/缺货）
- 🎯 全方位监控库存健康
- ⚙️ 可自定义预警阈值

### 🗄️ 数据库升级
- 新增 `inventory_tasks` 表 - 盘点任务管理
- 新增 `alerts` 表 - 预警记录
- 新增 `alert_rules` 表 - 预警规则配置
- 新增 `inventory_cycle` 字段 - 盘点周期

### 🔧 技术改进
- 预警扫描引擎
- 任务状态追踪
- 配置动态调整

---

## [v2.7.3] - 2026-02-16

### 🔧 技术改进
- 智能更新机制（GitHub回退 + Auto-Migration 2.0）
- 优化文件更新逻辑

---

## [v2.7.2] - 2026-02-15

### 🐛 Bug修复
- AI测试增加50秒倒计时和超时保护

---

## [v2.7.1] - 2026-02-14

### 🐛 Bug修复
- 修复AI测试按钮事件绑定问题

---

## [v2.7] - 2026-02-10

### 🆕 新增功能
- 独立管理员控制台 (`admin.php`)
- 前后台分离架构
- 百分百修复核心文件能力

---

## [v2.6] - 2026-02-08

### 🆕 新增功能
- 批量导入CSV SKU库
- 暂存队列模式
- 盘点周期设置

---

## [v2.4] - 2026-02-05

### 🆕 新增功能
- 可视化大盘（首页健康进度条）
- 智能锁定（扫码识别老商品）
- 门户菜单

---

## [v2.0] - 2026-02-01

### 🆕 新增功能
- 手机浏览器扫码
- 多批次存储
- 在线一键热更新系统

---

## [v1.0] - 2026-01-20

### 🎉 首次发布
- 基础扫码功能
- 数据库存储
- 简单界面

---

**开发者**: 贾维斯 ⚡
**项目**: 保质期管理系统
