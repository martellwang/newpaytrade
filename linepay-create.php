<?php
/**
 * PAYUNi LINE Pay 幕後 —— 建立交易，取得要顯示成 QR 的網址。
 *
 * 規格見 docs/payuni-linepay.md。與信用卡的差別（都在那份文件裡，這裡只提
 * 影響這支程式的三點）：
 *
 *   1. 回傳的 QRToken 是**網址**，不是圖。收銀機自己把它編成 QR 顯示。
 *   2. 這支成功**不代表收到錢**，只代表 QR 建好了（TradeStatus=0 建立）。
 *      真正的結果要靠 linepay-status.php 查詢或 notify-linepay.php 通知。
 *   3. Version 固定 1.2（信用卡 1.3、查詢 2.0、請退款 1.0，每支都不同）。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payuni_crypto.php';
require_once __DIR__ . '/payuni_error_codes.php';
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

if (!defined('PAYUNI_LINEPAY_URL') || PAYUNI_LINEPAY_URL === '') {
    respond(503, array('status' => 'failed', 'message' => 'LINE Pay 尚未開通'));
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if ($input === null) {
    respond(400, array('status' => 'failed', 'message' => '請求格式不是合法的 JSON'));
}

$merTradeNo = isset($input['merTradeNo']) ? $input['merTradeNo'] : '';
$amount = isset($input['amount']) ? $input['amount'] : null;
$description = isset($input['description']) ? $input['description'] : '';

// LINE Pay 從第一天就強制登入，不繼承刷卡那條路的相容性退路
$posToken = isset($_SERVER['HTTP_X_POS_TOKEN']) ? $_SERVER['HTTP_X_POS_TOKEN'] : '';
$identity = pos_resolve_identity($posToken, false);
if (!$identity['ok']) {
    respond($identity['httpCode'], $identity['body']);
}
$merIdForTrade = $identity['merId'];

// MerTradeNo 規則與信用卡相同：25 碼內、[A-Za-z0-9_-]
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

$conn = null;
// 先給預設值：資料庫連不上時 $existing 仍會被後面的程式讀到
$existing = null;
try {
    $conn = db_connect();
} catch (Exception $e) {
    // 與刷卡一致：資料庫掛掉不擋收款，但要記 log
    error_log('LINE Pay 建立交易時資料庫連線失敗（放行）：' . $e->getMessage());
}

if ($conn) {
    /*
     * 冪等保護：這個訂單編號已經有結論就直接回傳，不要再建一次。
     *
     * 刷卡那邊踩過同樣的坑（OkHttp 自動重送造成一次點擊送出兩次），詳見
     * authorize-direct.php 的註解。LINE Pay 更需要這道保護 —— 重複建立會
     * 產生兩張不同的 QR，客人掃到哪一張是隨機的，而店員的畫面只在看其中一張。
     */
    $existing = db_find_order($conn, $merTradeNo);
    if ($existing && in_array($existing['status'], array('success', 'failed'), true)) {
        error_log("LINE Pay 訂單 {$merTradeNo} 已有結果（{$existing['status']}），不重複建立");
        respond(200, array(
            'status' => $existing['status'],
            'message' => $existing['message'] !== '' ? $existing['message'] : null,
            'merTradeNo' => $merTradeNo,
            'duplicate' => true,
        ));
    }
}

$encryptInfoParams = array(
    'MerID' => $merIdForTrade,
    'MerTradeNo' => $merTradeNo,
    'TradeAmt' => (string) $amount,
    'Timestamp' => (string) time(),
    'ProdDesc' => $description !== '' ? mb_substr($description, 0, 550) : '商品訂單',
    /*
     * 背景通知網址。
     *
     * ⚠️ PAYUNi 只接受 80 / 443 port，而且需要能從外部連進來 ——
     *    本機 XAMPP 收不到通知，只能靠 linepay-status.php 主動查詢。
     *    這正是收銀機以查詢為主、通知為輔的原因。
     */
    'NotifyURL' => PUBLIC_BASE_URL . '/notify-linepay.php',
);

try {
    $encryptInfo = payuni_encrypt_trade_info($encryptInfoParams, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
    $hashInfo = payuni_generate_hash($encryptInfo, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
} catch (Exception $e) {
    error_log('LINE Pay 加密失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統錯誤，請稍後再試'));
}

if ($conn) {
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
                1, $identity['merchantId'], $merIdForTrade, $identity['storeId'],
                $identity['dealerId'], 'linepay');
        } catch (Exception $e) {
            error_log('寫入 LINE Pay pending 訂單失敗：' . $e->getMessage());
        }
    }
}

