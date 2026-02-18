# 保质期管理系统 - 部署检查清单

## ✅ 部署前检查

- [ ] PHP 版本 >= 7.4
- [ ] MySQL 版本 >= 5.7
- [ ] Web 服务器（Nginx/Apache）
- [ ] 浏览器支持摄像头访问（推荐 Chrome/Safari）

---

## 📋 部署步骤

### 1. 数据库配置
```bash
# 1.1 导入数据库
mysql -u root -p < database.sql

# 1.2 验证数据库创建
mysql -u root -p
> USE expiry_system;
> SHOW TABLES;
> EXIT;
```

### 2. 修改数据库连接
编辑 `db.php` 文件：
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');          // 修改为你的数据库用户名
define('DB_PASS', 'your_password'); // 修改为你的数据库密码
define('DB_NAME', 'expiry_system');
```

### 3. 上传文件到服务器
```
目标目录示例：
- 宝塔面板: /www/wwwroot/your-domain.com/
- Apache: /var/www/html/expiry_system/
- Nginx: /var/www/html/expiry_system/
```

需要上传的文件：
- [ ] db.php
- [ ] index.php

### 4. 设置文件权限
```bash
chmod 644 db.php index.php
chown www-data:www-data db.php index.php  # 根据服务器配置调整
```

### 5. 访问测试
```
URL: http://your-domain.com/index.php
```

---

## 🧪 功能测试清单

### 扫码测试
- [ ] 点击"启动摄像头扫描"按钮
- [ ] 允许浏览器访问摄像头权限
- [ ] 扫描条形码/二维码成功
- [ ] SKU 自动填充到输入框

### 新商品测试
- [ ] 输入新的 SKU（如：TEST001）
- [ ] 系统提示"新商品，请输入商品名称"
- [ ] 输入商品名称
- [ ] 添加批次（到期日期 + 数量）
- [ ] 点击保存，成功提示
- [ ] 数据正确写入数据库

### 旧商品测试
- [ ] 输入已有 SKU（或扫描已录入商品）
- [ ] 系统自动填充商品名称
- [ ] 显示已有批次列表
- [ ] 批次状态正确（临期/过期标记）
- [ ] 添加新批次并保存

### 多批次测试
- [ ] 点击"+ 添加更多批次"按钮
- [ ] 新批次行正确显示
- [ ] 可以删除多余批次行
- [ ] 多个批次同时保存成功

### 移动端测试
- [ ] 使用手机浏览器访问
- [ ] 页面响应式布局正常
- [ ] 摄像头调用正常
- [ ] 触摸操作流畅

---

## 🔍 常见问题排查

### 问题 1: 扫码功能无法使用
**检查项**：
- [ ] 是否使用 HTTPS（本地 localhost 除外）
- [ ] 浏览器是否允许摄像头权限
- [ ] 尝试更换浏览器（Chrome/Safari）
- [ ] 检查浏览器控制台是否有错误

### 问题 2: 数据库连接失败
**检查项**：
- [ ] MySQL 服务是否运行
- [ ] `db.php` 配置是否正确
- [ ] 数据库用户是否有权限
- [ ] 数据库 `expiry_system` 是否存在

### 问题 3: 保存失败
**检查项**：
- [ ] SKU 和商品名称是否为空
- [ ] 是否至少添加一个批次
- [ ] 批次日期和数量是否有效
- [ ] 检查 PHP 错误日志

---

## 📊 验证数据库数据

```sql
-- 查看所有商品
SELECT * FROM products;

-- 查看所有批次
SELECT * FROM batches;

-- 查看商品及其批次（关联查询）
SELECT 
    p.sku, 
    p.name, 
    b.expiry_date, 
    b.quantity,
    DATEDIFF(b.expiry_date, CURDATE()) as days_to_expiry
FROM products p
LEFT JOIN batches b ON p.id = b.product_id
ORDER BY p.id, b.expiry_date;

-- 查找即将过期的商品（30天内）
SELECT 
    p.sku, 
    p.name, 
    b.expiry_date, 
    b.quantity,
    DATEDIFF(b.expiry_date, CURDATE()) as days_to_expiry
