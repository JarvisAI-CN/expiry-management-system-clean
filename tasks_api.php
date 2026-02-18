<?php
/**
 * ========================================
 * 保质期管理系统 - 盘点任务管理 API
 * 文件名: tasks_api.php
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
 * 获取所有盘点任务
 */
if ($action === 'get_tasks') {
    $status_filter = $_GET['status'] ?? '';

    $sql = "SELECT t.*, p.sku, p.name, p.inventory_cycle
            FROM inventory_tasks t
            JOIN products p ON t.product_id = p.id";

    if ($status_filter) {
        $sql .= " WHERE t.status = '$status_filter'";
    }

    $sql .= " ORDER BY t.scheduled_date ASC, t.created_at DESC";

    $result = $conn->query($sql);
    $tasks = [];
    while ($row = $result->fetch_assoc()) {
        $tasks[] = $row;
    }

    echo json_encode(['success' => true, 'tasks' => $tasks]);
    exit;
}

/**
 * 生成盘点任务（根据商品周期）
 */
if ($action === 'generate_tasks') {
    $result = $conn->query("SELECT id, sku, name, inventory_cycle, last_inventory_at
                           FROM products
                           WHERE inventory_cycle != 'none'
                           ORDER BY last_inventory_at ASC");

    $generated_count = 0;
    $today = date('Y-m-d');

    while ($product = $result->fetch_assoc()) {
        $cycle = $product['inventory_cycle'];
        $last_inventory = $product['last_inventory_at'] ?? null;

        // 计算下次盘点日期
        $next_date = calculateNextInventoryDate($cycle, $last_inventory);

        if (!$next_date) continue;

        // 检查是否已存在相同任务
        $check = $conn->prepare("SELECT id FROM inventory_tasks
                                WHERE product_id = ? AND scheduled_date = ?");
        $check->bind_param("is", $product['id'], $next_date);
        $check->execute();
        $existing = $check->get_result()->num_rows;

        if ($existing > 0) continue;

        // 插入新任务
        $stmt = $conn->prepare("INSERT INTO inventory_tasks
                               (product_id, task_type, scheduled_date, status)
                               VALUES (?, ?, ?, 'pending')");
        $stmt->bind_param("iss", $product['id'], $cycle, $next_date);

        if ($stmt->execute()) {
            $generated_count++;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "成功生成 $generated_count 个盘点任务"
    ]);
    exit;
}

/**
 * 更新任务状态
 */
if ($action === 'update_task_status') {
    $data = json_decode(file_get_contents('php://input'), true);

    $task_id = $data['task_id'] ?? 0;
    $status = $data['status'] ?? 'pending';

    if (!$task_id) {
        echo json_encode(['success' => false, 'message' => '任务ID无效']);
        exit;
    }

    $completed_at = ($status === 'completed') ? 'NOW()' : 'NULL';

    $stmt = $conn->prepare("UPDATE inventory_tasks
                           SET status = ?,
                               completed_at = $completed_at
                           WHERE id = ?");
    $stmt->bind_param("si", $status, $task_id);

    if ($stmt->execute()) {
        // 如果任务完成，更新商品的last_inventory_at
        if ($status === 'completed') {
            $conn->query("UPDATE products p
                         JOIN inventory_tasks t ON p.id = t.product_id
                         SET p.last_inventory_at = CURDATE()
                         WHERE t.id = $task_id");
        }

        echo json_encode(['success' => true, 'message' => '任务状态已更新']);
    } else {
        echo json_encode(['success' => false, 'message' => '更新失败']);
    }
    exit;
}

/**
 * 获取任务统计
 */
if ($action === 'get_task_stats') {
    $stats = [
        'pending' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'overdue' => 0
    ];

    $result = $conn->query("SELECT status, COUNT(*) as count
                           FROM inventory_tasks
                           GROUP BY status");

    while ($row = $result->fetch_assoc()) {
        $stats[$row['status']] = $row['count'];
    }

    // 计算超期任务
    $result = $conn->query("SELECT COUNT(*) as count
                           FROM inventory_tasks
                           WHERE scheduled_date < CURDATE()
                           AND status != 'completed'");
    $row = $result->fetch_assoc();
    $stats['overdue'] = $row['count'];

    echo json_encode(['success' => true, 'stats' => $stats]);
    exit;
}

/**
 * 计算下次盘点日期
 */
function calculateNextInventoryDate($cycle, $last_inventory) {
    $today = time();
    $last = $last_inventory ? strtotime($last_inventory) : $today - 86400; // 默认昨天

    switch ($cycle) {
        case 'daily':
            return date('Y-m-d', $today + 86400); // 明天
        case 'weekly':
            return date('Y-m-d', $last + 7 * 86400); // 7天后
        case 'monthly':
            return date('Y-m-d', strtotime('+1 month', $last)); // 1个月后
        case 'yearly':
            return date('Y-m-d', strtotime('+1 year', $last)); // 1年后
        default:
            return null;
    }
}
?>
