# 保质期管理系统 - 邮箱配置功能设计方案

**设计者**: Agent A (项目经理)
**设计日期**: 2026-02-21
**版本**: v1.0
**状态**: 🎯 设计阶段

---

## 📋 目录

1. [需求概述](#需求概述)
2. [数据库设计](#数据库设计)
3. [API接口设计](#api接口设计)
4. [轮换算法设计](#轮换算法设计)
5. [后台管理界面](#后台管理界面)
6. [安全考虑](#安全考虑)
7. [实施计划](#实施计划)

---

## 需求概述

### 核心功能需求

1. **默认SMTP配置写死**
   - 服务器：smtp.qq.com
   - 加密方式：SSL
   - 端口：465

2. **简化用户操作**
   - 用户只需输入：QQ号（如：123456789）
   - 用户只需输入：邮箱授权码
   - 系统自动组装成完整邮箱：123456789@qq.com

3. **多账户管理**
   - 支持配置多个QQ邮箱账户
   - 每个账户有独立的授权码
   - 支持启用/禁用账户

4. **智能轮换发送**
   - 每次发送邮件时使用不同的账户
   - 避免单一账户发送过多导致被封禁
   - 记录每个账户的使用次数和时间

---

## 数据库设计

### 1. 邮箱账户表 (email_accounts)

```sql
CREATE TABLE IF NOT EXISTS `email_accounts` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '账户ID',
  `qq_number` VARCHAR(20) NOT NULL COMMENT 'QQ号',
  `email_address` VARCHAR(100) NOT NULL COMMENT '完整邮箱地址 (自动生成)',
  `auth_code_encrypted` TEXT NOT NULL COMMENT '加密后的授权码',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用: 1=启用, 0=禁用',
  `priority` INT(11) NOT NULL DEFAULT 0 COMMENT '优先级 (数字越大优先级越高)',
  `send_count` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计发送次数',
  `last_sent_at` DATETIME DEFAULT NULL COMMENT '最后发送时间',
  `last_sent_success` TINYINT(1) DEFAULT NULL COMMENT '最后发送是否成功: 1=成功, 0=失败',
  `error_message` TEXT DEFAULT NULL COMMENT '最后的错误信息',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `created_by` INT(11) UNSIGNED DEFAULT NULL COMMENT '创建人ID (关联users表)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_qq_number` (`qq_number`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_priority` (`priority`),
  KEY `idx_last_sent` (`last_sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮箱账户配置表';
```

### 2. 邮件发送日志表 (email_logs)

```sql
CREATE TABLE IF NOT EXISTS `email_logs` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `account_id` INT(11) UNSIGNED NOT NULL COMMENT '邮箱账户ID',
  `recipient` VARCHAR(200) NOT NULL COMMENT '收件人邮箱',
  `subject` VARCHAR(500) NOT NULL COMMENT '邮件主题',
  `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending' COMMENT '发送状态',
  `error_message` TEXT DEFAULT NULL COMMENT '错误信息',
  `sent_at` DATETIME DEFAULT NULL COMMENT '发送时间',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_status` (`status`),
  KEY `idx_sent_at` (`sent_at`),
  KEY `idx_recipient` (`recipient`),
  CONSTRAINT `fk_email_logs_account` FOREIGN KEY (`account_id`) 
    REFERENCES `email_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮件发送日志表';
```

### 索引设计说明

| 索引名 | 字段 | 用途 |
|--------|------|------|
| uk_qq_number | qq_number | 防止重复添加同一QQ号 |
| idx_is_active | is_active | 快速查询启用的账户 |
| idx_priority | priority | 按优先级排序 |
| idx_last_sent | last_sent_at | 轮换算法查询 |
| idx_account_id | account_id (logs) | 查询某账户的所有日志 |
| idx_status | status (logs) | 查询失败/成功的邮件 |

---

## API接口设计

### 基础信息

**接口前缀**: `/email_api.php`
**认证方式**: Session（管理员登录后）
**响应格式**: JSON

### 通用响应格式

```json
{
  "success": true/false,
  "message": "操作结果描述",
  "data": {},
  "error": "错误详情（仅失败时）"
}
```

---

### 1. 添加邮箱账户

**接口**: `POST /email_api.php?action=add_account`

**请求参数**:
```json
{
  "qq_number": "123456789",
  "auth_code": "授权码"
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "邮箱账户添加成功",
  "data": {
    "id": 1,
    "email_address": "123456789@qq.com",
    "qq_number": "123456789",
    "is_active": true
  }
}
```

**错误响应**:
```json
{
  "success": false,
  "message": "QQ号已存在",
  "error": "DUPLICATE_QQ"
}
```

---

### 2. 列出所有邮箱账户

**接口**: `GET /email_api.php?action=list_accounts`

**请求参数**: 无

**响应示例**:
```json
{
  "success": true,
  "message": "获取成功",
  "data": {
    "total": 2,
    "active_count": 1,
    "accounts": [
      {
        "id": 1,
        "qq_number": "123456789",
        "email_address": "123456789@qq.com",
        "is_active": true,
        "priority": 0,
        "send_count": 15,
        "last_sent_at": "2026-02-21 14:30:00",
        "last_sent_success": true,
        "created_at": "2026-02-20 10:00:00"
      },
      {
        "id": 2,
        "qq_number": "987654321",
        "email_address": "987654321@qq.com",
        "is_active": false,
        "priority": 0,
        "send_count": 8,
        "last_sent_at": "2026-02-21 12:00:00",
        "last_sent_success": false,
        "error_message": "Authentication failed",
        "created_at": "2026-02-19 15:30:00"
      }
    ]
  }
}
```

---

### 3. 更新邮箱账户

**接口**: `POST /email_api.php?action=update_account`

**请求参数**:
```json
{
  "id": 1,
  "auth_code": "新的授权码（可选）",
  "is_active": true,
  "priority": 10
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "更新成功",
  "data": {
    "id": 1,
    "updated_fields": ["auth_code", "priority"]
  }
}
```

---

### 4. 删除邮箱账户

**接口**: `POST /email_api.php?action=delete_account`

**请求参数**:
```json
{
  "id": 1
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "删除成功",
  "data": {
    "deleted_id": 1
  }
}
```

---

### 5. 测试发送邮件

**接口**: `POST /email_api.php?action=test_send`

**请求参数**:
```json
{
  "account_id": 1,
  "recipient": "test@example.com"
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "测试邮件发送成功",
  "data": {
    "account_id": 1,
    "recipient": "test@example.com",
    "sent_at": "2026-02-21 14:35:00"
  }
}
```

**错误响应**:
```json
{
  "success": false,
  "message": "发送失败",
  "error": "SMTP authentication failed",
  "data": {
    "account_id": 1,
    "error_detail": "535 Login failed"
  }
}
```

---

### 6. 获取邮件发送日志

**接口**: `GET /email_api.php?action=get_logs`

**请求参数**:
- `account_id` (可选): 筛选特定账户
- `status` (可选): 筛选状态 (sent/failed/pending)
- `limit` (可选): 返回数量，默认20
- `offset` (可选): 偏移量

**响应示例**:
```json
{
  "success": true,
  "message": "获取成功",
  "data": {
    "total": 50,
    "logs": [
      {
        "id": 100,
        "account_id": 1,
        "account_email": "123456789@qq.com",
        "recipient": "user@example.com",
        "subject": "保质期预警通知",
        "status": "sent",
        "sent_at": "2026-02-21 14:30:00"
      }
    ]
  }
}
```

---

### 7. 发送预警邮件（轮换调用）

**接口**: `POST /email_api.php?action=send_warning`

**请求参数**:
```json
{
  "recipient": "user@example.com",
  "subject": "保质期预警通知",
  "body": "<html>邮件内容...</html>"
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "邮件发送成功",
  "data": {
    "log_id": 101,
    "account_id": 2,
    "account_email": "987654321@qq.com",
    "recipient": "user@example.com",
    "sent_at": "2026-02-21 14:40:00"
  }
}
```

---

## 轮换算法设计

### 算法策略：智能加权轮换 (Smart Weighted Round Robin)

#### 核心原则

1. **优先使用高优先级账户**
2. **平衡发送次数**
3. **规避失败账户**
4. **时间间隔控制**

#### 算法伪代码

```php
function selectNextAccount() {
    $conn = getDBConnection();
    
    // 1. 获取所有启用的账户
    $sql = "SELECT id, email_address, priority, send_count, 
                   last_sent_at, last_sent_success, error_message
            FROM email_accounts 
            WHERE is_active = 1
            ORDER BY priority DESC, send_count ASC, last_sent_at ASC";
    
    $result = $conn->query($sql);
    $accounts = [];
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
    
    if (empty($accounts)) {
        return ['success' => false, 'error' => '没有可用的邮箱账户'];
    }
    
    // 2. 过滤掉最近失败的账户
    $validAccounts = array_filter($accounts, function($acc) {
        // 如果最后发送失败且在5分钟内，跳过
        if ($acc['last_sent_success'] === false && $acc['last_sent_at']) {
            $lastSent = strtotime($acc['last_sent_at']);
            if (time() - $lastSent < 300) { // 5分钟冷却期
                return false;
            }
        }
        return true;
    });
    
    // 如果所有账户都在冷却期，选择优先级最高的
    if (empty($validAccounts)) {
        $validAccounts = $accounts;
    }
    
    // 3. 计算权重
    $maxCount = max(array_column($validAccounts, 'send_count'));
    foreach ($validAccounts as &$acc) {
        // 权重 = 基础权重 + 优先级加成 - 发送次数惩罚
        $acc['weight'] = 100 + ($acc['priority'] * 10) - ($acc['send_count'] * 2);
    }
    
    // 4. 按权重排序，选择权重最高的
    usort($validAccounts, function($a, $b) {
        return $b['weight'] - $a['weight'];
    });
    
    return $validAccounts[0];
}
```

#### 轮换规则

| 规则 | 说明 |
|------|------|
| 优先级优先 | priority高的账户优先被选择 |
| 次数平衡 | send_count少的账户优先 |
| 时间间隔 | 优先选择长时间未使用的账户 |
| 失败冷却 | 发送失败的账户5分钟内不使用 |
| 均匀分布 | 多个账户优先级相同时轮换 |

---

## 后台管理界面

### 菜单结构 (admin.php)

在 `admin.php` 的左侧菜单添加：

```php
<div class="menu-item" data-page="email">
    <i class="icon">📧</i>
    <span>邮箱管理</span>
</div>
```

### 页面功能设计

#### 1. 邮箱账户列表页面

**位置**: `admin.php?page=email`

**UI元素**:
- 页面标题: "邮箱账户管理"
- 添加账户按钮: "+ 添加邮箱"
- 账户列表表格:
  - 列: QQ号、邮箱地址、状态、发送次数、最后发送、操作
- 操作按钮:
  - 编辑 (修改授权码、优先级)
  - 启用/禁用 (切换状态)
  - 测试发送 (发送测试邮件)
  - 删除 (删除账户)

**状态标识**:
- ✅ 启用: 绿色
- ❌ 禁用: 灰色
- ⚠️ 最近失败: 橙色

#### 2. 添加/编辑账户模态框

**字段**:
- QQ号 (仅添加时显示，自动填充邮箱)
- 授权码 (密码输入框)
- 优先级 (数字输入框，默认0)
- 状态 (启用/禁用开关)

**验证规则**:
- QQ号: 必填，5-12位数字
- 授权码: 必填，16位字符

#### 3. 测试发送模态框

**字段**:
- 测试目标邮箱 (默认填写管理员邮箱)
- 测试内容 (固定内容: "这是一封来自保质期管理系统的测试邮件")

**反馈**:
- 发送中: 显示加载动画
- 成功: 绿色勾选 + "测试邮件已发送"
- 失败: 红色叉号 + 错误信息

---

## 安全考虑

### 1. 授权码加密存储

**加密方法**: OpenSSL AES-256-CBC

```php
/**
 * 加密授权码
 */
function encryptAuthCode($authCode) {
    $key = hash('sha256', AUTH_ENCRYPTION_KEY, true);
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($authCode, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * 解密授权码
 */
function decryptAuthCode($encrypted) {
    $key = hash('sha256', AUTH_ENCRYPTION_KEY, true);
    $data = base64_decode($encrypted);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
}
```

**密钥管理**:
- 加密密钥存储在 `config.php` 中
- 权限设置为 600 (仅所有者可读写)
- 建议使用环境变量或专用密钥管理服务

### 2. 防止授权码泄露

**措施**:
- ❌ 日志中不记录授权码
- ❌ API响应中不返回授权码
- ❌ 前端不显示授权码（仅显示 ****）
- ✅ 更新时重新加密
- ✅ 数据库连接使用SSL

### 3. 权限控制

**访问控制**:
- 只有登录的管理员才能访问邮箱配置
- API接口检查 `$_SESSION['user_id']`
- 敏感操作记录操作日志

**操作日志**:
```sql
INSERT INTO logs (user_id, action, details) 
VALUES (1, 'email_account_added', '添加邮箱: 123456789@qq.com');
```

### 4. SQL注入防护

**措施**:
- 所有数据库查询使用 Prepared Statements
- 输入验证（QQ号必须是数字）
- 输出转义

```php
$stmt = $conn->prepare("INSERT INTO email_accounts (qq_number, ...) VALUES (?, ...)");
$stmt->bind_param("s...", $qqNumber, ...);
```

---

## 实施计划

### 阶段1: 数据库和基础功能 (1-2天)

- [ ] 创建数据库表
- [ ] 实现加密/解密函数
- [ ] 创建 `email_api.php` 核心逻辑
- [ ] 实现添加/删除/列表API

### 阶段2: 轮换算法和发送功能 (1天)

- [ ] 实现轮换算法
- [ ] 集成 PHPMailer
- [ ] 实现测试发送功能
- [ ] 实现日志记录

### 阶段3: 管理界面 (1天)

- [ ] 在 `admin.php` 添加菜单
- [ ] 创建邮箱账户列表页面
- [ ] 创建添加/编辑模态框
- [ ] 创建测试发送功能

### 阶段4: 集成和测试 (1天)

- [ ] 集成到保质期预警流程
- [ ] 端到端测试
- [ ] 性能测试
- [ ] 安全测试

### 阶段5: 文档和部署 (0.5天)

- [ ] 更新用户文档
- [ ] 更新API文档
- [ ] 部署到生产环境

---

## 附录

### 文件清单

```
expiry-clean/
├── email_api.php              # 邮箱API接口
├── email_functions.php        # 邮箱核心函数库
├── admin.php                  # 管理后台（修改）
├── database.sql               # 数据库结构（修改）
└── docs/
    └── email-config-design.md # 本设计文档
```

### 依赖项

- PHP >= 7.4
- MySQL >= 5.7
- PHPMailer >= 6.0
- OpenSSL extension

### 配置常量 (config.php)

```php
// 邮箱配置
define('SMTP_HOST', 'smtp.qq.com');
define('SMTP_ENCRYPTION', 'ssl');
define('SMTP_PORT', 465);

// 授权码加密密钥（32位随机字符串）
define('AUTH_ENCRYPTION_KEY', 'your-32-character-encryption-key');

// 默认测试邮箱
define('DEFAULT_TEST_EMAIL', 'admin@example.com');
```

---

**文档状态**: ✅ 设计完成，等待评审
**下一步**: 开发实施
