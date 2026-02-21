<?php
/**
 * ========================================
 * 保质期管理系统 - 邮箱功能函数库
 * 文件名: email_functions.php
 * 版本: v1.0.0
 * ========================================
 */

// 从配置文件读取加密密钥
if (defined('EMAIL_ENCRYPTION_KEY')) {
    define('AUTH_ENCRYPTION_KEY', EMAIL_ENCRYPTION_KEY);
} else {
    // 如果配置文件中没有定义，使用默认密钥（不建议生产环境使用）
    define('AUTH_ENCRYPTION_KEY', 'expiry-system-email-key-2026');
}

// SMTP配置（QQ邮箱固定）
define('SMTP_HOST', 'smtp.qq.com');
define('SMTP_PORT', 465);
define('SMTP_ENCRYPTION', 'ssl');

// ========================================
// 加密/解密函数
// ========================================

/**
 * 加密授权码
 * @param string $authCode 明文授权码
 * @return string Base64编码的密文
 */
function encryptAuthCode($authCode) {
    $key = hash('sha256', AUTH_ENCRYPTION_KEY, true);
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($authCode, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * 解密授权码
 * @param string $encrypted Base64编码的密文
 * @return string|false 明文授权码，失败返回false
 */
function decryptAuthCode($encrypted) {
    $key = hash('sha256', AUTH_ENCRYPTION_KEY, true);
    $data = base64_decode($encrypted);
    if ($data === false) return false;
    
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    $result = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    
    return ($result === false) ? false : $result;
}

// ========================================
// 账户管理函数
// ========================================

/**
 * 添加邮箱账户
 * @param mysqli $conn 数据库连接
 * @param string $qqNumber QQ号
 * @param string $authCode 授权码
 * @param int $priority 优先级
 * @return array ['success'=>bool, 'data'=>array, 'message'=>string]
 */
function addEmailAccount($conn, $qqNumber, $authCode, $priority = 0) {
    // 验证QQ号格式
    if (!preg_match('/^\d{5,12}$/', $qqNumber)) {
        return [
            'success' => false,
            'message' => 'QQ号格式错误，必须是5-12位数字'
        ];
    }
    
    // 验证授权码
    if (empty($authCode) || strlen($authCode) < 10) {
        return [
            'success' => false,
            'message' => '授权码格式错误'
        ];
    }
    
    // 检查是否已存在
    $stmt = $conn->prepare("SELECT id FROM email_accounts WHERE qq_number = ?");
    $stmt->bind_param("s", $qqNumber);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        return [
            'success' => false,
            'message' => '该QQ号已添加'
        ];
    }
    
    // 组装邮箱地址
    $emailAddress = $qqNumber . '@qq.com';
    
    // 加密授权码
    $encryptedCode = encryptAuthCode($authCode);
    
    // 插入数据库
    $stmt = $conn->prepare("INSERT INTO email_accounts (qq_number, email_address, auth_code_encrypted, priority, created_by) VALUES (?, ?, ?, ?, ?)");
    $userId = $_SESSION['user_id'] ?? null;
    $stmt->bind_param("sssii", $qqNumber, $emailAddress, $encryptedCode, $priority, $userId);
    
    if ($stmt->execute()) {
        $accountId = $conn->insert_id;
        addLog('email_account_added', "添加邮箱: $emailAddress");
        
        return [
            'success' => true,
            'message' => '邮箱账户添加成功',
            'data' => [
                'id' => $accountId,
                'email_address' => $emailAddress,
                'qq_number' => $qqNumber
            ]
        ];
    } else {
        return [
            'success' => false,
            'message' => '数据库操作失败: ' . $conn->error
        ];
    }
}

/**
 * 获取所有邮箱账户
 * @param mysqli $conn 数据库连接
 * @return array ['success'=>bool, 'data'=>array]
 */
function listEmailAccounts($conn) {
    $sql = "SELECT id, qq_number, email_address, is_active, priority, 
                   send_count, last_sent_at, last_sent_success, error_message,
                   created_at, updated_at
            FROM email_accounts 
            ORDER BY priority DESC, created_at DESC";
    
    $result = $conn->query($sql);
    $accounts = [];
    $activeCount = 0;
    
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
        if ($row['is_active']) {
            $activeCount++;
        }
    }
    
    return [
        'success' => true,
        'data' => [
            'total' => count($accounts),
            'active_count' => $activeCount,
            'accounts' => $accounts
        ]
    ];
}

/**
 * 更新邮箱账户
 * @param mysqli $conn 数据库连接
 * @param int $id 账户ID
 * @param array $updates 更新字段
 * @return array ['success'=>bool, 'message'=>string]
 */
