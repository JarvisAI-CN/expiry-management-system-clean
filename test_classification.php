<?php
// test_classification.php

function get_status($category_type, $category_rule_json, $removal_buffer, $expiry_date) {
    $rule = json_decode($category_rule_json, true);
    $needBuffer = $rule['need_buffer'] ?? true;
    
    $effectiveBuffer = $needBuffer ? (int)$removal_buffer : 0;
    $removalDate = date('Y-m-d', strtotime("$expiry_date - $effectiveBuffer days"));
    
    $today = date('Y-m-d');
    $daysToRemoval = (strtotime($removalDate) - strtotime($today)) / 86400;
    
    $ai_status_text = "";
    if ($daysToRemoval < 0) {
        if ($category_type === 'coffee') {
            $ai_status_text = "⚠️ 停止销售 (可赠送)";
        } else {
            $ai_status_text = "🔴 立即下架/报废";
        }
    } elseif ($daysToRemoval <= 7) {
        $ai_status_text = "🟡 临期紧急";
    } else {
        $ai_status_text = "🟢 状态良好";
    }
    
    return [
        'removal_date' => $removalDate,
        'days_to_removal' => (int)$daysToRemoval,
        'ai_status' => $ai_status_text
    ];
}

$today = date('Y-m-d');
echo "Today: $today\n\n";

// Test Case 1: Snack, Buffer 7, Expiry in 5 days
$expiry1 = date('Y-m-d', strtotime('+5 days'));
$res1 = get_status('snack', '{"need_buffer":true, "scrap_on_removal":true}', 7, $expiry1);
echo "Test 1 (Snack): Expiry $expiry1, Buffer 7 -> Expected: 🔴 立即下架/报废, Got: " . $res1['ai_status'] . " (Days to removal: " . $res1['days_to_removal'] . ")\n";

// Test Case 2: Coffee, Buffer 3, Expiry in 1 day
$expiry2 = date('Y-m-d', strtotime('+1 day'));
$res2 = get_status('coffee', '{"need_buffer":true, "scrap_on_removal":false, "allow_gift":true}', 3, $expiry2);
echo "Test 2 (Coffee): Expiry $expiry2, Buffer 3 -> Expected: ⚠️ 停止销售 (可赠送), Got: " . $res2['ai_status'] . " (Days to removal: " . $res2['days_to_removal'] . ")\n";

// Test Case 3: Material, Buffer 7, Expiry in 5 days
$expiry3 = date('Y-m-d', strtotime('+5 days'));
$res3 = get_status('material', '{"need_buffer":false, "scrap_on_removal":false}', 7, $expiry3);
echo "Test 3 (Material): Expiry $expiry3, Buffer 7 -> Expected: 🟡 临期紧急, Got: " . $res3['ai_status'] . " (Days to removal: " . $res3['days_to_removal'] . ")\n";

// Test Case 4: Snack, Buffer 7, Expiry in 20 days
$expiry4 = date('Y-m-d', strtotime('+20 days'));
$res4 = get_status('snack', '{"need_buffer":true, "scrap_on_removal":true}', 7, $expiry4);
echo "Test 4 (Snack): Expiry $expiry4, Buffer 7 -> Expected: 🟢 状态良好, Got: " . $res4['ai_status'] . " (Days to removal: " . $res4['days_to_removal'] . ")\n";
?>
