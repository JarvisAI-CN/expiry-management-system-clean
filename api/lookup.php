<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$code = $_POST['code'] ?? '';

if ($code === '') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $code = is_array($body) ? (string) ($body['code'] ?? '') : '';
}

$pdo = db();
$parsed = parse_qr_code((string) $code);
$product = $parsed['sku'] !== '' ? find_product($pdo, $parsed['sku']) : null;
$status = expiry_status($parsed['days_left'], product_alert_days($product));

json_response([
    'ok' => $parsed['valid'],
    'parsed' => $parsed,
    'product' => $product,
    'status' => $status,
    'message' => $parsed['valid'] ? '识别成功' : implode('；', $parsed['errors']),
]);
