#!/bin/bash
# 星巴克门店智能效期管理系统 - FTP 剩余文件上传脚本

FTP_SERVER="211.154.19.189"
FTP_USER="pandian"
FTP_PASS="pandian"
LOCAL_DIR=$(pwd)
LOG_FILE="ftp_remaining.log"

# 清理旧日志
rm -f $LOG_FILE
touch $LOG_FILE

echo "开始上传剩余文件到 $FTP_SERVER..." >> $LOG_FILE

# 创建临时命令脚本
TEMP_CMD=$(mktemp /tmp/ftp_remaining.XXXXXX)

# 生成 FTP 命令序列
cat <<EOF > $TEMP_CMD
open $FTP_SERVER
user $FTP_USER $FTP_PASS
binary

# 上传 config 目录文件
cd config
put $LOCAL_DIR/config/database.php
cd ..

# 上传 core 目录文件
cd core
put $LOCAL_DIR/core/Database.php
put $LOCAL_DIR/core/AuthService.php
put $LOCAL_DIR/core/EmailService.php
put $LOCAL_DIR/core/ImportService.php
put $LOCAL_DIR/core/AIService.php
cd ..

# 上传 includes 目录文件
cd includes
put $LOCAL_DIR/includes/header.php
put $LOCAL_DIR/includes/footer.php
put $LOCAL_DIR/includes/functions.php
cd ..

# 上传 admin 目录文件
cd admin
put $LOCAL_DIR/admin/ai_config.php
put $LOCAL_DIR/admin/email_config.php
put $LOCAL_DIR/admin/categories.php
put $LOCAL_DIR/admin/products.php
put $LOCAL_DIR/admin/import_todo.php
cd ..

# 显示目录列表以验证
pwd
ls -la config
ls -la core
ls -la includes
ls -la admin

quit
EOF

echo "FTP 命令文件已生成: $TEMP_CMD"

# 执行 FTP 命令
ftp -n < $TEMP_CMD >> $LOG_FILE 2>&1

if [ $? -eq 0 ]; then
    echo "✅ 剩余文件上传成功！"
    echo "📦 项目已成功上传到服务器 $FTP_SERVER"
    echo "🏠 远程目录：/ (根目录)"
    echo "🌐 访问地址：http://pandian.dhmip.cn"
    echo "🔧 安装地址：http://pandian.dhmip.cn/install.php"
    echo "📄 上传日志已保存到：$LOG_FILE"
else
    echo "❌ 文件上传失败！"
    echo "💡 详细信息请查看：$LOG_FILE"
    echo "🔍 检查日志内容：cat $LOG_FILE"
fi

# 清理临时文件
rm -f $TEMP_CMD
