# 快速部署指南 - v2.8.3

## 📦 部署前准备

### 1. 环境要求
- ✅ PHP 7.4+
- ✅ MySQL 5.7+
- ✅ Web服务器（Nginx/Apache）
- ✅ 浏览器支持：Chrome 55+, Safari 11+, Firefox 54+

### 2. 检查当前版本
```bash
cat /path/to/expiry-clean/VERSION.txt
# 应显示: 2.8.2
```

---

## 🚀 快速升级（3步完成）

### 步骤1：备份现有文件
```bash
# 进入项目目录
cd /path/to/expiry-clean/

# 创建备份目录
mkdir -p backups/v2.8.2_$(date +%Y%m%d_%H%M%S)

# 备份核心文件
cp index.php backups/v2.8.2_$(date +%Y%m%d_%H%M%S)/
cp VERSION.txt backups/v2.8.2_$(date +%Y%m%d_%H%M%S)/

# 验证备份
ls -lh backups/v2.8.2_$(date +%Y%m%d_%H%M%S)/
```

### 步骤2：替换文件
```bash
# 方法A：直接替换（推荐用于测试环境）
# 将新版本文件直接覆盖到项目目录

# 方法B：手动替换（推荐用于生产环境）
# 1. 上传新的index.php
# 2. 上传新的VERSION.txt
# 3. 验证文件权限
chmod 644 index.php
chmod 644 VERSION.txt
```

### 步骤3：验证升级
```bash
# 检查版本号
cat VERSION.txt
# 应显示: 2.8.3

# 检查文件完整性
md5sum index.php
md5sum VERSION.txt
```

---

## 🧪 快速测试

### 1. 访问系统
```
URL: http://your-domain/index.php
```

### 2. 验证版本号
- 页面标题应显示：保质期管理 v2.8.3

### 3. 测试模糊搜索
1. 登录系统
2. 点击"新增盘点录入"
3. 在SKU输入框输入"568"
4. 等待搜索结果弹窗
5. 点击任意结果

**预期结果**：
- ✅ 显示搜索结果弹窗
- ✅ 点击后自动填充SKU

### 4. 测试手电筒（移动设备）
1. 点击"点击添加 (扫一扫)"
2. 授予摄像头权限
3. 查看右上角是否显示手电筒按钮
4. 点击按钮测试开关

**预期结果**：
- ✅ 支持torch的设备显示手电筒按钮
- ✅ 点击可以开关手电筒

---

## 🔧 回滚方案

### 如果升级失败，执行以下步骤：

```bash
# 1. 停止Web服务器（可选）
# sudo systemctl stop nginx

# 2. 恢复备份文件
cp backups/v2.8.2_$(date +%Y%m%d_%H%M%S)/index.php ./
cp backups/v2.8.2_$(date +%Y%m%d_%H%M%S)/VERSION.txt ./

# 3. 重启Web服务器（可选）
# sudo systemctl start nginx

# 4. 验证回滚
cat VERSION.txt
# 应显示: 2.8.2
```

---

## 📋 部署检查清单

### 升级前
- [ ] 已创建完整备份
- [ ] 已记录当前版本号
- [ ] 已通知系统用户
- [ ] 已选择低峰期进行升级

### 升级中
- [ ] 文件备份成功
- [ ] 文件替换成功
- [ ] 文件权限正确
- [ ] 版本号更新成功

### 升级后
- [ ] 页面可正常访问
- [ ] 用户可正常登录
- [ ] 版本号显示正确（v2.8.3）
- [ ] 模糊搜索功能正常
- [ ] 手电筒功能正常（移动设备）
- [ ] 原有功能不受影响
- [ ] 无JavaScript错误
- [ ] 无PHP错误日志

---

## 🐛 常见问题排查

### 问题1：升级后页面无法访问
**可能原因**：
- PHP语法错误
- 文件权限问题

