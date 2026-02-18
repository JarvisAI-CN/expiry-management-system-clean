#!/bin/bash
# ========================================
# 保质期管理系统 - API功能升级脚本
# 版本: v2.9.0 (API接口版本)
# ========================================

set -e  # 遇到错误立即退出

PROJECT_DIR="/home/ubuntu/.openclaw/workspace/PARA/Archives/保质期管理系统"
DB_BACKUP_DIR="/home/ubuntu/123pan/备份/保质期系统"

echo "========================================"
echo "保质期管理系统 - API功能升级"
echo "========================================"
echo ""

# 检查项目目录是否存在
if [ ! -d "$PROJECT_DIR" ]; then
    echo "❌ 错误: 项目目录不存在: $PROJECT_DIR"
    exit 1
fi

cd "$PROJECT_DIR"

# ========================================
# 1. 备份数据库
# ========================================
echo "📦 步骤1: 备份现有数据库..."
echo ""

# 创建备份目录
mkdir -p "$DB_BACKUP_DIR"

# 获取当前时间戳
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# 备份配置文件
if [ -f "config.php" ]; then
    cp config.php "$DB_BACKUP_DIR/config_$TIMESTAMP.php"
    echo "✅ 配置文件已备份"
fi

# 导出数据库
if [ -f "config.php" ]; then
    # 从config.php提取数据库配置（需要手动操作）
    echo "⚠️  请手动备份数据库，或运行以下命令："
    echo "   mysqldump -u用户名 -p密码 数据库名 > $DB_BACKUP_DIR/database_$TIMESTAMP.sql"
    echo ""
fi

# ========================================
# 2. 执行数据库升级
# ========================================
echo "📊 步骤2: 升级数据库结构..."
echo ""

# 检查config.php是否存在
if [ ! -f "config.php" ]; then
    echo "⚠️  警告: config.php不存在，请先完成系统安装"
    echo "   访问您的域名并运行 install.php"
    echo ""
else
    echo "正在执行API表创建SQL..."
    # 需要MySQL连接信息，这里提供手动执行指令
    echo "请在数据库中执行: api_keys.sql"
    echo "或者使用MySQL命令行:"
    echo "   mysql -u用户名 -p密码 数据库名 < api_keys.sql"
    echo ""
fi

# ========================================
# 3. 验证新文件
# ========================================
echo "📁 步骤3: 验证新增文件..."
echo ""

NEW_FILES=(
    "api.php"
    "api_key_manager.php"
    "api_keys.php"
    "api_keys.sql"
    "../scripts/expiry_api_client.py"
)

for file in "${NEW_FILES[@]}"; do
    if [ -f "$file" ]; then
        echo "✅ $file"
    else
        echo "❌ $file (缺失)"
    fi
done

echo ""

# ========================================
# 4. 设置权限
# ========================================
echo "🔐 步骤4: 设置文件权限..."
echo ""

# 设置PHP文件权限
find . -type f -name "*.php" -exec chmod 644 {} \;
echo "✅ PHP文件权限已设置为644"

# 设置目录权限
find . -type d -exec chmod 755 {} \;
echo "✅ 目录权限已设置为755"

echo ""

# ========================================
# 5. 添加管理入口链接
# ========================================
echo "🔗 步骤5: 更新管理后台菜单..."
echo ""

echo "请手动编辑 admin.php 或 dashboard.php"
echo "添加API管理入口:"
echo ""
echo '<a href="api_keys.php" class="btn btn-primary">'
echo '  <i class="bi bi-key"></i> API密钥管理'
echo '</a>'
echo ""

# ========================================
# 6. Python客户端设置
# ========================================
echo "🐍 步骤6: 设置Python API客户端..."
echo ""

PYTHON_SCRIPT="../scripts/expiry_api_client.py"

if [ -f "$PYTHON_SCRIPT" ]; then
    chmod +x "$PYTHON_SCRIPT"
    echo "✅ Python客户端已设置可执行权限"
    echo ""
    echo "使用方法:"
    echo "   python3 $PYTHON_SCRIPT http://你的域名 YOUR_API_KEY"
    echo ""
else
    echo "⚠️  Python客户端未找到"
fi

# ========================================
# 完成
# ========================================
echo "========================================"
echo "✅ 升级准备完成！"
echo "========================================"
echo ""
echo "📋 后续步骤:"
echo ""
echo "1️⃣  执行数据库升级:"
echo "   mysql -u用户名 -p 数据库名 < api_keys.sql"
echo ""
echo "2️⃣  访问API管理页面:"
echo "   http://你的域名/api_keys.php"
echo ""
echo "3️⃣  创建第一个API密钥"
echo ""
echo "4️⃣  测试API连接:"
echo "   python3 ../scripts/expiry_api_client.py http://你的域名 YOUR_API_KEY"
echo ""
echo "5️⃣  给贾维斯API密钥，让他开始工作！"
echo ""
echo "========================================"
