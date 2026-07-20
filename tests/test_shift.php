<?php
/**
 * 開班／交班、交易紀錄、退款權限的端到端測試。
 *
 * 跑法（先確定本機 MySQL 已啟動）：
 *   php -S localhost:8099 -t . &
 *   php -f tests/test_shift.php http://localhost:8099
 *
 * 這支會在資料庫建立測試用的經銷商／客戶／商店／店員與訂單，
 * **跑完會自己清掉**。不會呼叫 PAYUNi，不會產生任何真實交易。
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$base = isset($argv[1]) ? rtrim($argv[1], '/') : 'http://localhost:8099';

$pass = 0;
$fail = 0;

function check($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✓ $label\n";
    } else {
        $fail++;
        echo "  ✗ $label" . ($detail !== '' ? "  → $detail" : '') . "\n";
    }
}

/** 呼叫端點，回傳 array(httpCode, decodedBody) */
function call($method, $url, $headers = array(), $body = null) {
    $ch = curl_init($url);
    $h = array('Content-Type: application/json');
    foreach ($headers as $k => $v) { $h[] = "$k: $v"; }
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 15,
    ));
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($code, json_decode($raw, true), $raw);
}

$conn = db_connect();
db_create_merchants_table_if_not_exists($conn);
db_create_store_staff_table_if_not_exists($conn);

echo "=== 準備測試資料 ===\n";

$suffix = substr((string) time(), -6);
$dealerId = null; $merchantId = null; $storeId = null;

db_save_dealer($conn, 0, "測試經銷商$suffix", "td$suffix", password_hash('x', PASSWORD_DEFAULT), 1, 'test');
$dealerId = mysqli_insert_id($conn);

$customerCode = db_next_customer_code($conn);
$account = "acct$suffix";
$password = 'testpass1234';
db_save_merchant($conn, 0, $dealerId, $customerCode, null, "測試客戶$suffix",
    $account, password_hash($password, PASSWORD_DEFAULT), 1, 'test');
$merchantId = mysqli_insert_id($conn);

db_save_store($conn, 0, $merchantId, "測試商店$suffix", "TESTMER$suffix", 'payuni', 1, 'test');
$storeId = mysqli_insert_id($conn);

// 兩位店員：一位可退款、一位不可
db_save_staff($conn, 0, $storeId, '01', '有權限店長', '1234', 1, 1, 'test');
$mgrId = mysqli_insert_id($conn);
db_save_staff($conn, 0, $storeId, '02', '一般店員', '5678', 0, 1, 'test');
$clerkId = mysqli_insert_id($conn);

echo "  客戶編號 $customerCode / 商店 #$storeId / 店員 #$mgrId #$clerkId\n";

$deviceId = "testdev$suffix";
$api = array('X-API-Key' => BACKEND_API_KEY);

echo "\n=== 1. 收銀機登入 ===\n";
list($code, $r) = call('POST', "$base/pos_login.php", $api, array(
    'customerCode' => $customerCode, 'account' => $account,
    'password' => $password, 'deviceId' => $deviceId,
));
check('登入成功', isset($r['status']) && $r['status'] === 'success', json_encode($r, JSON_UNESCAPED_UNICODE));
$token = isset($r['token']) ? $r['token'] : '';
check('拿到 token', $token !== '');
check('自動綁定唯一的商店', !empty($r['storeName']), json_encode($r, JSON_UNESCAPED_UNICODE));

$auth = $api + array('X-POS-Token' => $token);

echo "\n=== 2. 班次：一開始沒人開班 ===\n";
list($code, $r) = call('GET', "$base/pos-shift.php?action=status", $auth);
check('查詢成功', $code === 200 && $r['status'] === 'success', $code . ' ' . json_encode($r));
check('onShift = false', isset($r['onShift']) && $r['onShift'] === false);

echo "\n=== 3. 開班：錯誤的 PIN 要被擋 ===\n";
list($code, $r) = call('POST', "$base/pos-shift.php?action=start", $auth,
    array('staffCode' => '01', 'pin' => '9999'));
check('PIN 錯誤被拒', isset($r['status']) && $r['status'] === 'failed', json_encode($r, JSON_UNESCAPED_UNICODE));
check('訊息不透露是工號錯還是 PIN 錯',
    isset($r['message']) && strpos($r['message'], '工號或 PIN') !== false, isset($r['message']) ? $r['message'] : '');

list($code, $r) = call('POST', "$base/pos-shift.php?action=start", $auth,
    array('staffCode' => '99', 'pin' => '1234'));
check('不存在的工號被拒', isset($r['status']) && $r['status'] === 'failed');

echo "\n=== 4. 開班：正確的工號與 PIN ===\n";
list($code, $r) = call('POST', "$base/pos-shift.php?action=start", $auth,
    array('staffCode' => '01', 'pin' => '1234'));
