<?php
/**
 * Daily expiry reminder runner
 *
 * Usage:
 *   php scripts/daily_reminder.php            # idempotent: once per day
 *   php scripts/daily_reminder.php --force    # send regardless of last_sent
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../smtp_mailer.php';

$conn = getDBConnection();
if (!$conn) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$force = in_array('--force', $argv, true);

function setting($k, $default='') {
    return getSetting($k, $default);
}

$enabled = setting('daily_reminder_enabled', '0') === '1';
if (!$enabled && !$force) {
    echo "disabled\n";
    exit(0);
}

$today = date('Y-m-d');
$lastSent = trim(setting('daily_reminder_last_sent', ''));
if (!$force && $lastSent === $today) {
    echo "already sent today\n";
    exit(0);
}

$recipientsRaw = trim(setting('daily_reminder_recipients', ''));
if ($recipientsRaw === '') {
    echo "no recipients\n";
    exit(0);
}
$recipients = array_values(array_filter(array_map('trim', preg_split('/[;,\s]+/', $recipientsRaw))));

$alertDaysRaw = trim(setting('alert_days', '3,7,15'));
$globalDays = array_values(array_filter(array_map('intval', explode(',', $alertDaysRaw))));
$g1 = $globalDays[0] ?? 7;
$g2 = $globalDays[1] ?? 15;
$g3 = $globalDays[2] ?? 30;

// Fetch batches expiring within max window (use global max as wide net)
$maxWindow = max($g1, $g2, $g3, 30);
$sql = "SELECT p.sku, p.name as product_name, c.name as category_name, c.rule,
               b.id as batch_id, b.expiry_date, b.quantity,
               DATEDIFF(b.expiry_date, CURDATE()) as days_to_expiry
        FROM batches b
        JOIN products p ON p.id = b.product_id
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE b.quantity > 0
          AND b.expiry_date IS NOT NULL
          AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ORDER BY days_to_expiry ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $maxWindow);
$stmt->execute();
$res = $stmt->get_result();

$expired = [];
$critical = [];
$warning = [];
$reminder = [];

function cat_thresholds($ruleJson, $g1, $g2, $g3) {
    if (!$ruleJson) return [$g1, $g2, $g3];
    $data = json_decode($ruleJson, true);
    if (!is_array($data)) return [$g1, $g2, $g3];
    $t1 = isset($data['warning_days_level1']) ? (int)$data['warning_days_level1'] : $g1;
    $t2 = isset($data['warning_days_level2']) ? (int)$data['warning_days_level2'] : $g2;
    $t3 = isset($data['warning_days_level3']) ? (int)$data['warning_days_level3'] : $g3;
    return [$t1, $t2, $t3];
}

while ($row = $res->fetch_assoc()) {
    $days = (int)$row['days_to_expiry'];
    [$t1,$t2,$t3] = cat_thresholds($row['rule'] ?? '', $g1,$g2,$g3);

    if ($days < 0) {
        $expired[] = $row;
    } elseif ($days <= $t1) {
        $critical[] = $row;
    } elseif ($days <= $t2) {
        $warning[] = $row;
    } elseif ($days <= $t3) {
        $reminder[] = $row;
    }
}

$subject = "保质期每日提醒（{$today}）";

function render_table($title, $items) {
    if (count($items) === 0) return "";
    $rows = '';
    foreach (array_slice($items, 0, 50) as $r) {
        $sku = htmlspecialchars($r['sku']);
        $name = htmlspecialchars($r['product_name']);
        $cat = htmlspecialchars($r['category_name'] ?? '-');
        $exp = htmlspecialchars($r['expiry_date']);
        $qty = (int)$r['quantity'];
        $days = (int)$r['days_to_expiry'];
        $rows .= "<tr><td>{$sku}</td><td>{$name}</td><td>{$cat}</td><td>{$exp}</td><td>{$qty}</td><td>{$days}</td></tr>";
    }
    return "<h3 style='margin-top:18px'>{$title}（".count($items)."）</h3><table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse;font-size:13px'><thead><tr><th>SKU</th><th>商品</th><th>分类</th><th>到期日</th><th>数量</th><th>剩余天数</th></tr></thead><tbody>{$rows}</tbody></table>";
}

$html = "<div style='font-family:Arial,Helvetica,sans-serif'>";
$html .= "<h2>保质期每日提醒</h2>";
$html .= "<p>日期：{$today}</p>";
$html .= "<ul>";
$html .= "<li>已过期：<b>".count($expired)."</b></li>";
$html .= "<li>严重（临期）：<b>".count($critical)."</b></li>";
$html .= "<li>警告：<b>".count($warning)."</b></li>";
$html .= "<li>提醒：<b>".count($reminder)."</b></li>";
$html .= "</ul>";
$html .= render_table('已过期（需要立即处理）', $expired);
$html .= render_table('严重预警（临期）', $critical);
$html .= render_table('警告', $warning);
$html .= render_table('提醒', $reminder);
$html .= "<p style='color:#666'>备注：阈值优先使用分类规则（warning_days_level1/2/3），否则使用全局 alert_days（{$g1},{$g2},{$g3}）</p>";
$html .= "</div>";

$cfg = [
    'host' => setting('smtp_host',''),
    'port' => (int)setting('smtp_port','587'),
    'secure' => setting('smtp_secure','tls'),
    'username' => setting('smtp_user',''),
    'password' => setting('smtp_pass',''),
    'from_email' => setting('smtp_from_email',''),
    'from_name' => setting('smtp_from_name','保质期管理系统'),
];

foreach (['host','username','password','from_email'] as $k) {
    if (trim((string)$cfg[$k]) === '') {
        echo "smtp not configured\n";
        exit(0);
    }
}

$anyFail = false;
foreach ($recipients as $to) {
    $result = smtp_send_mail($cfg + [
        'to' => $to,
        'subject' => $subject,
        'html' => $html,
    ]);
    if (!$result['success']) {
        $anyFail = true;
        error_log('daily_reminder send failed to ' . $to . ': ' . $result['message']);
    }
}

if (!$anyFail) {
    setSetting('daily_reminder_last_sent', $today);
}

echo $anyFail ? "partial failure\n" : "ok\n";