$postFields = http_build_query(array(
    'MerID' => $merIdForTrade,
    'Version' => '1.2', // LINE Pay 固定 1.2
    'EncryptInfo' => $encryptInfo,
    'HashInfo' => $hashInfo,
    'IsPlatForm' => '1', // 以代理商身分串接
));

$ch = curl_init(PAYUNI_LINEPAY_URL);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/x-www-form-urlencoded',
        'user-agent: payuni',
    ),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
));
$responseBody = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($responseBody === false) {
    /*
     * 建立 QR 失敗，跟刷卡逾時的處理**刻意不同**。
     *
     * 刷卡逾時要當 pending，因為卡可能已經扣款了。這裡不會：QR 都還沒交到
     * 客人手上，沒有人能付款。當成單純失敗、讓店員重試才是對的。
     */
    error_log('呼叫 PAYUNi LINE Pay API 失敗：' . $curlError);
    respond(502, array('status' => 'failed', 'message' => '金流服務暫時無法回應'));
}

$result = json_decode($responseBody, true);

if (!isset($result['Status']) || $result['Status'] !== 'SUCCESS') {
    error_log('LINE Pay 回應非 SUCCESS，完整內容：' . $responseBody);
    $decryptedMessage = null;
    if (!empty($result['EncryptInfo'])) {
        try {
            $failDetail = payuni_decrypt_trade_info($result['EncryptInfo'], PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
            $decryptedMessage = isset($failDetail['Message']) ? $failDetail['Message'] : null;
            error_log('LINE Pay 解密內容：' . json_encode($failDetail, JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            // EncryptInfo 可能是空的，解密失敗就略過
        }
    }
    $message = payuni_resolve_error_message(
        isset($result['Status']) ? $result['Status'] : '',
        $decryptedMessage,
        isset($result['Message']) ? $result['Message'] : null,
        '無法建立 LINE Pay 交易'
    );
    if ($conn) {
        try {
            db_update_order_result($conn, $merTradeNo, 'failed', null, null, null, $message, $responseBody);
        } catch (Exception $e) {
            error_log('更新 LINE Pay 失敗訂單狀態失敗：' . $e->getMessage());
        }
    }
    respond(200, array('status' => 'failed', 'message' => $message));
}

try {
    $detail = payuni_decrypt_trade_info($result['EncryptInfo'], PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
} catch (Exception $e) {
    error_log('解密 LINE Pay 回應失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統錯誤，請稍後再試'));
}

$qrToken = isset($detail['QRToken']) ? $detail['QRToken'] : '';
if ($qrToken === '') {
    // 沒有導頁網址就沒有 QR 可顯示，等於這筆交易根本開不了
    error_log('LINE Pay 回應缺少 QRToken：' . json_encode($detail, JSON_UNESCAPED_UNICODE));
    $message = !empty($detail['Message']) ? $detail['Message'] : '無法建立 LINE Pay 交易';
    if ($conn) {
        try {
            db_update_order_result($conn, $merTradeNo, 'failed', null, null, null, $message, $responseBody);
        } catch (Exception $e) {
            error_log('更新 LINE Pay 失敗訂單狀態失敗：' . $e->getMessage());
        }
    }
    respond(200, array('status' => 'failed', 'message' => $message));
}

$payuniTradeNo = isset($detail['TradeNo']) ? $detail['TradeNo'] : null;
if ($conn && $payuniTradeNo) {
    // 先把 PAYUNi 訂單編號存起來，狀態仍是 pending。
    // 之後查詢或退款都需要這個編號，現在不存的話萬一後面失聯就查不到了。
    try {
        db_update_order_result($conn, $merTradeNo, 'pending', $payuniTradeNo, null, null,
            '已建立 QR，等待客人付款', $responseBody);
    } catch (Exception $e) {
        error_log('更新 LINE Pay pending 訂單失敗：' . $e->getMessage());
    }
}

respond(200, array(
    // 刻意不叫 success —— 這裡只是 QR 建好了，錢還沒收到
    'status' => 'created',
    'merTradeNo' => $merTradeNo,
    'payuniTradeNo' => $payuniTradeNo,
    'amount' => $amount,
    // 收銀機拿這串網址自己編成 QR 顯示
    'qrToken' => $qrToken,
    'qrExpiredTime' => isset($detail['QRExpiredTime']) ? $detail['QRExpiredTime'] : null,
));
