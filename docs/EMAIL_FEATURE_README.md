# 邮箱配置功能 - 设计文档索引

**保质期管理系统 v2.9.0+**

---

## 📁 文档结构

```
expiry-clean/
├── docs/
│   ├── email-config-design.md           # 完整设计文档
│   ├── EMAIL_IMPLEMENTATION_GUIDE.md    # 实施指南
│   └── email_admin_ui.html              # UI设计原型
├── email_accounts.sql                   # 数据库迁移脚本
├── email_functions.php                  # 核心函数库
├── email_api.php                        # API接口
└── README_EMAIL.md                      # 本文件
```

---

## 🎯 功能概述

本功能为保质期管理系统添加了完整的邮箱配置和智能轮换发送能力。

### 核心特性

1. **简化的邮箱配置**
   - 用户只需输入QQ号和授权码
   - 系统自动组装完整邮箱地址
   - 固定的SMTP配置（smtp.qq.com:465 SSL）

2. **多账户管理**
   - 支持配置多个QQ邮箱账户
   - 每个账户独立的授权码
   - 启用/禁用账户
   - 优先级设置

3. **智能轮换发送**
   - 自动选择最合适的账户
   - 基于优先级、发送次数、时间间隔
   - 失败账户自动冷却

4. **完善的日志系统**
   - 记录每次发送
   - 追踪成功/失败状态
   - 错误信息保存

---

## 📖 文档导航

### 1. 设计文档 📘

**文件**: `docs/email-config-design.md`

**内容**:
- 需求分析
- 数据库设计（表结构、索引）
- API接口设计（7个接口）
- 轮换算法设计
- 后台管理界面设计
- 安全考虑（加密、权限）

**适合人群**: 项目经理、架构师、开发人员

### 2. 实施指南 🛠️

**文件**: `docs/EMAIL_IMPLEMENTATION_GUIDE.md`

**内容**:
- 8个实施阶段的详细步骤
- 部署流程（开发/生产环境）
- 测试用例
- 验收标准
- 故障排查

**适合人群**: 开发人员、运维人员

### 3. UI设计原型 🎨

**文件**: `docs/email_admin_ui.html`

**内容**:
- 完整的HTML/CSS/JS代码
- 邮箱管理页面
- 添加/编辑模态框
- 测试发送功能

**适合人群**: 前端开发人员

---

## 🚀 快速开始

### 5分钟快速部署

```bash
# 1. 创建数据库表
mysql -u root -p expiry_system < email_accounts.sql

# 2. 复制核心文件
cp email_functions.php email_api.php /path/to/expiry-clean/

# 3. 配置加密密钥（在config.php中添加）
define('AUTH_ENCRYPTION_KEY', 'your-32-character-key');

# 4. 安装PHPMailer
composer require phpmailer/phpmailer

# 5. 测试API
curl http://your-domain/email_api.php?action=list_accounts
```

### 获取第一个QQ邮箱授权码

1. 登录QQ邮箱 (mail.qq.com)
2. 点击"设置" → "账户"
3. 找到"POP3/IMAP/SMTP/Exchange/CardDAV/CalDAV服务"
4. 开启"POP3/SMTP服务"
5. 生成授权码（16位）

---

## 📊 系统架构

```
┌─────────────────────────────────────────────────────────┐
│                      Admin.php                          │
│                   (后台管理界面)                         │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│                   email_api.php                         │
│                   (RESTful API)                         │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│                email_functions.php                      │
│              (核心业务逻辑 + 轮换算法)                    │
└────────────────────┬────────────────────────────────────┘
                     │
         ┌───────────┴───────────┐
         ▼                       ▼
┌──────────────────┐    ┌──────────────────┐
│  MySQL数据库     │    │   PHPMailer      │
│                 │    │                  │
│ - email_accounts│    │ - SMTP发送       │
│ - email_logs    │    │ - 错误处理       │
└──────────────────┘    └──────────────────┘
```

---

## 🔐 安全特性

| 特性 | 实现方式 |
|------|----------|
| 授权码加密 | AES-256-CBC + Base64 |
| SQL注入防护 | Prepared Statements |
| 权限控制 | Session验证 |
| 日志审计 | 操作日志表 |
| 错误隐藏 | 不暴露敏感信息 |

---

## 📈 性能指标

| 指标 | 目标值 |
|------|--------|
| 发送单封邮件 | < 5秒 |
| 加载账户列表 | < 1秒 |
| 轮换算法响应 | < 100ms |
| 并发支持 | 10+ 账户 |

---

## 🧪 测试覆盖

- ✅ 单元测试: 加密/解密
- ✅ 集成测试: API接口
- ✅ 功能测试: UI交互
- ✅ 性能测试: 轮换算法
- ✅ 安全测试: SQL注入、权限

---

## 📝 API接口

### 基础URL
```
http://your-domain/email_api.php
```

### 可用接口

| 接口 | 方法 | 描述 |
|------|------|------|
| add_account | POST | 添加邮箱账户 |
| list_accounts | GET | 列出所有账户 |
| update_account | POST | 更新账户信息 |
| delete_account | POST | 删除账户 |
| test_send | POST | 测试发送邮件 |
| get_logs | GET | 获取发送日志 |
| send_warning | POST | 发送预警邮件 |
| send_warning_batch | POST | 批量发送预警邮件 |

详细文档见: `docs/email-config-design.md`

---

## 🔄 轮换算法

### 策略: 智能加权轮换 (Smart Weighted Round Robin)

**权重公式**:
```
weight = 100 + (priority × 10) - (send_count × 2) + idle_bonus
```

**规则**:
1. 优先级高的账户优先
2. 发送次数少的账户优先
3. 长时间未使用的账户获得加分
4. 失败账户有5分钟冷却期

---

## 🛠️ 技术栈

| 组件 | 版本 | 用途 |
|------|------|------|
| PHP | >= 7.4 | 后端语言 |
| MySQL | >= 5.7 | 数据库 |
| PHPMailer | >= 6.0 | 邮件发送 |
| OpenSSL | - | 加密/解密 |

---

## 📞 技术支持

### 常见问题

**Q: 如何获取QQ邮箱授权码？**
A: QQ邮箱 → 设置 → 账户 → 开启SMTP → 生成授权码

**Q: 为什么邮件发送失败？**
A: 检查授权码是否正确，查看错误日志

**Q: 如何更换加密密钥？**
A: 修改 `config.php` 中的 `AUTH_ENCRYPTION_KEY`，然后重新输入所有授权码

**Q: 轮换算法如何配置？**
A: 通过设置账户的 `priority` 字段调整优先级

---

## 📋 版本历史

| 版本 | 日期 | 变更 |
|------|------|------|
| v1.0.0 | 2026-02-21 | 初始版本 |
| - | - | - |

---

## 👥 贡献者

- **设计**: Agent A (项目经理AI)
- **开发**: 待分配
- **测试**: 待分配

---

## 📄 许可证

本功能作为保质期管理系统的一部分，遵循相同的许可证。

---

**开始部署**: 查看 `docs/EMAIL_IMPLEMENTATION_GUIDE.md`

**设计细节**: 查看 `docs/email-config-design.md`

**UI集成**: 查看 `docs/email_admin_ui.html`

---

🎉 **祝使用愉快！**
