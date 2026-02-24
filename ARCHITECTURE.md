# 星巴克门店智能效期管理系统 V3.0.0 - 架构设计

## 项目概述

星巴克门店智能效期管理系统是一款专为星巴克门店设计的 Web 效期管理系统，帮助门店员工高效管理库存效期，减少浪费，优化库存管理。

## 技术架构

### 后端技术
- **语言**: Native PHP 8.1+ (无框架依赖，便于部署)
- **数据库**: MySQL 8.0 (PDO 模式)
- **通信**: Guzzle (HTTP 请求)
- **邮件**: PHPMailer
- **Excel 处理**: PhpSpreadsheet
- **API**: RESTful API

### 前端技术
- **框架**: Bootstrap 5 (Mobile-First 设计，适配 iPad)
- **库**: jQuery
- **表格**: DataTables
- **图标**: Font Awesome 6.x

### 项目结构
```
expiry-clean/
├── config/                  # 配置文件目录
│   ├── database.php        # 数据库配置
│   └── system.php          # 系统配置
├── core/                   # 核心业务逻辑
│   ├── EmailService.php    # 邮件服务
│   ├── ImportService.php   # 数据导入服务
│   ├── AIService.php       # AI 服务
│   ├── AuthService.php     # 鉴权服务
│   └── Database.php        # 数据库连接
├── admin/                  # 管理员后台
│   ├── import_todo.php     # 数据导入待办
│   ├── categories.php      # 分类管理
│   ├── products.php        # 物料管理
│   ├── email_config.php    # 邮件配置
│   └── ai_config.php       # AI 配置
├── public/                 # 公共资源
│   ├── css/                # 样式文件
│   ├── js/                 # JavaScript 文件
│   ├── images/             # 图片资源
│   └── uploads/            # 上传文件目录
├── includes/               # 共享文件
│   ├── header.php          # 页面头部
│   ├── footer.php          # 页面底部
│   └── functions.php       # 公用函数
├── schema.sql              # 数据库结构
├── install.php             # 系统安装程序
├── login.php               # 登录页面
├── dashboard.php           # 首页
├── stocktake.php           # 盘点系统
└── index.php               # 入口文件
```

## 核心功能模块

### 1. 系统初始化与安装
- 自动检测环境
- 数据库配置
- 管理员设置
- 安装锁定机制

### 2. 鉴权系统
- 用户登录
- 记住我功能
- 权限验证
- 会话管理

### 3. 首页看板
- 快捷操作入口
- AI 智能简报
- 系统状态监控

### 4. 盘点系统
- 新建盘点单
- 扫码枪支持
- AI 数据分析
- 历史记录查询

### 5. 后台管理
- AI 配置中心
- 邮件集群配置
- 分类与效期规则
- 现有物料维护
- 数据导入与映射

## 数据库设计

### 用户表 (users)
```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1
);
```

### 系统配置表 (system_configs)
```sql
CREATE TABLE system_configs (
    config_key VARCHAR(100) PRIMARY KEY,
    config_value TEXT,
    config_type VARCHAR(50) DEFAULT 'string',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### 邮件账号表 (email_accounts)
```sql
CREATE TABLE email_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    qq_number VARCHAR(20) NOT NULL,
    auth_code VARCHAR(100) NOT NULL,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### 分类表 (categories)
```sql
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    early_dispose_days INT DEFAULT 0,
    shelf_remove_days INT DEFAULT 0,
    check_frequency VARCHAR(10) DEFAULT 'daily',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### 物料表 (products)
```sql
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    category_id INT,
    company_category_raw VARCHAR(200),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);
```

### 盘点会话表 (stocktake_sessions)
```sql
CREATE TABLE stocktake_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_code VARCHAR(50) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    status ENUM('draft', 'completed') DEFAULT 'draft',
    ai_analysis TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

### 盘点明细表 (stocktake_items)
```sql
CREATE TABLE stocktake_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    product_id INT NOT NULL,
    sku VARCHAR(50) NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    quantity INT DEFAULT 0,
    expiry_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES stocktake_sessions(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

## 安全设计

### 输入验证
- 所有用户输入均进行安全过滤
- 使用 prepared statements 防止 SQL 注入
- 文件上传进行严格的扩展名和 MIME 类型检查

### 会话管理
- 使用 secure cookie
- 会话超时机制
- 防 CSRF 攻击

### 权限控制
- 基于角色的访问控制
- 页面级权限验证
- API 接口权限控制

## 性能优化

### 数据库优化
- 合理的索引设计
- 查询优化
- 数据分页处理

### 前端优化
- 静态资源压缩
- 图片优化
- 代码合并

### 缓存策略
- 页面缓存
- 查询结果缓存
- 配置文件缓存

## 部署说明

### 服务器要求
- PHP 8.1+
- MySQL 8.0+
- Apache 或 Nginx 服务器
- 至少 1GB 内存
- 至少 10GB 磁盘空间

### 安装步骤
1. 上传文件到服务器
2. 设置正确的文件权限
3. 配置虚拟主机
4. 访问域名完成安装

### 环境检测
- 系统会自动检测 PHP 版本和扩展
- 检查数据库连接
- 验证文件写入权限

---

**版本**: V3.0.0  
**作者**: 资深 PHP 全栈架构师  
**创建时间**: 2026-02-24  
**更新时间**: 2026-02-24
