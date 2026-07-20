<?php
/**
 * 行動支付掃碼收款 —— 建立交易並回傳要編成 QR 的中轉網址。
 *
 * ── ⚠️ 這支不會呼叫 PAYUNi ────────────────────────────────────
 *
 * UPP 的請求方式是 **Form Post，而且是由客人的瀏覽器送出的**，不是我們的
 * 伺服器。所以這裡只做兩件事：建立訂單紀錄、建立付款連結，然後把中轉頁的
 * 網址回給收銀機。真正接觸 PAYUNi 的是 pay-redirect.php（客人掃碼之後）。
 *
 * 附帶好處：QR 出現得比 LINE Pay 快，因為完全不需要等外部網路往返。
 *
 * 代價是**這裡無法預先驗證商店有沒有開通那些錢包** —— 錯誤要等客人掃碼、
 * 到了 UPP 頁面才會出現。這是 UPP 的機制決定的，不是可以選的。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pos_auth.php';

header('Content-Type: application/json; charset=utf-8');

function respond($statusCode, $body) {
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$apiKey = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '';
if ($apiKey === '' || $apiKey !== BACKEND_API_KEY) {
    respond(401, array('status' => 'failed', 'message' => 'unauthorized'));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, array('status' => 'failed', 'message' => 'method not allowed'));
}

if (!defined('PAYUNI_UPP_URL') || PAYUNI_UPP_URL === '') {
    respond(503, array('status' => 'failed', 'message' => '行動支付尚未開通'));
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if ($input === null) {
    respond(400, array('status' => 'failed', 'message' => '請求格式不是合法的 JSON'));
}

$merTradeNo = isset($input['merTradeNo']) ? $input['merTradeNo'] : '';
$amount = isset($input['amount']) ? $input['amount'] : null;

$posToken = isset($_SERVER['HTTP_X_POS_TOKEN']) ? $_SERVER['HTTP_X_POS_TOKEN'] : '';
$identity = pos_resolve_identity($posToken, false);
if (!$identity['ok']) {
    respond($identity['httpCode'], $identity['body']);
}

if (!preg_match('/^[A-Za-z0-9_-]{1,25}$/', $merTradeNo)) {
    respond(400, array('status' => 'failed', 'message' => 'merTradeNo 格式不正確'));
}
if (!is_numeric($amount) || $amount <= 0) {
    respond(400, array('status' => 'failed', 'message' => 'amount 必須是大於 0 的數字'));
}
$amount = (int) round($amount);

$device = isset($input['device']) && is_array($input['device']) ? $input['device'] : null;
$deviceId = ($device && !empty($device['deviceId'])) ? substr((string) $device['deviceId'], 0, 64) : null;
$deviceSerial = ($device && !empty($device['serialNo'])) ? substr((string) $device['serialNo'], 0, 64) : null;

/*
 * 這裡與刷卡、LINE Pay 不同：資料庫連不上就**直接失敗**。
 *
 * 那兩條路上「訂單紀錄」只是輔助，交易本身仍會送到 PAYUNi。這條路不一樣 ——
 * 付款連結本身就存在資料庫裡，沒有資料庫就產不出可用的 QR，硬放行只會給
 * 店員一張掃了必定失效的圖。
 */
try {
    $conn = db_connect();
    db_create_payment_links_table_if_not_exists($conn);
} catch (Exception $e) {
    error_log('行動支付建立交易時資料庫失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統忙碌中，請稍後再試'));
}

// 冪等：已有結論的訂單不重新產生連結
$existing = db_find_order($conn, $merTradeNo);
if ($existing && in_array($existing['status'], array('success', 'failed'), true)) {
    error_log("行動支付訂單 {$merTradeNo} 已有結果（{$existing['status']}），不重複建立");
    respond(200, array(
        'status' => $existing['status'],
        'message' => $existing['message'] !== '' ? $existing['message'] : null,
        'merTradeNo' => $merTradeNo,
        'duplicate' => true,
    ));
}

if ($device && $deviceId) {
    try {
        db_upsert_device($conn, array(
            'deviceId' => $deviceId,
            'serialNo' => $deviceSerial,
            'brand' => isset($device['brand']) ? $device['brand'] : null,
            'manufacturer' => isset($device['manufacturer']) ? $device['manufacturer'] : null,
            'model' => isset($device['model']) ? $device['model'] : null,
            'product' => isset($device['product']) ? $device['product'] : null,
            'androidVersion' => isset($device['androidVersion']) ? $device['androidVersion'] : null,
            'androidSdk' => isset($device['androidSdk']) ? $device['androidSdk'] : null,
            'appVersion' => isset($device['appVersion']) ? $device['appVersion'] : null,
            'hasNfc' => isset($device['hasNfc']) ? $device['hasNfc'] : null,
            'nfcEnabled' => isset($device['nfcEnabled']) ? $device['nfcEnabled'] : null,
            'screen' => isset($device['screen']) ? $device['screen'] : null,
        ));
    } catch (Exception $e) {
        error_log('登記裝置失敗：' . $e->getMessage());
    }
}

if (!$existing) {
    try {
        db_insert_pending_order($conn, $merTradeNo, $amount, $deviceId, $deviceSerial,
            1, $identity['merchantId'], $identity['merId'], $identity['storeId'],
            $identity['dealerId'], 'wallet', $identity['staffId'], $identity['staffName']);
    } catch (Exception $e) {
        error_log('寫入行動支付 pending 訂單失敗：' . $e->getMessage());
        respond(500, array('status' => 'failed', 'message' => '系統忙碌中，請稍後再試'));
    }
}

// 店名要顯示給客人看，讓他確認掃到的是正確的店家
$storeName = null;
try {
    $store = db_find_store($conn, $identity['storeId']);
    if ($store && !empty($store['name'])) {
        $storeName = $store['name'];
    }
} catch (Exception $e) {
    error_log('查詢商店名稱失敗：' . $e->getMessage());
}

try {
    $token = db_create_payment_link($conn, $merTradeNo, $amount,
        $identity['merchantId'], $identity['storeId'], $identity['merId'], $storeName);
} catch (Exception $e) {
    error_log('建立付款連結失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統忙碌中，請稍後再試'));
}

respond(200, array(
    // 與 LINE Pay 一致：叫 created 不叫 success，這時錢還沒收到
    'status' => 'created',
    'merTradeNo' => $merTradeNo,
    'amount' => $amount,
    // 收銀機把這串網址編成 QR 顯示
    'qrToken' => PUBLIC_BASE_URL . '/pay.php?t=' . $token,
    'expiresInSeconds' => PAYMENT_LINK_TTL,
));
