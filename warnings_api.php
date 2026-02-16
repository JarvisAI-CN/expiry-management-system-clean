<?php
/**
 * ========================================
 * 保质期管理系统 - 智能预警 API
 * 文件名: warnings_api.php
 * 版本: v2.8.0-alpha
 * ========================================
 */
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Session Expired']);
    exit;
}

header('Content-Type: application/json');
$action = $_GET['api'] ?? '';
$conn = getDBConnection();

/**
 * 获取预警配置
 */
if ($action === 'get_warning_config') {
    $result = $conn->query("SELECT setting_key, setting_value, description
                           FROM system_settings
                           WHERE setting_key IN ('warning_days_level1', 'warning_days_level2',
                                                'warning_days_level3', 'low_stock_threshold',
                                                'enable_auto_tasks')");

    $config = [];
    while ($row = $result->fetch_assoc()) {
        $config[$row['setting_key']] = $row['setting_value'];
    }

    echo json_encode(['success' => true, 'config' => $config]);
    exit;
}

/**
 * 更新预警配置
 */
if ($action === 'update_warning_config') {
    $data = json_decode(file_get_contents('php://input'), true);

    foreach ($data as $key => $value) {
        $stmt = $conn->prepare("UPDATE system_settings
                               SET setting_value = ?
                               WHERE setting_key = ?");
        $stmt->bind_param("ss", $value, $key);
        $stmt->execute();
    }

    echo json_encode(['success' => true, 'message' => '预警配置已更新']);
    exit;
}

/**
 * 扫描并生成预警
 */
if ($action === 'scan_warnings') {
    // 获取预警配置
    $config = [];
    $result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $result->fetch_assoc()) {
        $config[$row['setting_key']] = $row['setting_value'];
    }

    $level1_days = intval($config['warning_days_level1'] ?? 7);
    $level2_days = intval($config['warning_days_level2'] ?? 15);
    $level3_days = intval($config['warning_days_level3'] ?? 30);
    $low_stock_threshold = intval($config['low_stock_threshold'] ?? 10);

    $warnings_generated = 0;

    // 1. 扫描过期预警
    $sql = "SELECT p.id, p.sku, p.name, p.removal_buffer,
                   b.id as batch_id, b.expiry_date, b.quantity,
                   DATEDIFF(b.expiry_date, CURDATE()) as days_to_expiry
            FROM products p
            JOIN batches b ON p.id = b.product_id
            WHERE b.expiry_date > CURDATE()
            ORDER BY days_to_expiry ASC";

    $result = $conn->query($sql);

    while ($row = $result->fetch_assoc()) {
        $days = intval($row['days_to_expiry']);
        $level = null;
        $message = null;

        // 确定预警级别
        if ($days <= $level1_days) {
            $level = 'critical';
            $message = "严重预警：{$row['name']} (SKU: {$row['sku']}) 将在 {$days} 天后过期";
        } elseif ($days <= $level2_days) {
            $level = 'warning';
            $message = "警告：{$row['name']} (SKU: {$row['sku']}) 将在 {$days} 天后过期";
        } elseif ($days <= $level3_days) {
            $level = 'reminder';
            $message = "提醒：{$row['name']} (SKU: {$row['sku']}) 将在 {$days} 天后过期";
        }

        if ($level && $message) {
            // 检查是否已存在相同预警
            $check = $conn->prepare("SELECT id FROM warning_logs
                                    WHERE product_id = ? AND batch_id = ?
                                    AND warning_level = ? AND warning_type = 'expiry'
                                    AND is_resolved = 0
                                    AND DATE(created_at) = CURDATE()");
            $check->bind_param("iis", $row['id'], $row['batch_id'], $level);
            $check->execute();

            if ($check->get_result()->num_rows == 0) {
                $stmt = $conn->prepare("INSERT INTO warning_logs
                                       (product_id, batch_id, warning_level, warning_type, message)
                                       VALUES (?, ?, ?, 'expiry', ?)");
                $stmt->bind_param("iiss", $row['id'], $row['batch_id'], $level, $message);

                if ($stmt->execute()) {
                    $warnings_generated++;
                }
            }
        }
    }

    // 2. 扫描库存预警
    $sql = "SELECT p.id, p.sku, p.name,
                   SUM(b.quantity) as total_quantity
            FROM products p
            LEFT JOIN batches b ON p.id = b.product_id
            GROUP BY p.id
            HAVING total_quantity < $low_stock_threshold OR total_quantity IS NULL";

    $result = $conn->query($sql);

    while ($row = $result->fetch_assoc()) {
        $qty = intval($row['total_quantity'] ?? 0);
        $message = $qty == 0
            ? "缺货告警：{$row['name']} (SKU: {$row['sku']}) 已无库存"
            : "低库存预警：{$row['name']} (SKU: {$row['sku']}) 库存仅剩 {$qty}";

        // 检查是否已存在相同预警
        $check = $conn->prepare("SELECT id FROM warning_logs
                                WHERE product_id = ? AND warning_type = 'stock'
                                AND is_resolved = 0
                                AND DATE(created_at) = CURDATE()");
        $check->bind_param("i", $row['id']);
        $check->execute();

        if ($check->get_result()->num_rows == 0) {
            $stmt = $conn->prepare("INSERT INTO warning_logs
                                   (product_id, warning_level, warning_type, message)
                                   VALUES (?, 'low_stock', 'stock', ?)");
            $stmt->bind_param("is", $row['id'], $message);

            if ($stmt->execute()) {
                $warnings_generated++;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "扫描完成，生成 $warnings_generated 条新预警"
    ]);
    exit;
}

/**
 * 获取预警列表
 */
if ($action === 'get_warnings') {
    $level_filter = $_GET['level'] ?? '';
    $resolved_filter = isset($_GET['resolved']) ? $_GET['resolved'] : '0';
    $limit = intval($_GET['limit'] ?? 50);

    $sql = "SELECT w.*, p.sku, p.name
            FROM warning_logs w
            JOIN products p ON w.product_id = p.id
            WHERE w.is_resolved = $resolved_filter";

    if ($level_filter) {
        $sql .= " AND w.warning_level = '$level_filter'";
    }

    $sql .= " ORDER BY w.created_at DESC LIMIT $limit";

    $result = $conn->query($sql);
    $warnings = [];
    while ($row = $result->fetch_assoc()) {
        $warnings[] = $row;
    }

    echo json_encode(['success' => true, 'warnings' => $warnings]);
    exit;
}

/**
 * 标记预警为已解决
 */
if ($action === 'resolve_warning') {
    $data = json_decode(file_get_contents('php://input'), true);
    $warning_id = intval($data['warning_id'] ?? 0);

    if (!$warning_id) {
        echo json_encode(['success' => false, 'message' => '预警ID无效']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE warning_logs SET is_resolved = 1 WHERE id = ?");
    $stmt->bind_param("i", $warning_id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '预警已标记为已解决']);
    } else {
        echo json_encode(['success' => false, 'message' => '操作失败']);
    }
    exit;
}

/**
 * 获取预警统计
 */
if ($action === 'get_warning_stats') {
    $stats = [
        'critical' => 0,
        'warning' => 0,
        'reminder' => 0,
        'low_stock' => 0,
        'total_unresolved' => 0
    ];

    $result = $conn->query("SELECT warning_level, COUNT(*) as count
                           FROM warning_logs
                           WHERE is_resolved = 0
                           GROUP BY warning_level");

    while ($row = $result->fetch_assoc()) {
        $stats[$row['warning_level']] = $row['count'];
    }

    $result = $conn->query("SELECT COUNT(*) as count
                           FROM warning_logs
                           WHERE is_resolved = 0");
    $row = $result->fetch_assoc();
    $stats['total_unresolved'] = $row['count'];

    echo json_encode(['success' => true, 'stats' => $stats]);
    exit;
}
?>
