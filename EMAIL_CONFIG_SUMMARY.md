# 邮箱账户配置功能 - 实现总结

## 版本信息
- **功能版本**: v2.14.0
- **实现日期**: 2026-02-21
- **实现者**: Agent B (Subagent)

---

## 一、修改的文件清单

### 1. install.php（已修改）
**修改内容**:
- 在数据库表创建SQL中添加 `email_accounts` 表
- 在数据库表创建SQL中添加 `email_logs` 表
- 在默认设置中添加邮箱SMTP配置参数
- 生成随机 `EMAIL_ENCRYPTION_KEY` 并写入config.php

**关键代码**:
```php
// 生成32字节十六进制加密密钥
$encryptionKey = bin2hex(random_bytes(16));

// 添加到config.php
define('EMAIL_ENCRYPTION_KEY', '$encryptionKey');
```

### 2. email_functions.php（已修改）
**修改内容**:
- 修改密钥读取逻辑，优先使用config.php中的EMAIL_ENCRYPTION_KEY
- 如果config.php中没有定义，使用默认密钥（仅兼容性考虑）

**关键代码**:
```php
if (defined('EMAIL_ENCRYPTION_KEY')) {
    define('AUTH_ENCRYPTION_KEY', EMAIL_ENCRYPTION_KEY);
} else {
    define('AUTH_ENCRYPTION_KEY', 'expiry-system-email-key-2026');
}
```

### 3. admin.php（已修改）
**修改内容**:
- 添加"邮箱配置"导航菜单项
- 添加邮箱账户管理页面（tab-email）
- 实现邮箱列表展示（状态、优先级、发送次数等）
- 添加统计卡片（总账户数、启用账户、总发送数、系统状态）
- 实现JavaScript函数：
  - `loadEmailAccounts()` - 加载邮箱列表
  - `addEmailAccount()` - 添加邮箱账户
  - `toggleEmail()` - 切换启用/禁用
  - `deleteEmail()` - 删除账户
  - `testEmail()` - 测试发送邮件

---

## 二、新增的文件

### 1. upgrade_to_v2.14.php（新增）
**用途**: 为已安装系统升级数据库

**功能**:
- 创建 `email_accounts` 表
- 创建 `email_logs` 表
- 添加邮箱相关系统设置
- 在config.php中添加 `EMAIL_ENCRYPTION_KEY`
- 更新版本号到v2.14.0

**使用方法**: 访问 `upgrade_to_v2.14.php`，按提示操作

### 2. smtp_mailer.php（新增）
**用途**: 简化的SMTP邮件发送接口

**导出函数**:
```php
// 发送单封邮件（自动轮换账户）
sendSmtpEmail($recipient, $subject, $body, $accountId = null)

// 批量发送邮件（自动轮换）
sendBulkSmtpEmail($recipients, $subject, $body)

// 发送保质期预警邮件
sendExpiryAlert($expiringProducts, $recipient)

// 检查邮箱配置状态
checkEmailConfig()
```

---

## 三、已有文件（无需修改）

### 1. email_accounts.sql（已存在）
数据库表结构定义文件，包含email_accounts和email_logs表的创建SQL。

### 2. email_api.php（已存在）
完整的邮箱管理API接口，包含：
- `add_account` - 添加邮箱账户
- `list_accounts` - 列出邮箱账户
- `update_account` - 更新邮箱账户
- `delete_account` - 删除邮箱账户
- `test_send` - 测试发送邮件
- `get_logs` - 获取邮件发送日志
- `send_warning` - 发送预警邮件（单封）
- `send_warning_batch` - 批量发送预警邮件

### 3. email_functions.php（已存在）
邮箱功能核心函数库，包含：
- 加密/解密函数（AES-256-CBC）
- 账户管理函数
- 智能轮换算法（selectNextAccount）
- 邮件发送函数（sendEmail）
- 日志查询函数（getEmailLogs）

---

## 四、依赖影响分析

### 4.1 对现有功能的影响
✅ **无破坏性影响** - 所有修改都是新增功能，不影响现有系统

### 4.2 数据库影响
- 新增2个表：`email_accounts`、`email_logs`
- 新增4个系统设置：`email_smtp_host`、`email_smtp_port`、`email_smtp_encryption`、`email_cooldown_seconds`
- 修改1个配置文件：config.php（添加EMAIL_ENCRYPTION_KEY常量）

### 4.3 依赖项
**新增依赖**:
- **PHPMailer** - 用于发送SMTP邮件
  - 安装命令: `composer require phpmailer/phpmailer`
  - 如果未安装，系统会提示用户安装

---

## 五、安全设计

### 5.1 授权码加密
- 使用 **AES-256-CBC** 加密算法
- 密钥从 config.php 读取（EMAIL_ENCRYPTION_KEY）
- IV向量每次随机生成，存储在加密数据前16字节
- 授权码永不在前端显示

### 5.2 权限控制
- 所有API接口需要登录验证（`$_SESSION['user_id']`）
- 只有登录的管理员才能访问邮箱配置功能

### 5.3 SQL注入防护
- 所有数据库查询使用预处理语句（prepared statements）
- 参数化查询，杜绝SQL注入

---

## 六、智能轮换算法