function updateEmailAccount($conn, $id, $updates) {
    $allowedFields = ['auth_code', 'is_active', 'priority'];
    $setClause = [];
    $types = '';
    $params = [];
    
    foreach ($updates as $field => $value) {
        if (!in_array($field, $allowedFields)) {
            continue;
        }
        
        if ($field === 'auth_code') {
            $setClause[] = "auth_code_encrypted = ?";
            $params[] = encryptAuthCode($value);
            $types .= 's';
        } elseif ($field === 'is_active') {
            $setClause[] = "is_active = ?";
            $params[] = (int)$value;
            $types .= 'i';
        } elseif ($field === 'priority') {
            $setClause[] = "priority = ?";
            $params[] = (int)$value;
            $types .= 'i';
        }
    }
    
    if (empty($setClause)) {
        return ['success' => false, 'message' => '没有有效的更新字段'];
    }
    
    $params[] = $id;
    $types .= 'i';
    
    $sql = "UPDATE email_accounts SET " . implode(', ', $setClause) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        addLog('email_account_updated', "更新邮箱账户 ID: $id");
        return ['success' => true, 'message' => '更新成功'];
    } else {
        return ['success' => false, 'message' => '更新失败: ' . $conn->error];
    }
}

/**
 * 删除邮箱账户
 * @param mysqli $conn 数据库连接
 * @param int $id 账户ID
 * @return array ['success'=>bool, 'message'=>string]
 */
function deleteEmailAccount($conn, $id) {
    $stmt = $conn->prepare("DELETE FROM email_accounts WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        addLog('email_account_deleted', "删除邮箱账户 ID: $id");
        return ['success' => true, 'message' => '删除成功'];
    } else {
        return ['success' => false, 'message' => '删除失败: ' . $conn->error];
    }
}

// ========================================
// 轮换算法函数
// ========================================

/**
 * 选择下一个发送账户（智能轮换）
 * @param mysqli $conn 数据库连接
 * @return array|false 账户信息，失败返回false
 */
function selectNextAccount($conn) {
    $cooldownSeconds = getSetting('email_cooldown_seconds', 300);
    
    // 1. 获取所有启用的账户
    $sql = "SELECT id, email_address, auth_code_encrypted, priority, 
                   send_count, last_sent_at, last_sent_success
            FROM email_accounts 
            WHERE is_active = 1
            ORDER BY priority DESC, send_count ASC, last_sent_at ASC";
    
    $result = $conn->query($sql);
    $accounts = [];
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
    
    if (empty($accounts)) {
        return false;
    }
    
    // 2. 过滤掉冷却期内的失败账户
    $validAccounts = [];
    $currentTime = time();
    
    foreach ($accounts as $acc) {
        // 如果最后发送失败且在冷却期内，跳过
        if ($acc['last_sent_success'] === false && $acc['last_sent_at']) {
            $lastSent = strtotime($acc['last_sent_at']);
            if ($currentTime - $lastSent < $cooldownSeconds) {
                continue; // 跳过冷却中的账户
            }
        }
        $validAccounts[] = $acc;
    }
    
    // 如果所有账户都在冷却期，选择优先级最高的
    if (empty($validAccounts)) {
        $validAccounts = $accounts;
    }
    
    // 3. 计算权重并选择
    // 权重 = 100 + 优先级*10 - 发送次数*2
    $maxWeight = -9999;
    $selectedAccount = null;
    
    foreach ($validAccounts as $acc) {
        $weight = 100 + ($acc['priority'] * 10) - ($acc['send_count'] * 2);
        
        // 如果使用时间很久没被使用，给予额外加分
        if ($acc['last_sent_at']) {
            $hoursSinceLast = ($currentTime - strtotime($acc['last_sent_at'])) / 3600;
            $weight += min($hoursSinceLast, 24); // 最多加24分
        }
        
        if ($weight > $maxWeight) {
            $maxWeight = $weight;
            $selectedAccount = $acc;
        }
    }
    
    return $selectedAccount;
}

// ========================================
// 邮件发送函数
// ========================================

/**
 * 发送邮件
 * @param mysqli $conn 数据库连接
 * @param string $recipient 收件人邮箱
 * @param string $subject 邮件主题
 * @param string $body 邮件正文（HTML）
 * @param int|null $specificAccountId 指定账户ID（可选）
 * @return array ['success'=>bool, 'data'=>array, 'message'=>string]
 */
