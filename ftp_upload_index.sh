#!/bin/bash
# 星巴克门店智能效期管理系统 - FTP 上传 index.php

FTP_SERVER="211.154.19.189"
FTP_USER="pandian"
FTP_PASS="pandian"
LOCAL_DIR=$(pwd)

echo "开始上传 index.php..."

# 使用 FTP 上传
ftp -n <<EOF
open $FTP_SERVER
user $FTP_USER $FTP_PASS
binary
put $LOCAL_DIR/index.php
chmod 644 index.php
ls -la index.php
quit
EOF

if [ $? -eq 0 ]; then
    echo "✅ index.php 上传成功！"
else
    echo "❌ index.php 上传失败！"
fi
