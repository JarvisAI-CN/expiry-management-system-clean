<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$body = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!is_array($body)) {
    $body = [];
}

$rawCode = (string) ($body['raw_code'] ?? $_POST['raw_code'] ?? '');
$quantity = (int) ($body['quantity'] ?? $_POST['quantity'] ?? 0);
$note = (string) ($body['note'] ?? $_POST['note'] ?? '');
$parsed = parse_qr_code($rawCode);

if (!$parsed['valid']) {
    json_response([
        'ok' => false,
        'message' => implode('；', $parsed['errors']),
        'parsed' => $parsed,
    ], 422);
}

if ($quantity < 1) {
    json_response([
        'ok' => false,
        'message' => '数量至少为 1',
    ], 422);
}

$pdo = db();
$id = save_scan($pdo, $parsed, $quantity, $note);
$product = find_product($pdo, $parsed['sku']);

json_response([
    'ok' => true,
    'message' => '已保存',
    'id' => $id,
    'product' => $product,
    'parsed' => $parsed,
]);
