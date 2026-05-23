<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

const APP_ROOT = __DIR__ . '/..';
const DATA_DIR = APP_ROOT . '/data';
const DB_PATH = DATA_DIR . '/shelf_life.sqlite';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    initialize_database($pdo);

    return $pdo;
}

function table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("PRAGMA table_info({$table})");
    return array_column($stmt->fetchAll(), 'name');
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!in_array($column, table_columns($pdo, $table), true)) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function initialize_database(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS products (
            sku TEXT PRIMARY KEY,
            display_sku TEXT NOT NULL,
            name TEXT NOT NULL,
            category TEXT,
            alert_days INTEGER NOT NULL DEFAULT 7,
            shelf_remove_days INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    ensure_column($pdo, 'products', 'category', 'TEXT');
    ensure_column($pdo, 'products', 'alert_days', 'INTEGER NOT NULL DEFAULT 7');
    ensure_column($pdo, 'products', 'shelf_remove_days', 'INTEGER NOT NULL DEFAULT 0');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            early_dispose_days INTEGER NOT NULL DEFAULT 7,
            shelf_remove_days INTEGER NOT NULL DEFAULT 0,
            check_frequency TEXT NOT NULL DEFAULT \'daily\',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS scans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            raw_code TEXT NOT NULL,
            sku TEXT NOT NULL,
            production_date TEXT,
            expiry_date TEXT,
            product_name_snapshot TEXT,
            quantity INTEGER NOT NULL,
            note TEXT,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS stocktake_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_code TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL DEFAULT \'draft\',
            note TEXT,
            ai_analysis TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS stocktake_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            raw_code TEXT NOT NULL,
            sku TEXT NOT NULL,
            production_date TEXT,
            expiry_date TEXT,
            product_name_snapshot TEXT,
            quantity INTEGER NOT NULL,
            note TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY (session_id) REFERENCES stocktake_sessions(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_category ON products (category)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_categories_name ON categories (name)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scans_sku ON scans (sku)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scans_expiry ON scans (expiry_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scans_created ON scans (created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stocktake_items_session ON stocktake_items (session_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stocktake_items_expiry ON stocktake_items (expiry_date)');

    seed_default_categories($pdo);
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_string(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');
}

function normalize_sku(?string $sku): string
{
    $sku = trim((string) $sku);
    $sku = preg_replace('/^\xEF\xBB\xBF/', '', $sku) ?? $sku;
    $sku = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $sku) ?? '');
    $trimmed = ltrim($sku, '0');

    if ($trimmed === '' && $sku !== '') {
        return '0';
    }

    return $trimmed;
}

function normalize_day_count($value, int $default, int $min = 0, int $max = 365): int
{
    if ($value === null || trim((string) $value) === '') {
        return $default;
    }

    $number = filter_var($value, FILTER_VALIDATE_INT);

    if ($number === false) {
        return $default;
    }

    return max($min, min($max, (int) $number));
}

function product_alert_days(?array $product): int
{
    return normalize_day_count($product['alert_days'] ?? null, 7, 0, 365);
}

function product_shelf_remove_days(?array $product): int
{
    return normalize_day_count($product['shelf_remove_days'] ?? null, 0, 0, 365);
}

function get_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string) $value;
}

function set_setting(PDO $pdo, string $key, ?string $value): void
{
    if ($value === null || $value === '') {
        $stmt = $pdo->prepare('DELETE FROM settings WHERE key = :key');
        $stmt->execute([':key' => $key]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO settings (key, value, updated_at)
         VALUES (:key, :value, :updated_at)
         ON CONFLICT(key) DO UPDATE SET
            value = excluded.value,
            updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
        ':updated_at' => now_string(),
    ]);
}

function mask_secret(?string $secret): string
{
    $secret = trim((string) $secret);

    if ($secret === '') {
        return '未保存';
    }

    if (strlen($secret) <= 10) {
        return str_repeat('*', strlen($secret));
    }

    return substr($secret, 0, 4) . str_repeat('*', max(4, strlen($secret) - 8)) . substr($secret, -4);
}

function seed_default_categories(PDO $pdo): void
{
    $defaults = [
        ['糕点类', 2, 1, 'daily'],
        ['鲜奶类', 1, 0, 'daily'],
        ['咖啡豆', 7, 3, 'weekly'],
        ['常温物料', 30, 0, 'monthly'],
        ['糖浆/果汁', 14, 0, 'weekly'],
        ['其他', 7, 0, 'weekly'],
    ];

    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO categories (
            name, early_dispose_days, shelf_remove_days, check_frequency, created_at, updated_at
        ) VALUES (
            :name, :early_dispose_days, :shelf_remove_days, :check_frequency, :created_at, :updated_at
        )'
    );

    foreach ($defaults as [$name, $earlyDisposeDays, $shelfRemoveDays, $checkFrequency]) {
        $now = now_string();
        $stmt->execute([
            ':name' => $name,
            ':early_dispose_days' => $earlyDisposeDays,
            ':shelf_remove_days' => $shelfRemoveDays,
            ':check_frequency' => $checkFrequency,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

function list_categories(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();
}

function find_category_by_name(PDO $pdo, ?string $name): ?array
{
    $name = trim((string) $name);

    if ($name === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM categories WHERE name = :name');
    $stmt->execute([':name' => $name]);
    $category = $stmt->fetch();

    return $category ?: null;
}

function upsert_category(PDO $pdo, string $name, $earlyDisposeDays = null, $shelfRemoveDays = null, string $checkFrequency = 'daily'): bool
{
    $name = trim($name);
    $earlyDisposeDays = normalize_day_count($earlyDisposeDays, 7, 0, 365);
    $shelfRemoveDays = normalize_day_count($shelfRemoveDays, 0, 0, 365);
    $allowed = ['daily', 'weekly', 'monthly'];
    $checkFrequency = in_array($checkFrequency, $allowed, true) ? $checkFrequency : 'daily';

    if ($name === '') {
        return false;
    }

    $now = now_string();
    $stmt = $pdo->prepare(
        'INSERT INTO categories (
            name, early_dispose_days, shelf_remove_days, check_frequency, created_at, updated_at
        ) VALUES (
            :name, :early_dispose_days, :shelf_remove_days, :check_frequency, :created_at, :updated_at
        )
        ON CONFLICT(name) DO UPDATE SET
            early_dispose_days = excluded.early_dispose_days,
            shelf_remove_days = excluded.shelf_remove_days,
            check_frequency = excluded.check_frequency,
            updated_at = excluded.updated_at'
    );

    return $stmt->execute([
        ':name' => $name,
        ':early_dispose_days' => $earlyDisposeDays,
        ':shelf_remove_days' => $shelfRemoveDays,
        ':check_frequency' => $checkFrequency,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function parse_ymd(?string $value): ?string
{
    $digits = preg_replace('/\D/', '', (string) $value) ?? '';

    if (strlen($digits) !== 8) {
        return null;
    }

    $year = (int) substr($digits, 0, 4);
    $month = (int) substr($digits, 4, 2);
    $day = (int) substr($digits, 6, 2);

    if (!checkdate($month, $day, $year)) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function compact_ymd(?string $date): ?string
{
    if ($date === null || $date === '') {
        return null;
    }

    return str_replace('-', '', $date);
}

function display_date(?string $date): string
{
    if ($date === null || $date === '') {
        return '-';
    }

    return str_replace('-', '/', $date);
}

function days_left(?string $expiryDate): ?int
{
    if ($expiryDate === null || $expiryDate === '') {
        return null;
    }

    $timezone = new DateTimeZone('Asia/Shanghai');
    $today = (new DateTimeImmutable('today', $timezone))->setTime(0, 0);
    $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $expiryDate, $timezone);

    if (!$expiry) {
        return null;
    }

    return (int) $today->diff($expiry)->format('%r%a');
}

function expiry_status(?int $daysLeft, int $alertDays = 7): array
{
    $alertDays = normalize_day_count($alertDays, 7, 0, 365);

    if ($daysLeft === null) {
        return ['code' => 'unknown', 'text' => '待确认'];
    }

    if ($daysLeft < 0) {
        return ['code' => 'expired', 'text' => '已过期'];
    }

    if ($daysLeft <= $alertDays) {
        return ['code' => 'urgent', 'text' => '临期'];
    }

    if ($daysLeft <= 30) {
        return ['code' => 'warning', 'text' => '需优先'];
    }

    return ['code' => 'ok', 'text' => '正常'];
}

function parse_qr_code(string $raw): array
{
    $clean = trim($raw);
    $clean = str_replace(["\r", "\n", "\t", ' ', '　'], '', $clean);
    $parts = explode('#', $clean);

    $head = $parts[0] ?? '';
    $productionRaw = $parts[1] ?? '';
    $expiryRaw = $parts[2] ?? '';

    $productionDate = parse_ymd($productionRaw);
    $expiryDate = parse_ymd($expiryRaw);

    if ($productionDate === null && preg_match('/(\d{8})$/', $head, $matches)) {
        $productionDate = parse_ymd($matches[1]);
    }

    $skuSource = $head;
    if (strlen($skuSource) > 8) {
        $tail = substr($skuSource, -8);
        $tailLooksLikeDate = parse_ymd($tail) !== null;
        $tailMatchesProduction = $productionDate !== null && $tail === compact_ymd($productionDate);

        if ($tailLooksLikeDate || $tailMatchesProduction) {
            $skuSource = substr($skuSource, 0, -8);
        }
    }

    $sku = normalize_sku($skuSource);
    $errors = [];

    if ($clean === '') {
        $errors[] = '没有读取到二维码内容';
    }

    if ($sku === '') {
        $errors[] = '没有解析到 SKU';
    }

    if ($productionDate === null) {
        $errors[] = '没有解析到生产日期';
    }

    if ($expiryDate === null) {
        $errors[] = '没有解析到到期日期';
    }

    return [
        'raw_code' => $clean,
        'sku' => $sku,
        'production_date' => $productionDate,
        'expiry_date' => $expiryDate,
        'days_left' => days_left($expiryDate),
        'errors' => $errors,
        'valid' => count($errors) === 0,
    ];
}

function find_product(PDO $pdo, string $sku): ?array
{
    $stmt = $pdo->prepare(
        'SELECT sku, display_sku, name, category, alert_days, shelf_remove_days
         FROM products
         WHERE sku = :sku'
    );
    $stmt->execute([':sku' => normalize_sku($sku)]);
    $product = $stmt->fetch();

    return $product ?: null;
}

function upsert_product(
    PDO $pdo,
    string $skuInput,
    string $name,
    ?string $category = null,
    $alertDays = null,
    $shelfRemoveDays = null
): bool {
    $sku = normalize_sku($skuInput);
    $displaySku = trim($skuInput);
    $name = trim($name);
    $category = trim((string) $category);
    $categoryRule = find_category_by_name($pdo, $category);

    if ($category !== '' && !$categoryRule) {
        upsert_category($pdo, $category, $alertDays, $shelfRemoveDays);
        $categoryRule = find_category_by_name($pdo, $category);
    }

    $defaultAlertDays = (int) ($categoryRule['early_dispose_days'] ?? 7);
    $defaultShelfRemoveDays = (int) ($categoryRule['shelf_remove_days'] ?? 0);
    $alertDays = normalize_day_count($alertDays, $defaultAlertDays, 0, 365);
    $shelfRemoveDays = normalize_day_count($shelfRemoveDays, $defaultShelfRemoveDays, 0, 365);

    if ($sku === '' || $name === '') {
        return false;
    }

    $now = now_string();
    $stmt = $pdo->prepare(
        'INSERT INTO products (
            sku, display_sku, name, category, alert_days, shelf_remove_days, created_at, updated_at
        ) VALUES (
            :sku, :display_sku, :name, :category, :alert_days, :shelf_remove_days, :created_at, :updated_at
        )
         ON CONFLICT(sku) DO UPDATE SET
            display_sku = excluded.display_sku,
            name = excluded.name,
            category = excluded.category,
            alert_days = excluded.alert_days,
            shelf_remove_days = excluded.shelf_remove_days,
            updated_at = excluded.updated_at'
    );

    return $stmt->execute([
        ':sku' => $sku,
        ':display_sku' => $displaySku !== '' ? $displaySku : $sku,
        ':name' => $name,
        ':category' => $category !== '' ? $category : null,
        ':alert_days' => $alertDays,
        ':shelf_remove_days' => $shelfRemoveDays,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function pick_import_value(array $row, array $aliases, int $fallbackIndex): ?string
{
    foreach ($aliases as $alias) {
        if (array_key_exists($alias, $row)) {
            return trim((string) $row[$alias]);
        }
    }

    return isset($row[$fallbackIndex]) ? trim((string) $row[$fallbackIndex]) : null;
}

function normalize_import_header(string $header): string
{
    $header = trim($header);
    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
    return strtolower($header);
}

function import_products_from_text(PDO $pdo, string $text): array
{
    $lines = preg_split('/\R/u', $text) ?: [];
    $imported = 0;
    $skipped = 0;
    $headers = null;

    $skuAliases = ['sku', '商品编码', '商品条码', '物料编码', '编码'];
    $nameAliases = ['商品名称', '产品名称', '物料名称', 'name', 'product_name', '名称'];
    $categoryAliases = ['分类', '公司分类', '类别', 'category', 'company_category', 'company_category_raw'];
    $alertAliases = ['提醒天数', '临期天数', '提前提醒天数', 'alert_days', 'early_dispose_days'];
    $removeAliases = ['下架天数', '提前下架天数', 'shelf_remove_days'];

    foreach ($lines as $lineNumber => $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        $delimiter = strpos($line, "\t") !== false ? "\t" : ',';
        $fields = array_map('trim', str_getcsv($line, $delimiter));

        if ($headers === null) {
            $firstRow = array_map('normalize_import_header', $fields);
            $headerText = implode('|', $firstRow);

            if (preg_match('/sku|商品|产品|物料|编码|名称|分类|category|alert_days|early_dispose_days/i', $headerText)) {
                $headers = $firstRow;
                continue;
            }

            $headers = [];
        }

        $row = $fields;
        if ($headers !== []) {
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $fields[$index] ?? '';
            }
        }

        $sku = pick_import_value($row, $skuAliases, 0) ?? '';
        $name = pick_import_value($row, $nameAliases, 1) ?? '';
        $category = pick_import_value($row, $categoryAliases, 2);
        $alertDays = pick_import_value($row, $alertAliases, 3);
        $shelfRemoveDays = pick_import_value($row, $removeAliases, 4);

        if ($sku === '' || $name === '') {
            $skipped++;
            continue;
        }

        if (upsert_product($pdo, $sku, $name, $category, $alertDays, $shelfRemoveDays)) {
            $imported++;
        } else {
            $skipped++;
        }
    }

    return ['imported' => $imported, 'skipped' => $skipped];
}

function save_scan(PDO $pdo, array $parsed, int $quantity, ?string $note = null): int
{
    $product = find_product($pdo, $parsed['sku']);
    $stmt = $pdo->prepare(
        'INSERT INTO scans (
            raw_code, sku, production_date, expiry_date,
            product_name_snapshot, quantity, note, created_at
        ) VALUES (
            :raw_code, :sku, :production_date, :expiry_date,
            :product_name_snapshot, :quantity, :note, :created_at
        )'
    );

    $stmt->execute([
        ':raw_code' => $parsed['raw_code'],
        ':sku' => $parsed['sku'],
        ':production_date' => $parsed['production_date'],
        ':expiry_date' => $parsed['expiry_date'],
        ':product_name_snapshot' => $product['name'] ?? null,
        ':quantity' => $quantity,
        ':note' => trim((string) $note) !== '' ? trim((string) $note) : null,
        ':created_at' => now_string(),
    ]);

    return (int) $pdo->lastInsertId();
}

function build_ai_inventory_prompt(PDO $pdo, string $question = ''): string
{
    $stmt = $pdo->query(
        'SELECT s.*, COALESCE(s.product_name_snapshot, p.name) AS product_name,
                p.category, p.alert_days, p.shelf_remove_days
         FROM scans s
         LEFT JOIN products p ON p.sku = s.sku
         ORDER BY s.expiry_date ASC, s.id DESC
         LIMIT 120'
    );
    $rows = $stmt->fetchAll();
    $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
    $metrics = [
        'expired' => ['count' => 0, 'quantity' => 0],
        'urgent' => ['count' => 0, 'quantity' => 0],
        'warning' => ['count' => 0, 'quantity' => 0],
        'ok' => ['count' => 0, 'quantity' => 0],
        'unknown' => ['count' => 0, 'quantity' => 0],
    ];
    $riskLines = [];
    $categoryTotals = [];

    foreach ($rows as $row) {
        $quantity = (int) $row['quantity'];
        $left = days_left($row['expiry_date']);
        $status = expiry_status($left, product_alert_days($row));
        $code = $status['code'];
        $category = $row['category'] ?: '未分类';

        if (!isset($metrics[$code])) {
            $metrics[$code] = ['count' => 0, 'quantity' => 0];
        }

        $metrics[$code]['count'] = ($metrics[$code]['count'] ?? 0) + 1;
        $metrics[$code]['quantity'] = ($metrics[$code]['quantity'] ?? 0) + $quantity;
        $categoryTotals[$category] = ($categoryTotals[$category] ?? 0) + $quantity;

        if (count($riskLines) < 40 && in_array($code, ['expired', 'urgent', 'warning'], true)) {
            $riskLines[] = sprintf(
                '- %s | SKU %s | 分类 %s | 数量 %d | 生产 %s | 到期 %s | 剩余 %s 天 | 状态 %s | 提醒 %d 天',
                $row['product_name'] ?: '未录入商品名',
                $row['sku'],
                $category,
                $quantity,
                display_date($row['production_date']),
                display_date($row['expiry_date']),
                $left === null ? '未知' : (string) $left,
                $status['text'],
                product_alert_days($row)
            );
        }
    }

    arsort($categoryTotals);
    $categoryLines = [];
    foreach (array_slice($categoryTotals, 0, 12, true) as $category => $quantity) {
        $categoryLines[] = "- {$category}: {$quantity}";
    }

    $defaultQuestion = '请根据当前库存保质期记录，给出今天门店优先处理、下架检查和补录 SKU 商品名的建议。';
    $question = trim($question) !== '' ? trim($question) : $defaultQuestion;

    return implode("\n", [
        '你是门店保质期管理助手，请用中文回答，输出要短、清晰、适合店员直接执行。',
        "今天日期：{$today}",
        '',
        '状态汇总：',
        sprintf('- 已过期：%d 批，数量 %d', $metrics['expired']['count'], $metrics['expired']['quantity']),
        sprintf('- 临期：%d 批，数量 %d', $metrics['urgent']['count'], $metrics['urgent']['quantity']),
        sprintf('- 30 天内需优先：%d 批，数量 %d', $metrics['warning']['count'], $metrics['warning']['quantity']),
        sprintf('- 正常：%d 批，数量 %d', $metrics['ok']['count'], $metrics['ok']['quantity']),
        '',
        '分类数量：',
        $categoryLines ? implode("\n", $categoryLines) : '- 暂无分类数据',
        '',
        '风险批次：',
        $riskLines ? implode("\n", $riskLines) : '- 当前没有过期或临期批次',
        '',
        '用户问题：',
        $question,
        '',
        '请按“今天马上处理 / 本周关注 / 数据问题”三段输出，不要编造没有出现的 SKU。',
    ]);
}

function call_minimax_inventory_ai(PDO $pdo, string $question = ''): array
{
    $apiKey = trim((string) get_setting($pdo, 'minimax_token_plan_key', ''));

    if ($apiKey === '') {
        return ['ok' => false, 'message' => '请先在后台保存 MiniMax Token Plan Key。'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => '服务器 PHP 没有启用 cURL，暂时不能调用 MiniMax。'];
    }

    $endpoint = trim((string) get_setting($pdo, 'minimax_endpoint', 'https://api.minimaxi.com/anthropic/v1/messages'));
    $model = trim((string) get_setting($pdo, 'minimax_model', 'MiniMax-M2.7'));
    $endpoint = $endpoint !== '' ? $endpoint : 'https://api.minimaxi.com/anthropic/v1/messages';
    $model = $model !== '' ? $model : 'MiniMax-M2.7';

    $payload = [
        'model' => $model,
        'max_tokens' => 1200,
        'system' => '你是一个帮助星巴克门店做保质期管理的中文助手。',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => build_ai_inventory_prompt($pdo, $question),
                    ],
                ],
            ],
        ],
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        return ['ok' => false, 'message' => 'MiniMax 请求失败：' . $curlError];
    }

    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => 'MiniMax 返回内容无法解析。'];
    }

    if ($httpCode >= 400) {
        $message = $data['error']['message'] ?? $data['message'] ?? ('HTTP ' . $httpCode);
        return ['ok' => false, 'message' => 'MiniMax 返回错误：' . $message];
    }

    $textBlocks = [];
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
            $textBlocks[] = (string) $block['text'];
        }
    }

    $text = trim(implode("\n\n", $textBlocks));

    if ($text === '') {
        return ['ok' => false, 'message' => 'MiniMax 没有返回文本建议。'];
    }

    return ['ok' => true, 'text' => $text, 'model' => $model];
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