**解决方案**：
```bash
# 检查PHP语法
php -l index.php

# 检查文件权限
ls -la index.php
# 应显示: -rw-r--r--

# 检查错误日志
tail -f /var/log/nginx/error.log
tail -f /var/log/php-fpm/error.log
```

### 问题2：搜索功能不工作
**可能原因**：
- 未登录状态
- API路径错误

**解决方案**：
```bash
# 检查浏览器控制台（F12）
# 查看是否有JavaScript错误

# 检查网络请求
# 查看API请求是否返回200状态码

# 测试API
curl -v "http://your-domain/index.php?api=fuzzy_search&q=test" \
  -H "Cookie: PHPSESSID=your_session_id"
```

### 问题3：手电筒按钮不显示
**可能原因**：
- 设备不支持torch
- 未授予摄像头权限
- 非HTTPS环境

**解决方案**：
```
# 1. 确认使用HTTPS或localhost
# 2. 确认已授予摄像头权限
# 3. 使用支持torch的设备测试
# 4. 检查浏览器控制台
```

### 问题4：版本号未更新
**可能原因**：
- 浏览器缓存

**解决方案**：
```bash
# 1. 强制刷新浏览器（Ctrl+F5）
# 2. 清除浏览器缓存
# 3. 验证服务器文件
cat VERSION.txt
```

---

## 📊 性能监控

### 1. 搜索性能监控
```javascript
// 在performFuzzySearch函数中添加
console.time('search');
const res = await fetch(...);
console.timeEnd('search');
// 预期: <500ms
```

### 2. 数据库查询监控
```sql
-- 启用慢查询日志
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 0.5;

-- 查看慢查询
SELECT * FROM mysql.slow_log 
WHERE sql_text LIKE '%fuzzy_search%' 
ORDER BY start_time DESC 
LIMIT 10;
```

---

## 🔒 安全检查

### 1. 文件权限检查
```bash
# 检查敏感文件权限
find /path/to/expiry-clean -name "*.php" -type f -exec chmod 644 {} \;
find /path/to/expiry-clean -type d -exec chmod 755 {} \;

# 验证
ls -la | grep -E "\.php$"
```

### 2. SQL注入测试
```bash
# 测试搜索API的SQL注入防护
curl "http://your-domain/index.php?api=fuzzy_search&q='OR'1'='1" \
  -H "Cookie: PHPSESSID=your_session_id"

# 预期返回：空结果或错误，不应泄露数据库信息
```

### 3. 访问控制测试
```bash
# 测试未登录访问
curl "http://your-domain/index.php?api=fuzzy_search&q=test"

# 预期返回：{"success": false, "message": "请先登录"}
```

---

## 📞 获取帮助

### 1. 查看文档
- 更新日志：`CHANGELOG_v2.8.3.md`
- 开发参考：`DEV_REFERENCE_v2.8.3.md`
- 测试清单：`TEST_CHECKLIST_v2.8.3.md`

### 2. 查看日志
```bash
# PHP错误日志
tail -f /var/log/php-fpm/error.log

# Web服务器日志
tail -f /var/log/nginx/error.log

# 应用日志（如果有）
tail -f /path/to/expiry-clean/logs/app.log
```

### 3. 联系支持
- 开发者：贾维斯 ⚡
- 项目：保质期管理系统
- 版本：v2.8.3

---

## ✅ 升级完成确认

### 最终检查
- [ ] 版本号：v2.8.3
- [ ] 模糊搜索：✅ 正常
- [ ] 手电筒控制：✅ 正常（支持的设备）
- [ ] 原有功能：✅ 正常
- [ ] 无错误日志：✅ 确认
- [ ] 性能正常：✅ 确认

### 部署签名
**部署人员**：_________  
**部署时间**：_________  
**部署环境**：_________  
**升级结果**：□ 成功  □ 失败  □ 部分成功  

**备注**：
_____________________________________
_____________________________________

---

**祝升级顺利！** 🎉

**开发者**: 贾维斯 ⚡  
**更新日期**: 2026-02-19  
**版本**: v2.8.3  
**项目**: 保质期管理系统
