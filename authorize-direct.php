<?php
/**
 * PAYUNi 統一金流 — 信用卡幕後授權（CREDIT），傳統程序式 PHP 寫法。
 *
 * 對應原本 Node.js 版的 backend/payuniDirectAuth.js，欄位、加解密邏輯、
 * IsPlatForm=1、CVC 留空的做法都已經在正式環境驗證過，直接照搬過來。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payuni_crypto.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

function respond($statusCode, $body) {
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// 驗證 App 呼叫時帶的 X-API-Key，防止外部直接呼叫這支會把卡號送去
// PAYUNi 授權的 API。用 $_SERVER['HTTP_X_API_KEY'] 讀取，不要用
// getallheaders()['X-API-Key']——getallheaders() 回傳的 key 大小寫
// 正規化方式不保證跟原始 header 名稱一致（實測會變成 'X-Api-Key'），
// 用陣列 key 直接比對容易讀不到值。
$apiKey = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '';
if ($apiKey === '' || $apiKey !== BACKEND_API_KEY) {
    respond(401, array('status' => 'failed', 'message' => 'unauthorized'));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, array('status' => 'failed', 'message' => 'method not allowed'));
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if ($input === null) {
    respond(400, array('status' => 'failed', 'message' => '請求格式不是合法的 JSON'));
}

$merTradeNo = isset($input['merTradeNo']) ? $input['merTradeNo'] : '';
$amount = isset($input['amount']) ? $input['amount'] : null;
$description = isset($input['description']) ? $input['description'] : '';
$cardNumber = isset($input['cardNumber']) ? $input['cardNumber'] : '';
$expiryMonth = isset($input['expiryMonth']) ? $input['expiryMonth'] : '';
$expiryYear = isset($input['expiryYear']) ? $input['expiryYear'] : '';
$cvv = isset($input['cvv']) ? $input['cvv'] : '';

if ($merTradeNo === '' || $amount === null || $cardNumber === '' || $expiryMonth === '' || $expiryYear === '') {
    respond(400, array('status' => 'failed', 'message' => '缺少必要付款資訊'));
}

// MerTradeNo 規則：長度 25 內，只能是 [A-Za-z0-9_-]，10 分鐘內不可重複
if (!preg_match('/^[A-Za-z0-9_-]{1,25}$/', $merTradeNo)) {
    respond(400, array('status' => 'failed', 'message' => 'merTradeNo 格式不正確'));
}

if (!is_numeric($amount) || $amount <= 0) {
    respond(400, array('status' => 'failed', 'message' => 'amount 必須是大於 0 的數字'));
}

// CardExpired 格式為 MMYY（月在前、年在後，兩碼年份）
$cardExpired = str_pad($expiryMonth, 2, '0', STR_PAD_LEFT) . substr((string) $expiryYear, -2);

$encryptInfoParams = array(
    'MerID' => PAYUNI_MER_ID,
    'MerTradeNo' => $merTradeNo,
    'TradeAmt' => (string) round($amount),
    'Timestamp' => (string) time(),
    'CardNo' => $cardNumber,
    'CardExpired' => $cardExpired,
);
// NFC 讀卡沒有 CVC 的情境：完全不帶這個欄位，搭配最外層 IsPlatForm=1
// 才能讓 PAYUNi 真正接受空 CVC（已於正式環境實測驗證）。
if ($cvv !== '') {
    $encryptInfoParams['CardCVC'] = $cvv;
}
$encryptInfoParams['ProdDesc'] = $description !== '' ? $description : '商品訂單';
$encryptInfoParams['NotifyURL'] = PUBLIC_BASE_URL . '/notify-direct.php';

try {
    $encryptInfo = payuni_encrypt_trade_info($encryptInfoParams, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
    $hashInfo = payuni_generate_hash($encryptInfo, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
} catch (Exception $e) {
    error_log('PAYUNi 加密失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統錯誤，請稍後再試'));
}

// 交易送出前先記一筆 pending，就算後面連線逾時也有紀錄可查
$conn = null;
try {
    $conn = db_connect();
    db_insert_pending_order($conn, $merTradeNo, round($amount));
} catch (Exception $e) {
    error_log('寫入 pending 訂單失敗：' . $e->getMessage());
    // 資料庫寫入失敗不擋交易，continue，但要記 log 之後追查
}

$postFields = http_build_query(array(
    'MerID' => PAYUNI_MER_ID,
    'Version' => '1.3',
    'EncryptInfo' => $encryptInfo,
    'HashInfo' => $hashInfo,
    // PAYUNi 客服口頭提供（未寫在技術文件裡）：最外層帶這個參數，
    // CardCVC 才能真正留空。
    'IsPlatForm' => '1',
));

$ch = curl_init(PAYUNI_DIRECT_AUTH_URL);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/x-www-form-urlencoded',
        'user-agent: payuni',
    ),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 65, // 文件提到第 60 秒未回應會轉為 UNKNOWN，稍微留寬一點
));
$responseBody = curl_exec($ch);
$curlErrno = curl_errno($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($responseBody === false) {
    if ($curlErrno === CURLE_OPERATION_TIMEDOUT) {
        // 逾時：依文件建議，之後可能還會有背景通知，或 15 分鐘後查詢
        if ($conn) {
            try {
                db_update_order_result($conn, $merTradeNo, 'pending', null, null, null, '交易處理中，逾時未回應', null);
            } catch (Exception $e) {
                error_log('更新逾時訂單狀態失敗：' . $e->getMessage());
            }
        }
        error_log("交易 $merTradeNo 逾時未回應，等待背景通知或後續查詢");
        respond(200, array('status' => 'pending', 'message' => '交易處理中，請稍後查詢', 'merTradeNo' => $merTradeNo));
    }
    error_log('呼叫 PAYUNi 幕後授權 API 失敗：' . $curlError);
    respond(502, array('status' => 'failed', 'message' => '金流服務暫時無法回應'));
}

$result = json_decode($responseBody, true);

// 最外層 Status 若不是 SUCCESS，代表請求本身有問題（簽章錯誤、格式錯誤、
// IP 白名單、CVC 必填等）
if (!isset($result['Status']) || $result['Status'] !== 'SUCCESS') {
    error_log('PAYUNi 回應非 SUCCESS，完整內容：' . $responseBody);

    $decryptedMessage = null;
    if (!empty($result['EncryptInfo'])) {
        try {
            $failDetail = payuni_decrypt_trade_info($result['EncryptInfo'], PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
            $decryptedMessage = isset($failDetail['Message']) ? $failDetail['Message'] : null;
            error_log('解密內容：' . json_encode($failDetail, JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            // EncryptInfo 可能是空的或格式不同，解密失敗就略過，不影響主要回應
        }
    }

    $message = $decryptedMessage ?: (isset($result['Message']) ? $result['Message'] : '請求失敗');
    if ($conn) {
        try {
            db_update_order_result($conn, $merTradeNo, 'failed', null, null, null, $message, $responseBody);
        } catch (Exception $e) {
            error_log('更新失敗訂單狀態失敗：' . $e->getMessage());
        }
    }
    respond(200, array('status' => 'failed', 'message' => $message));
}

try {
    $detail = payuni_decrypt_trade_info($result['EncryptInfo'], PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
} catch (Exception $e) {
    error_log('解密 PAYUNi 回應失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統錯誤，請稍後再試'));
}

// Status 是請求層級結果，TradeStatus 才是真正的付款狀態，兩者都要確認
if (isset($detail['Status']) && $detail['Status'] === 'UNKNOWN') {
    if ($conn) {
        try {
            db_update_order_result($conn, $merTradeNo, 'pending', null, null, null, '交易處理中，請稍後查詢或等待背景通知', $responseBody);
        } catch (Exception $e) {
            error_log('更新 pending 訂單狀態失敗：' . $e->getMessage());
        }
    }
    respond(200, array(
        'status' => 'pending',
        'message' => '交易處理中，請稍後查詢或等待背景通知',
        'merTradeNo' => $merTradeNo,
    ));
}

if (isset($detail['Status']) && $detail['Status'] === 'SUCCESS' && isset($detail['TradeStatus']) && $detail['TradeStatus'] === '1') {
    $payuniTradeNo = isset($detail['TradeNo']) ? $detail['TradeNo'] : null;
    $authCode = isset($detail['AuthCode']) ? $detail['AuthCode'] : null;
    $card4No = isset($detail['Card4No']) ? $detail['Card4No'] : null;
    if ($conn) {
        try {
            db_update_order_result($conn, $merTradeNo, 'success', $payuniTradeNo, $authCode, $card4No, null, $responseBody);
        } catch (Exception $e) {
            error_log('更新成功訂單狀態失敗：' . $e->getMessage());
        }
    }
    respond(200, array(
        'status' => 'success',
        'payuniTradeNo' => $payuniTradeNo,
        'authCode' => $authCode,
        'card4No' => $card4No,
    ));
}

$message = isset($detail['Message']) ? $detail['Message'] : (isset($detail['ResCodeMsg']) ? $detail['ResCodeMsg'] : '交易未通過');
if ($conn) {
    try {
        db_update_order_result($conn, $merTradeNo, 'failed', null, null, null, $message, $responseBody);
    } catch (Exception $e) {
        error_log('更新失敗訂單狀態失敗：' . $e->getMessage());
    }
}
respond(200, array('status' => 'failed', 'message' => $message));
