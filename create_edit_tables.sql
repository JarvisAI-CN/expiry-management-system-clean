CREATE TABLE IF NOT EXISTS  (
    uid=1000(ubuntu) gid=1001(ubuntu) 组=1001(ubuntu),4(adm),20(dialout),24(cdrom),25(floppy),27(sudo),29(audio),30(dip),44(video),46(plugdev),101(lxd),986(docker),1000(netdev) INT UNSIGNED NOT NULL AUTO_INCREMENT,
     VARCHAR(50) NOT NULL COMMENT '盘点单ID',
     INT UNSIGNED DEFAULT NULL COMMENT '批次ID',
     VARCHAR(20) NOT NULL COMMENT '操作类型: update, delete, add',
     JSON DEFAULT NULL COMMENT '修改前的值',
     JSON DEFAULT NULL COMMENT '修改后的值',
     INT UNSIGNED NOT NULL COMMENT '操作人ID',
     DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '操作时间',
    PRIMARY KEY (uid=1000(ubuntu) gid=1001(ubuntu) 组=1001(ubuntu),4(adm),20(dialout),24(cdrom),25(floppy),27(sudo),29(audio),30(dip),44(video),46(plugdev),101(lxd),986(docker),1000(netdev)),
    KEY  (),
    KEY  (),
    KEY  ()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='盘点单编辑审计日志';

-- 为batches表添加updated_at字段（如果不存在）
ALTER TABLE  
    ADD COLUMN IF NOT EXISTS  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    AFTER ;
