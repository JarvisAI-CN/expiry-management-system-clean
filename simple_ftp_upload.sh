#!/bin/bash
# 星巴克门店智能效期管理系统 - 简单 FTP 上传脚本

FTP_SERVER="211.154.19.189"
FTP_USER="pandian"
FTP_PASS="pandian"
LOCAL_DIR=$(pwd)

echo "正在准备上传文件..."

# 创建目录
echo "1. 创建服务器目录结构..."
mkdir -p /tmp/ftp_upload_temp
cd /tmp
mkdir -p ftp_upload_temp

# 复制项目文件到临时目录
cp -r $LOCAL_DIR/* /tmp/ftp_upload_temp/

# 使用 lftp 上传
echo "2. 正在上传文件..."

lftp <<EOF
open $FTP_SERVER
user $FTP_USER $FTP_PASS
mkdir -p config core includes admin logs public public/uploads
cd /
put -O / /tmp/ftp_upload_temp/install.php
put -O / /tmp/ftp_upload_temp/login.php
put -O / /tmp/ftp_upload_temp/dashboard.php
put -O / /tmp/ftp_upload_temp/index.php
put -O / /tmp/ftp_upload_temp/stocktake.php
put -O /config/ /tmp/ftp_upload_temp/config/database.php
put -O /core/ /tmp/ftp_upload_temp/core/Database.php
put -O /core/ /tmp/ftp_upload_temp/core/AuthService.php
put -O /core/ /tmp/ftp_upload_temp/core/EmailService.php
put -O /core/ /tmp/ftp_upload_temp/core/ImportService.php
put -O /core/ /tmp/ftp_upload_temp/core/AIService.php
put -O /includes/ /tmp/ftp_upload_temp/includes/header.php
put -O /includes/ /tmp/ftp_upload_temp/includes/footer.php
put -O /includes/ /tmp/ftp_upload_temp/includes/functions.php
put -O /admin/ /tmp/ftp_upload_temp/admin/ai_config.php
put -O /admin/ /tmp/ftp_upload_temp/admin/email_config.php
put -O /admin/ /tmp/ftp_upload_temp/admin/categories.php
put -O /admin/ /tmp/ftp_upload_temp/admin/products.php
put -O /admin/ /tmp/ftp_upload_temp/admin/import_todo.php
put -O / /tmp/ftp_upload_temp/schema.sql
put -O / /tmp/ftp_upload_temp/README.md
chmod 755 config core includes admin logs public
chmod 777 logs public/uploads
bye
EOF

if [ $? -eq 0 ]; then
    echo "✅ 文件上传成功！"
    echo "📦 项目已成功上传到服务器 211.154.19.189"
    echo "🏠 远程目录：/ (根目录)"
    echo "🌐 访问地址：http://pandian.dhmip.cn"
    echo "🔧 安装地址：http://pandian.dhmip.cn/install.php"
else
    echo "❌ 文件上传失败！"
    echo "💡 请检查网络连接和服务器状态"
fi

# 清理临时目录
rm -rf /tmp/ftp_upload_temp