FROM products p
JOIN batches b ON p.id = b.product_id
WHERE b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
ORDER BY b.expiry_date;

-- 查找已过期的商品
SELECT 
    p.sku, 
    p.name, 
    b.expiry_date, 
    b.quantity
FROM products p
JOIN batches b ON p.id = b.product_id
WHERE b.expiry_date < CURDATE()
ORDER BY b.expiry_date;
```

---

## 🔒 安全检查（必须项 + 2.8.x 专用）

### 生产环境部署前（必须全部打勾）
- [ ] 修改 `db.php` 中数据库密码
- [ ] 关闭 PHP 错误显示（修改 `error_reporting`）
- [ ] 启用 HTTPS 证书
- [ ] 定期备份数据库

### 版本与自动升级安全（2.8.x 新增）
- [ ] `VERSION.txt` 与前端显示版本一致（例如：2.8.1）
- [ ] `index.php` / `admin.php` 中的 `APP_VERSION` 已同步更新到当前版本
- [ ] 所有「升级 / 修复」相关 API（`check_upgrade` / `execute_upgrade` / `force_repair`）只能在 **登录后** 调用（经过 `checkAuth()` 检查）
- [ ] 远程更新只使用 **HTTPS** 源（GitHub / 自建 HTTPS），不再使用 HTTP 明文 `FALLBACK_URL`
- [ ] 远程拉取的 PHP 文件有基本完整性校验（如固定 hash 或签名文件），防止被中间人篡改

### API 安全（2.8.1 新增 API 系统）
- [ ] API 密钥在数据库中按 **哈希（如 SHA256）存储** 或至少经过加密，不直接保存明文
- [ ] `api.php` 中的密钥验证逻辑与实际存储方式一致（避免“文档说哈希，代码查明文”的错配）
- [ ] API 仅通过 `Authorization: Bearer <key>` 传递密钥，**禁止在 URL 中使用 `?api_key=` 传参（避免日志泄露密钥）**
- [ ] CORS 策略限制为可信前端域名（生产环境不要使用 `Access-Control-Allow-Origin: *`）
- [ ] 所有 API 端点都有清晰的限流方案或监控（防止被暴力调用）

### SQL 安全（重点检查 2.8.x 新增功能）
- [ ] 所有数据库查询都使用 **预处理语句（prepared statements）**，不直接拼接用户输入
- [ ] 特别确认 `index.php` 中与盘点会话相关的接口（如 `submit_session`）已经使用 `prepare + bind_param`，不存在 `$sid` 直接拼接到 SQL 的情况
- [ ] 日志记录中不包含敏感字段（密码、API Key 等）

### 备份脚本示例
```bash
#!/bin/bash
# backup.sh - 数据库备份脚本
BACKUP_DIR="/path/to/backup"
DATE=$(date +%Y%m%d_%H%M%S)
mysqldump -u root -p expiry_system > $BACKUP_DIR/expiry_$DATE.sql
find $BACKUP_DIR -name "expiry_*.sql" -mtime +30 -delete
```

---

## 📱 浏览器兼容性

| 浏览器 | 版本 | 扫码支持 | 移动端 |
|--------|------|---------|--------|
| Chrome | 90+ | ✅ | ✅ |
| Safari | 14+ | ✅ | ✅ |
| Firefox | 80+ | ✅ | ⚠️ |
| Edge | 90+ | ✅ | ⚠️ |
| 微信内置浏览器 | - | ❌ | ❌ |

---

## 📞 快速命令参考

```bash
# 检查 PHP 版本
php -v

# 检查 MySQL 服务状态
sudo systemctl status mysql

# 重启 Nginx
sudo systemctl restart nginx

# 查看 PHP 错误日志
tail -f /var/log/php/error.log

# 查看 Nginx 错误日志
tail -f /var/log/nginx/error.log
```

---

**部署日期**: ___________  
**部署人**: ___________  
**测试状态**: ⬜ 通过 / ⬜ 失败

**备注**: ____________________________________________________
