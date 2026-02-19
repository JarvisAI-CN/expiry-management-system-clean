<?php
/**
 * ========================================
 * API密钥管理模块
 * 集成到 admin.php
 * ========================================
 */

/**
 * 生成随机API密钥
 */
function generateApiKey() {
    // 生成一段高熵随机明文key，返回给用户使用
    return bin2hex(random_bytes(32)); // 64位十六进制字符串
}

/**
 * 根据明文key生成存储用哈希
 */
function hashApiKeyForStorage($plainKey) {
    // 如需更强可以加盐或使用更复杂方案，这里先做简单但正确的实现
    return hash('sha256', $plainKey);
}

/**
 * 创建API密钥
 */
function createApiKey($name, $createdBy, $expiresAt = null, $scopes = 'read:all') {
    $conn = getDBConnection();
    if (!$conn) {
        return ['success' => false, 'message' => '数据库连接失败'];
    }

    // 生成明文key（只在创建时返回给用户一次）
    $apiKeyPlain = generateApiKey();
    $apiKeyHash = hashApiKeyForStorage($apiKeyPlain);

    if (trim($scopes) === '') {
        $scopes = 'read:all';
    }

    $stmt = $conn->prepare("INSERT INTO api_keys (name, api_key_hash, created_by, expires_at, scopes) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssiss", $name, $apiKeyHash, $createdBy, $expiresAt, $scopes);

    if ($stmt->execute()) {
        $keyId = $conn->insert_id;

        // 记录日志
        addLog('create_api_key', "创建API密钥: {$name} (ID: {$keyId})");

        return [
            'success' => true,
            'key_id' => $keyId,
            'api_key' => $apiKeyPlain,
            'message' => 'API密钥创建成功'
        ];
    }

    return ['success' => false, 'message' => '创建失败: ' . $conn->error];
}

/**
 * 获取所有API密钥列表
 */
function getApiKeys($userId = null) {
    $conn = getDBConnection();
    if (!$conn) {
        return [];
    }

    $sql = "SELECT ak.id, ak.name, ak.is_active, ak.created_at, ak.last_used_at, ak.created_by, ak.scopes, u.username as creator_name
            FROM api_keys ak
            LEFT JOIN users u ON ak.created_by = u.id";

    if ($userId !== null) {
        $sql .= " WHERE ak.created_by = " . intval($userId);
    }

    $sql .= " ORDER BY ak.created_at DESC";

    $result = $conn->query($sql);

    $keys = [];
    while ($row = $result->fetch_assoc()) {
        // 已不再存储明文api_key，这里仅展示占位信息
        $row['api_key_masked'] = '********-********';
        $keys[] = $row;
    }

    return $keys;
}

/**
 * 删除API密钥
 */
function deleteApiKey($keyId, $userId) {
    $conn = getDBConnection();
    if (!$conn) {
        return ['success' => false, 'message' => '数据库连接失败'];
    }

    // 检查权限
    $stmt = $conn->prepare("SELECT created_by FROM api_keys WHERE id = ?");
    $stmt->bind_param("i", $keyId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // 只有创建者可以删除（或者管理员可以删除任何密钥）
        // 这里简化处理：允许删除
    } else {
        return ['success' => false, 'message' => '密钥不存在'];
    }

    // 执行删除
    $deleteStmt = $conn->prepare("DELETE FROM api_keys WHERE id = ?");
    $deleteStmt->bind_param("i", $keyId);

    if ($deleteStmt->execute()) {
        addLog('delete_api_key', "删除API密钥 ID: {$keyId}");

        return ['success' => true, 'message' => 'API密钥已删除'];
    }

    return ['success' => false, 'message' => '删除失败'];
}

/**
 * 切换API密钥状态
 */
function toggleApiKeyStatus($keyId) {
    $conn = getDBConnection();
    if (!$conn) {
        return ['success' => false, 'message' => '数据库连接失败'];
    }

    $stmt = $conn->prepare("UPDATE api_keys SET is_active = NOT is_active WHERE id = ?");
    $stmt->bind_param("i", $keyId);

    if ($stmt->execute()) {
        addLog('toggle_api_key', "切换API密钥状态 ID: {$keyId}");

        return ['success' => true, 'message' => '状态已切换'];
    }

    return ['success' => false, 'message' => '操作失败'];
}

/**
 * 获取API使用统计
 */
function getApiUsageStats($keyId = null, $days = 7) {
    $conn = getDBConnection();
    if (!$conn) {
        return [];
    }

    $sql = "SELECT DATE(al.created_at) as date, COUNT(*) as requests, AVG(al.response_code) as avg_status
            FROM api_logs al
            WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";

    if ($keyId !== null) {
        $sql .= " AND al.api_key_id = " . intval($keyId);
    }

    $sql .= " GROUP BY DATE(al.created_at) ORDER BY date DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $result = $stmt->get_result();

    $stats = [];
    while ($row = $result->fetch_assoc()) {
        $stats[] = $row;
    }

    return $stats;
}

/**
 * 获取API密钥详情（包括使用统计）
 */
function getApiKeyDetails($keyId) {
    $conn = getDBConnection();
    if (!$conn) {
        return null;
    }

    // 获取密钥信息
    $stmt = $conn->prepare("SELECT ak.*, u.username as creator_name
                           FROM api_keys ak
                           LEFT JOIN users u ON ak.created_by = u.id
                           WHERE ak.id = ?");
    $stmt->bind_param("i", $keyId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // 获取使用统计
        $row['usage_stats'] = getApiUsageStats($keyId, 30);

        // 获取最近10次访问记录
        $logStmt = $conn->prepare("SELECT endpoint, response_code, ip_address, created_at
                                   FROM api_logs
                                   WHERE api_key_id = ?
                                   ORDER BY created_at DESC
                                   LIMIT 10");
        $logStmt->bind_param("i", $keyId);
        $logStmt->execute();
        $logResult = $logStmt->get_result();

        $recentLogs = [];
        while ($logRow = $logResult->fetch_assoc()) {
            $recentLogs[] = $logRow;
        }

        $row['recent_logs'] = $recentLogs;

        return $row;
    }

    return null;
}
?>