### 6.1 算法策略
**选择逻辑**:
1. 过滤出所有启用的邮箱账户（is_active=1）
2. 跳过冷却期内的失败账户（默认5分钟）
3. 按优先级、发送次数、最后使用时间计算权重
4. 选择权重最高的账户

**权重公式**:
```
权重 = 100 + (优先级 × 10) - (发送次数 × 2) + 距离上次使用的小时数(最多+24)
```

### 6.2 失败重试机制
- 发送失败的账户会进入冷却期（默认300秒）
- 冷却期内该账户不会被选中
- 冷却期结束后自动恢复

### 6.3 负载均衡
- 优先使用发送次数少的账户
- 优先使用很久没用的账户
- 支持手动设置优先级

---

## 七、测试建议

### 7.1 数据库测试
```bash
# 1. 检查表是否创建成功
mysql -u root -p expiry_system -e "SHOW TABLES LIKE 'email%';"

# 2. 检查表结构
mysql -u root -p expiry_system -e "DESC email_accounts;"

# 3. 检查系统设置
mysql -u root -p expiry_system -e "SELECT * FROM settings WHERE s_key LIKE 'email%';"
```

### 7.2 功能测试步骤

#### 测试1: 添加邮箱账户
1. 登录管理后台
2. 进入"邮箱配置"页面
3. 点击"添加邮箱"
4. 输入QQ号和授权码
5. 验证是否成功添加到列表

#### 测试2: 测试发送邮件
1. 在邮箱列表中点击"测试发送"按钮
2. 输入测试收件人邮箱
3. 验证是否收到测试邮件

#### 测试3: 轮换算法验证
1. 添加3个不同优先级的邮箱账户
2. 连续发送10封邮件
3. 检查每个账户的发送次数
4. 验证是否按照预期轮换

#### 测试4: 授权码加密验证
```bash
# 查看数据库，确认auth_code_encrypted字段是加密后的乱码
mysql -u root -p expiry_system -e "SELECT qq_number, LEFT(auth_code_encrypted, 50) FROM email_accounts;"
```

#### 测试5: 禁用/启用功能
1. 禁用一个账户
2. 尝试发送邮件，验证该账户不会被使用
3. 重新启用该账户
4. 验证恢复正常

### 7.3 性能测试
- 测试添加100个邮箱账户的性能
- 测试批量发送100封邮件的速度
- 验证轮换算法在高并发下的表现

---

## 八、升级指南

### 8.1 新安装用户
直接访问 `install.php` 进行安装，邮箱功能会自动包含。

### 8.2 已安装用户升级
1. **备份数据库**（重要！）
   ```bash
   mysqldump -u root -p expiry_system > backup_$(date +%Y%m%d).sql
   ```

2. **运行升级脚本**
   - 访问 `http://你的域名/upgrade_to_v2.14.php`
   - 按页面提示操作
   - 等待升级完成

3. **安装PHPMailer**（如果未安装）
   ```bash
   cd /path/to/expiry-clean
   composer require phpmailer/phpmailer
   ```

4. **验证升级**
   - 登录后台，查看是否有"邮箱配置"菜单
   - 访问邮箱配置页面，验证功能正常

5. **清理**
   - 升级成功后，删除 `upgrade_to_v2.14.php`

---

## 九、常见问题

### Q1: 提示"PHPMailer未安装"怎么办？
**A**: 在项目目录运行：
```bash
composer require phpmailer/phpmailer
```

### Q2: 如何获取QQ邮箱授权码？
**A**: 
1. 登录QQ邮箱网页版
2. 点击"设置" → "账户"
3. 找到"POP3/IMAP/SMTP/Exchange/CardDAV/CalDAV服务"
4. 开启"SMTP服务"
5. 按提示发送短信
6. 获得16位授权码

### Q3: 测试发送失败怎么办？
**A**: 检查以下几点：
- 授权码是否正确
- QQ邮箱的SMTP服务是否已开启
- 服务器防火墙是否允许访问SMTP端口（465）
- 查看错误日志：`email_logs` 表

### Q4: 如何修改邮箱冷却时间？
**A**: 在数据库中修改设置：
```sql
UPDATE settings SET s_value = '600' WHERE s_key = 'email_cooldown_seconds';
```
单位是秒，默认300秒（5分钟）。

### Q5: 升级后可以回滚吗？
**A**: 可以，使用升级前的数据库备份：
```bash
mysql -u root -p expiry_system < backup_20260221.sql
```
然后恢复旧版本代码。

---

## 十、后续增强计划（可选）

### P2 功能
- [ ] 批量导入邮箱账户（CSV/Excel）
- [ ] 邮箱使用统计图表
- [ ] 邮件发送队列管理
- [ ] 发送失败自动重试
- [ ] 邮箱健康检查（定期测试）

### P3 功能
- [ ] 支持其他邮箱服务商（163、Gmail等）
- [ ] 邮件模板管理
- [ ] 定时发送任务
- [ ] 邮件发送速率限制

---

## 十一、技术支持

如有问题，请联系：
- **项目仓库**: https://github.com/JarvisAI-CN/expiry-clean
- **文档**: docs/email-config-design.md
- **API文档**: email_api.php（包含完整接口说明）

---

**实现完成日期**: 2026-02-21
**文档版本**: v1.0
**状态**: ✅ P0功能全部完成
