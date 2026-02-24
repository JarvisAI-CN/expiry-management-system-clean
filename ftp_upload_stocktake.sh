#!/bin/bash
# 上传 stocktake.php 文件到服务器
ftp -n <<END_FTP
open 211.154.19.189
user pandian pandian
binary
cd /
put stocktake.php
chmod 644 stocktake.php
ls -la stocktake.php
END_FTP