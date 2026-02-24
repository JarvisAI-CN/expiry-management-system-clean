#!/bin/bash
# 星巴克门店智能效期管理系统 - FTP上传脚本

# FTP服务器配置
FTP_SERVER="211.154.19.189"
FTP_USER="pandian"
FTP_PASS="pandian"
FTP_PATH="/"

# 本地项目路径
LOCAL_PATH=$(pwd)

# 日志文件
LOG_FILE="ftp_upload.log"

# 输出到日志的函数
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
    echo "$1"
}

# 检查项目是否是 git 仓库
check_project() {
    if [ ! -d ".git" ]; then
        log "❌ 不是 git 仓库，无法继续"
        return 1
    fi
    
    if [ ! -f "install.php" ]; then
        log "❌ 项目文件不完整，缺少 install.php"
        return 1
    fi
    
    log "✅ 项目检查通过"
    return 0
}

# 清理可能存在的旧日志
cleanup() {
    if [ -f "$LOG_FILE" ]; then
        rm "$LOG_FILE"
    fi
    touch "$LOG_FILE"
}

# 使用 FTP 上传文件
upload_ftp() {
    log "📤 开始连接 FTP 服务器..."
    
    # 使用 ftp 命令上传文件
    ftp -n "$FTP_SERVER" <<END_SCRIPT
user $FTP_USER $FTP_PASS
binary
cd $FTP_PATH

# 创建目录结构
mkdir config
mkdir core
mkdir includes
mkdir admin
mkdir logs
mkdir public
mkdir public/uploads

# 设置目录权限
chmod 755 config
chmod 755 core
chmod 755 includes
chmod 755 admin
chmod 755 logs
chmod 777 logs
chmod 755 public
chmod 777 public/uploads

# 上传文件
put install.php
put login.php
put dashboard.php
put index.php
put stocktake.php

put config/database.php
put core/Database.php
put core/AuthService.php
put core/EmailService.php
put core/ImportService.php
put core/AIService.php

put includes/header.php
put includes/footer.php
put includes/functions.php

put admin/ai_config.php
put admin/email_config.php
put admin/categories.php
put admin/products.php
put admin/import_todo.php

put schema.sql
put README.md

quit
END_SCRIPT

    if [ $? -eq 0 ]; then
        log "✅ FTP 上传完成！"
        return 0
    else
        log "❌ FTP 上传失败！"
        return 1
    fi
}

# 检查是否成功连接并上传
verify_upload() {
    log "🔍 验证上传结果..."
    
    # 检查是否成功上传了重要文件
    ftp -n "$FTP_SERVER" <<END_VERIFY
user $FTP_USER $FTP_PASS
pwd
ls install.php login.php config/database.php
quit
END_VERIFY
    
    if [ $? -eq 0 ]; then
        log "✅ 文件存在性验证通过！"
        return 0
    else
        log "❌ 文件存在性验证失败！"
        return 1
    fi
}

# 显示成功信息
show_success() {
    echo
    log "🎉 上传成功！"
    log "📦 文件已上传到 FTP 服务器"
    log "🏠 项目位置: ftp://${FTP_SERVER}${FTP_PATH}"
    log "💡 下一步：请访问 http://pandian.dhmip.cn/install.php 进行安装"
    log "🔑 安装程序将引导您完成数据库配置"
    log "📚 详细说明请查看 README.md 文件"
}

# 显示失败信息
show_failure() {
    echo
    log "❌ 上传失败！"
    log "📂 本地项目路径：${LOCAL_PATH}"
    log "📦 请检查："
    log "   - 网络连接是否正常"
    log "   - FTP服务器是否可达"
    log "   - 账号密码是否正确"
    log "   - 服务器空间是否足够"
    log "🔍 详细信息请查看 ftp_upload.log 文件"
}

# 主函数
main() {
    echo "=================================================="
    echo "  星巴克门店智能效期管理系统 V3.0.5 - FTP 上传"
    echo "=================================================="
    
    # 清理日志
    cleanup
    
    log "🚀 开始上传到服务器 $FTP_SERVER"
    
    # 检查项目结构
    if ! check_project; then
        return 1
    fi
    
    # 开始上传
    log "📁 项目根目录：$(pwd)"
    
    if ! upload_ftp; then
        show_failure
        return 1
    fi
    
    # 验证上传结果
    if ! verify_upload; then
        show_failure
        return 1
    fi
    
    # 显示成功信息
    show_success
    
    return 0
}

# 执行主函数
main "$@"