check('開班成功', isset($r['onShift']) && $r['onShift'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
check('回傳店員姓名', isset($r['staffName']) && $r['staffName'] === '有權限店長');
check('回傳退款權限 = true', isset($r['canRefund']) && $r['canRefund'] === true);
check('新班次金額為 0', isset($r['total']) && (int) $r['total'] === 0);

echo "\n=== 5. 交易要自動帶上經手人 ===\n";
// 直接寫一筆成功訂單，模擬收款完成（不呼叫 PAYUNi）
$merTradeNo = "TEST$suffix" . '01';
db_insert_pending_order($conn, $merTradeNo, 350, $deviceId, null, 1,
    $merchantId, "TESTMER$suffix", $storeId, $dealerId, 'credit', $mgrId, '有權限店長');
db_update_order_result($conn, $merTradeNo, 'success', 'PU123', 'AUTH01', '4242', null, null);

list($code, $r) = call('GET', "$base/pos-shift.php?action=status", $auth);
check('班次彙總含這筆交易', isset($r['total']) && (int) $r['total'] === 350,
    'total=' . (isset($r['total']) ? $r['total'] : '?'));
check('筆數為 1', isset($r['count']) && (int) $r['count'] === 1);

echo "\n=== 6. 交易紀錄 ===\n";
list($code, $r) = call('GET', "$base/pos-orders.php?scope=device&deviceId=$deviceId", $auth);
check('查詢成功', $code === 200 && $r['status'] === 'success', $code . ' ' . json_encode($r));
check('查到 1 筆', isset($r['orders']) && count($r['orders']) === 1);
check('顯示經手人', isset($r['orders'][0]['staffName']) && $r['orders'][0]['staffName'] === '有權限店長');
check('彙總淨額 350', isset($r['summary']['net']) && (int) $r['summary']['net'] === 350);

echo "\n=== 7. 跨商店查詢要被擋 ===\n";
list($code, $r) = call('GET', "$base/linepay-status.php?merTradeNo=NOSUCHORDER123", $auth);
check('查無此交易回 404', $code === 404, "code=$code");

echo "\n=== 8. 退款權限 ===\n";
// 8a. 目前是有權限的店長 → 應該通過權限檢查（會卡在後面的商店代號／PAYUNi，不是權限）
list($code, $r) = call('POST', "$base/refund.php", $auth, array('merTradeNo' => $merTradeNo));
check('有權限者不被權限擋下',
    !(isset($r['noPermission']) || isset($r['needShift'])),
    json_encode($r, JSON_UNESCAPED_UNICODE));

// 8b. 換成沒有退款權限的店員
call('POST', "$base/pos-shift.php?action=end", $auth);
call('POST', "$base/pos-shift.php?action=start", $auth,
    array('staffCode' => '02', 'pin' => '5678'));
list($code, $r) = call('POST', "$base/refund.php", $auth, array('merTradeNo' => $merTradeNo));
check('無權限者被擋下', $code === 403 && isset($r['noPermission']),
    "code=$code " . json_encode($r, JSON_UNESCAPED_UNICODE));

// 8c. 沒開班
call('POST', "$base/pos-shift.php?action=end", $auth);
list($code, $r) = call('POST', "$base/refund.php", $auth, array('merTradeNo' => $merTradeNo));
check('沒開班時被擋下', $code === 403 && isset($r['needShift']),
    "code=$code " . json_encode($r, JSON_UNESCAPED_UNICODE));

echo "\n=== 9. 交班 ===\n";
call('POST', "$base/pos-shift.php?action=start", $auth,
    array('staffCode' => '01', 'pin' => '1234'));
list($code, $r) = call('POST', "$base/pos-shift.php?action=end", $auth);
check('交班成功', isset($r['ended']) && $r['ended'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
check('交班後 onShift = false', isset($r['onShift']) && $r['onShift'] === false);

list($code, $r) = call('GET', "$base/pos-shift.php?action=status", $auth);
check('再查已無班次', isset($r['onShift']) && $r['onShift'] === false);

echo "\n=== 10. 沒開班仍可收款（經手人為空）===\n";
$merTradeNo2 = "TEST$suffix" . '02';
db_insert_pending_order($conn, $merTradeNo2, 100, $deviceId, null, 1,
    $merchantId, "TESTMER$suffix", $storeId, $dealerId, 'credit', null, null);
db_update_order_result($conn, $merTradeNo2, 'success', 'PU124', 'AUTH02', '4242', null, null);
list($code, $r) = call('GET', "$base/pos-orders.php?scope=device&deviceId=$deviceId", $auth);
check('查到 2 筆', isset($r['orders']) && count($r['orders']) === 2,
    'count=' . (isset($r['orders']) ? count($r['orders']) : '?'));
$noStaff = null;
foreach ($r['orders'] as $o) { if ($o['merTradeNo'] === $merTradeNo2) { $noStaff = $o; } }
check('該筆經手人為空', $noStaff !== null && empty($noStaff['staffName']));

echo "\n=== 11. 感應卡開班 ===\n";
// 給店長綁一張卡（PIN 留空代表不修改）
db_save_staff($conn, $mgrId, $storeId, '01', '有權限店長', '', 1, 1, 'test', '717E6632', 1);

call('POST', "$base/pos-shift.php?action=end", $auth);
list($code, $r) = call('POST', "$base/pos-shift.php?action=start", $auth,
    array('cardUid' => '717E6632', 'pin' => '1234'));
check('刷卡 + 正確 PIN 可開班', isset($r['onShift']) && $r['onShift'] === true,
    json_encode($r, JSON_UNESCAPED_UNICODE));
check('身分正確', isset($r['staffName']) && $r['staffName'] === '有權限店長');

call('POST', "$base/pos-shift.php?action=end", $auth);
list($code, $r) = call('POST', "$base/pos-shift.php?action=start", $auth,
    array('cardUid' => '717E6632', 'pin' => '0000'));
check('刷卡但 PIN 錯要被擋', isset($r['status']) && $r['status'] === 'failed');
check('訊息指向卡片而非工號',
    isset($r['message']) && strpos($r['message'], '卡片或 PIN') !== false,
    isset($r['message']) ? $r['message'] : '');

list($code, $r) = call('POST', "$base/pos-shift.php?action=start", $auth,
    array('cardUid' => 'DEADBEEF', 'pin' => '1234'));
check('沒登記過的卡被擋', isset($r['status']) && $r['status'] === 'failed');

list($code, $r) = call('POST', "$base/pos-shift.php?action=start", $auth,
    array('cardUid' => 'ZZZZ', 'pin' => '1234'));
check('格式不對的 UID 被擋', $code === 400, "code=$code");

call('POST', "$base/pos-shift.php?action=end", $auth);
list($code, $r) = call('POST', "$base/pos-shift.php?action=start", $auth,
    array('staffCode' => '02', 'pin' => '5678'));
check('原本的工號方式仍然可用', isset($r['onShift']) && $r['onShift'] === true,
    json_encode($r, JSON_UNESCAPED_UNICODE));

echo "\n=== 12. 收銀機建檔 ===\n";
// 目前開班的是「一般店員」，沒有建檔權限
list($code, $r) = call('POST', "$base/pos-shift.php?action=enroll", $auth,
    array('cardUid' => 'AABBCCDD', 'name' => '新人', 'pin' => '2468'));
check('沒有建檔權限者被擋', $code === 403 && isset($r['noPermission']),
    "code=$code " . json_encode($r, JSON_UNESCAPED_UNICODE));

// 換成有建檔權限的店長
call('POST', "$base/pos-shift.php?action=end", $auth);
call('POST', "$base/pos-shift.php?action=start", $auth,
    array('cardUid' => '717E6632', 'pin' => '1234'));
list($code, $r) = call('POST', "$base/pos-shift.php?action=enroll", $auth,
    array('cardUid' => 'AABBCCDD', 'name' => '新人', 'pin' => '2468'));
check('有建檔權限者可以登記新卡', isset($r['status']) && $r['status'] === 'success',
    json_encode($r, JSON_UNESCAPED_UNICODE));
check('自動編出工號', !empty($r['staffCode']));

list($code, $r) = call('POST', "$base/pos-shift.php?action=enroll", $auth,
    array('cardUid' => 'AABBCCDD', 'name' => '另一人', 'pin' => '1357'));
check('同一張卡不能登記給兩個人',
    isset($r['message']) && strpos($r['message'], '已經登記給') !== false,
    isset($r['message']) ? $r['message'] : '');

call('POST', "$base/pos-shift.php?action=end", $auth);
list($code, $r) = call('POST', "$base/pos-shift.php?action=start", $auth,
    array('cardUid' => 'AABBCCDD', 'pin' => '2468'));
check('新登記的卡可以開班', isset($r['staffName']) && $r['staffName'] === '新人',
    json_encode($r, JSON_UNESCAPED_UNICODE));
call('POST', "$base/pos-shift.php?action=end", $auth);

echo "\n=== 清除測試資料 ===\n";
mysqli_query($conn, "DELETE FROM orders WHERE store_id = $storeId");
mysqli_query($conn, "DELETE FROM refunds WHERE mer_trade_no LIKE 'TEST$suffix%'");
mysqli_query($conn, "DELETE FROM merchant_sessions WHERE merchant_id = $merchantId");
mysqli_query($conn, "DELETE FROM store_staff WHERE store_id = $storeId");
mysqli_query($conn, "DELETE FROM merchant_stores WHERE id = $storeId");
mysqli_query($conn, "DELETE FROM merchants WHERE id = $merchantId");
mysqli_query($conn, "DELETE FROM dealers WHERE id = $dealerId");
mysqli_query($conn, "DELETE FROM pos_device_locks WHERE device_id = '$deviceId'");
mysqli_query($conn, "DELETE FROM devices WHERE device_id = '$deviceId'");
echo "  已清除\n";

echo "\n" . str_repeat('=', 40) . "\n";
echo "通過 $pass 項，失敗 $fail 項\n";
exit($fail === 0 ? 0 : 1);
