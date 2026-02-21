# 邮箱配置功能 - 实施指南

**项目**: 保质期管理系统
**功能**: 邮箱配置与智能轮换发送
**预计工期**: 3-5天

---

## 📋 实施清单

### 阶段1: 数据库准备 (30分钟)

- [ ] 执行数据库迁移脚本
  ```bash
  mysql -u root -p expiry_system < email_accounts.sql
  ```
  
- [ ] 验证表创建成功
  ```sql
  SHOW TABLES LIKE 'email%';
  DESC email_accounts;
  DESC email_logs;
  ```

- [ ] 检查系统配置项
  ```sql
  SELECT * FROM settings WHERE s_key LIKE 'email%';
  ```

---

### 阶段2: 核心代码部署 (1小时)

- [ ] 复制核心文件到项目目录
  ```bash
  cp email_functions.php /path/to/expiry-clean/
  cp email_api.php /path/to/expiry-clean/
  ```

- [ ] 配置加密密钥（编辑 `config.php`）
  ```php
  // 在 config.php 中添加
  define('AUTH_ENCRYPTION_KEY', 'your-32-character-random-key-here');
  ```

- [ ] 验证文件权限
  ```bash
  chmod 644 email_functions.php
  chmod 644 email_api.php
  ```

---

### 阶段3: 安装PHPMailer (30分钟)

- [ ] 方法A: 使用Composer（推荐）
  ```bash
  cd /path/to/expiry-clean/
  composer require phpmailer/phpmailer
  ```

- [ ] 方法B: 手动下载
  1. 访问 https://github.com/PHPMailer/PHPMailer/releases
  2. 下载最新版本
  3. 解压到 `vendor/` 目录

- [ ] 验证安装
  ```php
  <?php
  require_once 'vendor/autoload.php';
  if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
      echo "PHPMailer installed successfully!";
  }
  ?>
  ```

---

### 阶段4: 集成到管理后台 (2小时)

#### 步骤4.1: 添加菜单项

编辑 `admin.php`，在左侧菜单添加：

```php
<div class="menu-item" onclick="showEmailPage()">
    <i class="icon">📧</i>
    <span>邮箱管理</span>
</div>
```

#### 步骤4.2: 集成UI代码

- [ ] 将 `docs/email_admin_ui.html` 中的代码整合到 `admin.php`
  - 可选方案A: 直接复制到 `admin.php` 底部
  - 可选方案B: 创建独立的 `email_admin.php` 并通过 iframe 包含

- [ ] 确保CSS样式兼容
  - 检查现有的 `.btn` 样式
  - 检查现有的 `.modal` 样式
  - 必要时调整CSS选择器

#### 步骤4.3: 测试页面访问

访问: `http://your-domain/admin.php?page=email`

预期结果:
- 显示邮箱管理页面
- 统计卡片正常显示
- 表格可以加载

---

### 阶段5: 功能测试 (1小时)

#### 测试5.1: 添加邮箱账户

1. 点击"+ 添加邮箱"按钮
2. 输入QQ号: `123456789`
3. 输入授权码: `your_actual_auth_code`
4. 点击保存
5. 验证: 账户出现在列表中

#### 测试5.2: 测试发送

1. 点击账户的"📧"按钮
2. 输入测试目标邮箱
3. 点击"发送测试邮件"
4. 验证: 收到测试邮件

#### 测试5.3: 轮换算法

1. 添加3个不同的QQ邮箱账户
2. 设置不同的优先级
3. 连续发送多封测试邮件
4. 验证: 账户按优先级轮换

#### 测试5.4: 错误处理

1. 添加一个错误的授权码
2. 测试发送，验证失败
3. 检查账户状态是否标记为失败
4. 验证: 失败账户进入冷却期

---

### 阶段6: 集成到预警流程 (1小时)

#### 步骤6.1: 创建预警邮件模板

创建文件 `email_templates.php`:

