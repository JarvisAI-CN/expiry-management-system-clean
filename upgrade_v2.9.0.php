<?php
/**
 * ========================================
 * 保质期管理系统 - 数据库升级脚本 v2.9.0
 * 文件名: upgrade_v2.9.0.php
 * 功能:
 *   - 为 api_keys 表增加 scopes 字段（API权限范围）
 *   - 预填充默认权限 "read:all"
 *   - 更新 VERSION.txt 到 2.9.0（如可写）
 * ========================================
 */

require_once __DIR__ . '/db.php';

/**
 * 执行 v2.9.0 升级
 *
 * @param bool $silent true = 返回数组，不输出HTML；false = 直接输出HTML（浏览器运行）
 * @return array 升级结果
 */
function run_upgrade_v2_9_0($silent = false) {
    $conn = getDBConnection();
    if (!$conn) {
        $msg = '数据库连接失败';
        if (!$silent) {
            echo "<p style='color:red'>✗ {$msg}</p>";
        }
        return ['success' => false, 'message' => $msg];
    }

    $messages = [];
    $success = true;

    try {
        // 1) 检查并新增 scopes 字段
        $res = $conn->query("SHOW COLUMNS FROM `api_keys` LIKE 'scopes'");
        if ($res && $res->num_rows > 0) {
            $messages[] = "✓ api_keys.scopes 字段已存在，跳过添加";
        } else {
            $sql = "ALTER TABLE `api_keys`
                    ADD COLUMN `scopes` VARCHAR(255) NOT NULL DEFAULT 'read:all'
                    COMMENT '权限范围（逗号分隔的scope列表）'
                    AFTER `is_active`";
            if ($conn->query($sql)) {
                $messages[] = "✓ 成功为 api_keys 表添加 scopes 字段";
            } else {
                $messages[] = "✗ 添加 scopes 字段失败: " . $conn->error;
                $success = false;
            }
        }

        // 2) 为已有记录填充默认 scope
        if ($success) {
            $sql = "UPDATE `api_keys` SET `scopes` = 'read:all' WHERE `scopes` IS NULL OR `scopes` = ''";
            if ($conn->query($sql)) {
                $messages[] = "✓ 已为现有 API 密钥填充默认 scopes = 'read:all'";
            } else {
                $messages[] = "✗ 填充默认 scopes 失败: " . $conn->error;
                $success = false;
            }
        }

        // 3) 更新 VERSION.txt
        $versionFile = __DIR__ . '/VERSION.txt';
        if (is_writable($versionFile)) {
            if (@file_put_contents($versionFile, '2.9.0') !== false) {
                $messages[] = "✓ 已将 VERSION.txt 更新为 2.9.0";
            } else {
                $messages[] = "✗ 写入 VERSION.txt 失败";
            }
        } else {
            $messages[] = "⚠ VERSION.txt 不可写，已跳过版本号更新";
        }
    } catch (Exception $e) {
        $success = false;
        $messages[] = '✗ 升级过程中发生异常: ' . $e->getMessage();
    }

    if (!$silent) {
        echo "<h2>升级到 v2.9.0</h2>";
        foreach ($messages as $m) {
            $color = strpos($m, '✗') !== false ? 'red' : (strpos($m, '⚠') !== false ? 'orange' : 'green');
            echo "<p style='color:{$color}'>" . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . "</p>";
        }
        echo '<hr>';
        echo $success
            ? "<p style='color:green'><b>升级完成！</b></p>"
            : "<p style='color:red'><b>升级失败，请检查上方错误信息。</b></p>";
        echo "<p><a href='index.php'>返回系统</a></p>";
    }

    return [
        'success' => $success,
        'messages' => $messages
    ];
}

// 如果是直接通过浏览器访问该文件，则执行升级并输出HTML
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    run_upgrade_v2_9_0(false);
}
