#!/usr/bin/env php
<?php
/**
 * SKU上传异步处理脚本
 * 后台处理CSV文件，对比SKU差异
 */

require_once __DIR__ . '/db.php';

$task_id = intval($argv[1] ?? 0);
if (!$task_id) {
    die("Usage: php process_sku_upload.php <task_id>\n");
}

$conn = getDBConnection();

// 获取任务信息
$stmt = $conn->prepare("SELECT * FROM sku_upload_tasks WHERE id = ?");
$stmt->bind_param("i", $task_id);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();

if (!$task) {
    die("Task not found\n");
}

// 更新状态为处理中
$stmt = $conn->prepare("UPDATE sku_upload_tasks SET status = 'processing' WHERE id = ?");
$stmt->bind_param("i", $task_id);
$stmt->execute();

$filepath = __DIR__ . '/uploads/' . $task['filename'];

if (!file_exists($filepath)) {
    $stmt = $conn->prepare("UPDATE sku_upload_tasks SET status = 'failed', error_message = ? WHERE id = ?");
    $error = "文件不存在";
    $stmt->bind_param("si", $error, $task_id);
    $stmt->execute();
    die("File not found\n");
}

try {
    // 根据文件扩展名选择解析方式
    $fileExt = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

    if ($fileExt === 'csv') {
        // 解析CSV文件
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            throw new Exception("无法打开文件");
        }

        $uploaded_skus = [];
        $row_count = 0;

        while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
            $row_count++;

            if (empty($data[0])) continue;

            if ($row_count === 1 && !preg_match('/^\d+$/', $data[0])) {
                continue;
            }

            $sku = trim($data[0]);
            $name = trim($data[1] ?? '');

            if ($sku) {
                $uploaded_skus[$sku] = $name;
            }
        }

        fclose($handle);

    } elseif ($fileExt === 'xlsx' || $fileExt === 'xls') {
        // 解析Excel文件
        require_once __DIR__ . '/xlsx_parser.php';
        $rows = parseXlsxFile($filepath);

        $uploaded_skus = [];
        $row_count = 0;

        foreach ($rows as $rowData) {
            $row_count++;

            if (empty($rowData[0])) continue;

            if ($row_count === 1 && !preg_match('/^\d+$/', $rowData[0])) {
                continue;
            }

            $sku = trim($rowData[0]);
            $name = trim($rowData[1] ?? '');

            if ($sku) {
                $uploaded_skus[$sku] = $name;
            }
        }

    } else {
        throw new Exception("不支持的文件格式：$fileExt");
    }

    // 获取数据库中所有SKU
    $db_skus = [];
    $res = $conn->query("SELECT sku, name FROM products");
    while ($r = $res->fetch_assoc()) {
        $db_skus[$r['sku']] = $r['name'];
    }
    $res = $conn->query("SELECT sku, name FROM sku_todos");
    while ($r = $res->fetch_assoc()) {
        $db_skus[$r['sku']] = $r['name'];
    }

    // 对比差异
    $new_skus = [];
    $missing_skus = [];
    $duplicate_skus = [];

    foreach ($uploaded_skus as $sku => $name) {
        if (!isset($db_skus[$sku])) {
            $new_skus[$sku] = $name;
        }
    }

    foreach ($db_skus as $sku => $name) {
        if (!isset($uploaded_skus[$sku])) {
            $missing_skus[$sku] = $name;
        }
    }

    foreach ($uploaded_skus as $sku => $name) {
        if (isset($db_skus[$sku])) {
            $duplicate_skus[$sku] = $name;
        }
    }

    // 导入新SKU到sku_todos表
    foreach ($new_skus as $sku => $name) {
        $stmt = $conn->prepare("INSERT IGNORE INTO sku_todos (sku, name, source_file) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $sku, $name, $task['filename']);
        $stmt->execute();
    }

    // 准备结果数据
    $result = [
        'new_skus' => array_slice($new_skus, 0, 100, true), // 只返回前100个示例
        'missing_skus' => array_slice($missing_skus, 0, 100, true),
        'duplicate_skus' => array_slice($duplicate_skus, 0, 100, true),
        'new_count' => count($new_skus),
        'missing_count' => count($missing_skus),
        'duplicate_count' => count($duplicate_skus)
    ];

    // 更新任务状态
    $result_json = json_encode($result);
    $stmt = $conn->prepare("UPDATE sku_upload_tasks SET status = 'completed', total_rows = ?, new_skus = ?, missing_skus = ?, duplicate_skus = ?, result_data = ? WHERE id = ?");
    $stmt->bind_param("iiiisi",
        $row_count,
        $result['new_count'],
        $result['missing_count'],
        $result['duplicate_count'],
        $result_json,
        $task_id
    );
    $stmt->execute();

    echo "Task completed: {$result['new_count']} new, {$result['missing_count']} missing, {$result['duplicate_count']} duplicates\n";

} catch (Exception $e) {
    $stmt = $conn->prepare("UPDATE sku_upload_tasks SET status = 'failed', error_message = ? WHERE id = ?");
    $error = $e->getMessage();
    $stmt->bind_param("si", $error, $task_id);
    $stmt->execute();
    echo "Error: " . $e->getMessage() . "\n";
}