```php
<?php
/**
 * 邮件模板
 */

function getWarningEmailTemplate($products) {
    $productsHtml = '';
    foreach ($products as $p) {
        $productsHtml .= "<tr>
            <td>{$p['name']}</td>
            <td>{$p['expiry_date']}</td>
            <td>{$p['days_remaining']}天</td>
        </tr>";
    }
    
    return "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; }
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
            .warning { color: #dc3545; }
        </style>
    </head>
    <body>
        <h2>⚠️ 保质期预警通知</h2>
        <p>以下商品即将过期，请及时处理：</p>
        <table>
            <thead>
                <tr>
                    <th>商品名称</th>
                    <th>到期日期</th>
                    <th>剩余天数</th>
                </tr>
            </thead>
            <tbody>
                $productsHtml
            </tbody>
        </table>
        <p style='color: #666; font-size: 12px;'>
            发送时间: " . date('Y-m-d H:i:s') . "<br>
            系统名称: 保质期管理系统
        </p>
    </body>
    </html>
    ";
}
?>
```

#### 步骤6.2: 修改预警检查脚本

编辑 `check_expiry.py` 或创建新的PHP脚本:

```php
<?php
require_once 'db.php';
require_once 'email_functions.php';
require_once 'email_templates.php';

// 获取即将过期的商品
$conn = getDBConnection();
$warningDays = 7; // 提前7天预警

$sql = "SELECT p.name, b.expiry_date, 
               DATEDIFF(b.expiry_date, CURDATE()) as days_remaining
        FROM batches b
        JOIN products p ON b.product_id = p.id
        WHERE b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ORDER BY b.expiry_date ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $warningDays);
$stmt->execute();
$result = $stmt->get_result();

$expiringProducts = [];
while ($row = $result->fetch_assoc()) {
    $expiringProducts[] = $row;
}

// 如果有过期商品，发送邮件
if (!empty($expiringProducts)) {
    // 获取管理员邮箱（从settings表）
    $adminEmail = getSetting('admin_email', 'admin@example.com');
    
    // 生成邮件内容
    $subject = '保质期预警通知 - ' . count($expiringProducts) . ' 件商品即将过期';
    $body = getWarningEmailTemplate($expiringProducts);
    
    // 发送邮件（自动轮换账户）
    $result = sendEmail($conn, $adminEmail, $subject, $body);
    
    if ($result['success']) {
        echo "邮件发送成功！使用账户: " . $result['data']['account_email'] . "\n";
    } else {
        echo "邮件发送失败: " . $result['message'] . "\n";
    }
} else {
    echo "没有即将过期的商品\n";
}
?>
```

#### 步骤6.3: 配置定时任务

```bash
# 编辑crontab
crontab -e

# 添加每天早上8点检查
0 8 * * * php /path/to/expiry-clean/send_warning_email.php >> /var/log/expiry_warning.log 2>&1
```

---

### 阶段7: 安全检查 (30分钟)

- [ ] 验证授权码加密存储
  ```sql
  SELECT auth_code_encrypted FROM email_accounts LIMIT 1;
  -- 应该是Base64编码的密文
  ```

- [ ] 检查文件权限
  ```bash
  ls -la config.php  # 应该是 600 或 640
  ```

- [ ] 测试SQL注入防护
  - 添加账户时输入特殊字符
  - 验证是否被正确转义

- [ ] 验证权限控制
  - 未登录访问 `email_api.php`
  - 应该返回401错误

---

### 阶段8: 文档更新 (30分钟)

- [ ] 更新用户手册
  - 添加"如何配置邮箱"章节
  - 添加"如何获取授权码"教程

- [ ] 更新API文档
  - 添加邮箱相关API说明

- [ ] 创建故障排查文档
  - 常见错误及解决方案

---

## 🚀 部署流程

### 开发环境测试

```bash
# 1. 备份现有数据
mysqldump expiry_system > backup_$(date +%Y%m%d).sql

# 2. 执行数据库迁移
mysql -u root -p expiry_system < email_accounts.sql

# 3. 部署代码
cp email_functions.php /path/to/expiry-clean/
cp email_api.php /path/to/expiry-clean/

# 4. 安装依赖
composer install

# 5. 测试功能
php -r "require 'email_functions.php'; echo 'OK';"
```

### 生产环境部署