function sendEmail($conn, $recipient, $subject, $body, $specificAccountId = null) {
    // 1. 选择发送账户
    if ($specificAccountId) {
        $sql = "SELECT * FROM email_accounts WHERE id = ? AND is_active = 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $specificAccountId);
        $stmt->execute();
        $result = $stmt->get_result();
        $account = $result->fetch_assoc();
    } else {
        $account = selectNextAccount($conn);
    }
    
    if (!$account) {
        return [
            'success' => false,
            'message' => '没有可用的邮箱账户'
        ];
    }
    
    // 2. 解密授权码
    $authCode = decryptAuthCode($account['auth_code_encrypted']);
    if ($authCode === false) {
        return [
            'success' => false,
            'message' => '授权码解密失败'
        ];
    }
    
    // 3. 创建日志记录
    $stmt = $conn->prepare("INSERT INTO email_logs (account_id, recipient, subject, body, status) VALUES (?, ?, ?, ?, 'pending')");
    $stmt->bind_param("isss", $account['id'], $recipient, $subject, $body);
    $stmt->execute();
    $logId = $conn->insert_id;
    
    // 4. 使用PHPMailer发送邮件
    try {
        // 引入PHPMailer
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            // 尝试从vendor目录加载
            $vendorPath = __DIR__ . '/vendor/autoload.php';
            if (file_exists($vendorPath)) {
                require_once $vendorPath;
            } else {
                throw new Exception('PHPMailer未安装，请运行: composer require phpmailer/phpmailer');
            }
        }
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // 服务器设置
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = $account['email_address'];
        $mail->Password = $authCode;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        
        // 收发件人
        $mail->setFrom($account['email_address'], '保质期管理系统');
        $mail->addAddress($recipient);
        
        // 内容
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);
        
        // 发送
        $mail->send();
        
        // 5. 更新账户统计
        $updateStmt = $conn->prepare("UPDATE email_accounts SET send_count = send_count + 1, last_sent_at = NOW(), last_sent_success = 1, error_message = NULL WHERE id = ?");
        $updateStmt->bind_param("i", $account['id']);
        $updateStmt->execute();
        
        // 6. 更新日志
        $logUpdateStmt = $conn->prepare("UPDATE email_logs SET status = 'sent', sent_at = NOW() WHERE id = ?");
        $logUpdateStmt->bind_param("i", $logId);
        $logUpdateStmt->execute();
        
        addLog('email_sent', "邮件发送成功: $recipient");
        
        return [
            'success' => true,
            'message' => '邮件发送成功',
            'data' => [
                'log_id' => $logId,
                'account_id' => $account['id'],
                'account_email' => $account['email_address'],
                'recipient' => $recipient,
                'sent_at' => date('Y-m-d H:i:s')
            ]
        ];
        
    } catch (Exception $e) {
        $errorMsg = $mail->ErrorInfo ?? $e->getMessage();
        
        // 更新账户错误状态
        $errorUpdateStmt = $conn->prepare("UPDATE email_accounts SET last_sent_success = 0, error_message = ? WHERE id = ?");
        $errorUpdateStmt->bind_param("si", $errorMsg, $account['id']);
        $errorUpdateStmt->execute();
        
        // 更新日志
        $logUpdateStmt = $conn->prepare("UPDATE email_logs SET status = 'failed', error_message = ? WHERE id = ?");
        $logUpdateStmt->bind_param("si", $errorMsg, $logId);
        $logUpdateStmt->execute();
        
        addLog('email_failed', "邮件发送失败: $errorMsg");
        
        return [
            'success' => false,
            'message' => '邮件发送失败',
            'error' => $errorMsg
        ];
    }
}

/**
 * 发送测试邮件
 * @param mysqli $conn 数据库连接
 * @param int $accountId 账户ID
 * @param string $recipient 测试收件人
 * @return array ['success'=>bool, 'message'=>string]
 */
function sendTestEmail($conn, $accountId, $recipient) {
    $subject = '保质期管理系统 - 测试邮件';
    $body = '
        <html>
        <head><meta charset="UTF-8"></head>
        <body style="font-family: Arial, sans-serif;">
            <h2>这是一封测试邮件</h2>
            <p>您好！</p>
            <p>这是来自保质期管理系统的测试邮件。</p>
            <p>如果您收到此邮件，说明邮箱配置成功！</p>
            <hr>
            <p style="color: #666; font-size: 12px;">
                发送时间: ' . date('Y-m-d H:i:s') . '<br>
                系统名称: 保质期管理系统
            </p>
        </body>
        </html>
    ';
    
    return sendEmail($conn, $recipient, $subject, $body, $accountId);
}

// ========================================
// 日志查询函数
// ========================================

/**
 * 获取邮件发送日志
 * @param mysqli $conn 数据库连接
 * @param array $filters 筛选条件
 * @return array ['success'=>bool, 'data'=>array]
 */
function getEmailLogs($conn, $filters = []) {
    $where = ['1=1'];
    $params = [];
    $types = '';
    
    if (!empty($filters['account_id'])) {
        $where[] = 'account_id = ?';
        $params[] = $filters['account_id'];
        $types .= 'i';
    }
    
    if (!empty($filters['status'])) {
        $where[] = 'status = ?';
        $params[] = $filters['status'];
        $types .= 's';
    }
    
    $limit = (int)($filters['limit'] ?? 20);
    $offset = (int)($filters['offset'] ?? 0);
    
    // 获取总数
    $countSql = "SELECT COUNT(*) as total FROM email_logs WHERE " . implode(' AND ', $where);
    $stmt = $conn->prepare($countSql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
    
    // 获取日志
    $sql = "SELECT l.*, a.email_address as account_email
            FROM email_logs l
            LEFT JOIN email_accounts a ON l.account_id = a.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY l.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $types .= 'ii';
    $params[] = $limit;
    $params[] = $offset;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    
    $logs = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    
    return [
        'success' => true,
        'data' => [
            'total' => $total,
            'logs' => $logs
        ]
    ];
}
