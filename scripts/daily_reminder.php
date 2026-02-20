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


// === Report windows (fixed by requirement) ===
// - expiring within 3 days (0..3)
// - expiring within 7 days (4..7)
// - expired within last 3 days (-3..-1)

$expiring_3 = [];
$expiring_7 = [];
$expired_3 = [];

while ($row = $res->fetch_assoc()) {
    $days = (int)$row['days_to_expiry'];

    if ($days >= 0 && $days <= 3) {
        $expiring_3[] = $row;
    } elseif ($days >= 4 && $days <= 7) {
        $expiring_7[] = $row;
    } elseif ($days < 0 && $days >= -3) {
        $expired_3[] = $row;
    }
}

$subject = "保质期每日提醒（{$today}）";

function render_table($title, $items) {
    if (count($items) === 0) return "";
    $rows = '';
    foreach (array_slice($items, 0, 200) as $r) {
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

function csv_escape($v) {
    $v = (string)$v;
    $v = str_replace('"', '""', $v);
    return '"' . $v . '"';
}

function build_csv_section($title, $items) {
    $lines = [];
    $lines[] = $title;
    $lines[] = 'SKU,商品,分类,到期日,数量,剩余天数';
    foreach ($items as $r) {
        $lines[] = implode(',', [
            csv_escape($r['sku'] ?? ''),
            csv_escape($r['product_name'] ?? ''),
            csv_escape($r['category_name'] ?? ''),
            csv_escape($r['expiry_date'] ?? ''),
            csv_escape($r['quantity'] ?? ''),
            csv_escape($r['days_to_expiry'] ?? ''),
        ]);
    }
    $lines[] = '';
    return implode("\r\n", $lines);
}

$csv = "";
$csv .= build_csv_section("3天内到期（以 {$today} 为节点）", $expiring_3);
$csv .= "\r\n" . build_csv_section("7天内到期（4~7天）", $expiring_7);
$csv .= "\r\n" . build_csv_section("已过期（近3天内过期，方便复查）", $expired_3);

$csvFilename = 'expiry-reminder-' . date('Ymd') . '.csv';
$csvWithBom = "\xEF\xBB\xBF" . $csv; // Excel-friendly UTF-8 BOM

$html = "<div style='font-family:Arial,Helvetica,sans-serif'>";
$html .= "<h2>保质期每日提醒</h2>";
$html .= "<p>日期：{$today}</p>";
$html .= "<ul>";
$html .= "<li><b>3天内到期</b>：".count($expiring_3)."</li>";
$html .= "<li><b>7天内到期</b>（4~7天）：".count($expiring_7)."</li>";
$html .= "<li><b>已过期</b>（近3天内过期）：".count($expired_3)."</li>";
$html .= "</ul>";
$html .= "<p><b>附件：</b>{$csvFilename}（可下载表格）</p>";
$html .= render_table('3天内到期（以邮件发布日期为节点）', $expiring_3);
$html .= render_table('7天内到期（4~7天）', $expiring_7);
$html .= render_table('已过期（过期时间在三天之内，方便复查）', $expired_3);
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
        'attachments' => [
            [
                'filename' => $csvFilename,
                'contentType' => 'text/csv; charset=UTF-8',
                'content' => $csvWithBom,
            ],
        ],
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