```bash
# 1. 停止服务（如果需要）
# systemctl stop apache2

# 2. 备份数据库
mysqldump expiry_system > prod_backup_$(date +%Y%m%d).sql

# 3. 执行数据库迁移
mysql -u root -p expiry_system < email_accounts.sql

# 4. 部署代码
scp email_functions.php user@server:/path/to/expiry-clean/
scp email_api.php user@server:/path/to/expiry-clean/

# 5. 设置权限
ssh user@server "chmod 644 /path/to/expiry-clean/email_*.php"

# 6. 重启服务
# systemctl start apache2

# 7. 验证部署
curl http://your-domain/email_api.php?action=list_accounts
```

---

## 🧪 测试用例

### 用例1: 基本功能测试

| 步骤 | 操作 | 预期结果 |
|------|------|----------|
| 1 | 访问邮箱管理页面 | 页面正常显示 |
| 2 | 点击"添加邮箱" | 弹出添加模态框 |
| 3 | 输入QQ号和授权码 | 字段验证正常 |
| 4 | 保存账户 | 账户出现在列表中 |
| 5 | 点击"测试发送" | 收到测试邮件 |

### 用例2: 轮换算法测试

| 步骤 | 操作 | 预期结果 |
|------|------|----------|
| 1 | 添加3个账户，优先级分别为10, 5, 0 | 账户按优先级排序 |
| 2 | 发送邮件 | 使用优先级10的账户 |
| 3 | 再发送2封 | 依次使用优先级5和0的账户 |
| 4 | 继续发送 | 循环使用账户 |

### 用例3: 错误处理测试

| 步骤 | 操作 | 预期结果 |
|------|------|----------|
| 1 | 添加重复QQ号 | 提示"QQ号已存在" |
| 2 | 添加错误授权码 | 账户创建成功，但发送失败 |
| 3 | 检查账户状态 | 标记为失败，显示错误信息 |
| 4 | 等待5分钟后重试 | 账户恢复正常可用 |

---

## 📊 验收标准

### 功能完整性

- ✅ 支持添加多个QQ邮箱账户
- ✅ 支持启用/禁用账户
- ✅ 支持设置优先级
- ✅ 智能轮换发送邮件
- ✅ 记录发送日志
- ✅ 测试发送功能
- ✅ 错误处理和重试

### 性能要求

- ✅ 发送单封邮件 < 5秒
- ✅ 加载账户列表 < 1秒
- ✅ 轮换算法响应时间 < 100ms

### 安全要求

- ✅ 授权码加密存储
- ✅ SQL注入防护
- ✅ 权限验证
- ✅ 日志审计

---

## 🔧 故障排查

### 问题1: 邮件发送失败

**可能原因**:
- 授权码错误
- 网络连接问题
- SMTP服务器限制

**排查步骤**:
```bash
# 1. 检查PHP错误日志
tail -f /var/log/apache2/error.log

# 2. 测试SMTP连接
telnet smtp.qq.com 465

# 3. 验证授权码
# 登录QQ邮箱 → 设置 → 账户 → 生成授权码
```

### 问题2: 页面无法加载

**可能原因**:
- 文件路径错误
- 权限问题
- PHP语法错误

**排查步骤**:
```bash
# 1. 检查文件是否存在
ls -la email_api.php

# 2. 检查PHP语法
php -l email_api.php

# 3. 检查Apache错误日志
tail -f /var/log/apache2/error.log
```

### 问题3: 轮换不生效

**可能原因**:
- 所有账户都被禁用
- 所有账户都在冷却期
- 数据库查询错误

**排查步骤**:
```sql
-- 1. 检查账户状态
SELECT * FROM email_accounts WHERE is_active = 1;

-- 2. 检查最近失败记录
SELECT * FROM email_logs WHERE status = 'failed' ORDER BY created_at DESC LIMIT 10;
```

---

## 📞 支持

如有问题，请联系开发团队或查看文档:

- 设计文档: `docs/email-config-design.md`
- API文档: `docs/email-config-design.md` (API接口设计章节)
- UI设计: `docs/email_admin_ui.html`

---

**祝部署顺利！** 🎉
