#!/bin/bash
# 星巴克门店智能效期管理系统 - FTP README.md 上传脚本

FTP_SERVER="211.154.19.189"
FTP_USER="pandian"
FTP_PASS="pandian"
LOCAL_DIR=$(pwd)

echo "开始上传 README.md..."

# 使用 FTP 上传
ftp -n <<EOF
open $FTP_SERVER
user $FTP_USER $FTP_PASS
binary
put $LOCAL_DIR/README.md
chmod 644 README.md
pwd
ls README.md
quit
EOF

if [ $? -eq 0 ]; then
    echo "✅ README.md 上传成功！"
    echo "📦 文件已成功上传到服务器"
    echo "🏠 路径：/README.md"
else
    echo "❌ README.md 上传失败！"
fi
