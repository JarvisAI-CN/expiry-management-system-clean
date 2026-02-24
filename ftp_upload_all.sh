#!/bin/bash
# 星巴克门店智能效期管理系统 - FTP 上传脚本

FTP_SERVER="211.154.19.189"
FTP_USER="pandian"
FTP_PASS="pandian"
LOCAL_DIR=$(pwd)

echo "开始上传所有修改后的文件..."

# 确保使用主动模式连接 FTP 服务器
ftp -A -n <<EOF
open $FTP_SERVER
user $FTP_USER $FTP_PASS
mkdir core
mkdir migrations
chmod 755 core
chmod 755 migrations
quit
EOF

# 上传核心文件
ftp -A -n <<EOF
open $FTP_SERVER
user $FTP_USER $FTP_PASS
binary
cd core
put $LOCAL_DIR/core/MigrationManager.php MigrationManager.php
chmod 644 MigrationManager.php
quit
EOF

ftp -A -n <<EOF
open $FTP_SERVER
user $FTP_USER $FTP_PASS
binary
cd migrations
put $LOCAL_DIR/migrations/0001_initial_schema.php 0001_initial_schema.php
chmod 644 0001_initial_schema.php
quit
EOF

ftp -A -n <<EOF
open $FTP_SERVER
user $FTP_USER $FTP_PASS
binary
put $LOCAL_DIR/install.php
chmod 644 install.php
put $LOCAL_DIR/index.php
chmod 644 index.php
put $LOCAL_DIR/dashboard.php
chmod 644 dashboard.php
quit
EOF

echo "✅ 所有文件上传完成！"
echo "📦 上传了以下文件："
echo "   - core/MigrationManager.php"
echo "   - migrations/0001_initial_schema.php"
echo "   - install.php"
echo "   - index.php"
echo "   - dashboard.php"
echo ""
echo "🚀 数据库迁移系统已成功部署！"
