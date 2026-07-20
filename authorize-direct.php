<?php
/**
 * PAYUNi 統一金流 — 信用卡幕後授權（CREDIT），傳統程序式 PHP 寫法。
 *
 * 對應原本 Node.js 版的 backend/payuniDirectAuth.js，欄位、加解密邏輯、
 * IsPlatForm=1、CVC 留空的做法都已經在正式環境驗證過，直接照搬過來。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payuni_crypto.php';
require_once __DIR__ . '/payuni_error_codes.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rate_limit.php';

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

/*
 * ── 收銀機登入身分 ────────────────────────────────────────────────
 *
 * 收銀機必須先登入某個客戶（見 pos_login.php），交易時帶著 token。
 * 用哪個商店代號送出**由後端依 token 決定**，不是由 App 指定 ——
 * 讓 App 自己帶 MerID 等於任何拿到 API Key 的人都能冒用別家商店收款。
 *
 * 相容處理：token 留空時退回 config.php 的 PAYUNI_MER_ID。這是為了讓
 * 既有的收銀機在更新 App 之前還能繼續收款；等所有機器都換上登入流程
 * 之後，這段可以移除並改成強制要求 token。
 */
$posToken = isset($_SERVER['HTTP_X_POS_TOKEN']) ? $_SERVER['HTTP_X_POS_TOKEN'] : '';
$session = null;
$merchantId = null;
$storeId = null;
$dealerId = null;
$merIdForTrade = defined('PAYUNI_MER_ID') ? PAYUNI_MER_ID : '';

if ($posToken !== '') {
    try {
        $conn0 = db_connect();
        db_create_merchants_table_if_not_exists($conn0);
        $session = db_find_session_by_token($conn0, $posToken);
    } catch (Exception $e) {
        error_log('查詢收銀機登入身分失敗：' . $e->getMessage());
    }
    if (!$session) {
        // token 無效、客戶或商店已停用 —— 要明確擋下，不能默默退回預設
        // 商店代號，那會讓已停用的客戶還能繼續收款
        respond(401, array(
            'status' => 'failed',
            'message' => '收銀機登入已失效，請重新登入',
            'needLogin' => true,
        ));
    }
    if (empty($session['store_id']) || empty($session['mer_id'])) {
        // 登入了但還沒選分店。這種 token 不能拿來交易 —— 沒有商店代號
        // 就不知道這筆錢要進哪家店。
        respond(400, array(
            'status' => 'failed',
            'message' => '尚未選擇商店，請重新登入並選擇',
            'needStoreSelection' => true,
        ));
    }
    $merchantId = (int) $session['merchant_id'];
    $storeId = (int) $session['store_id'];
    $dealerId = $session['dealer_id'] !== null ? (int) $session['dealer_id'] : null;
    $merIdForTrade = $session['mer_id'];
}

if ($merIdForTrade === '') {
    respond(500, array('status' => 'failed', 'message' => '系統尚未設定商店代號'));
}

/*
 * 分期期數（PAYUNi 的 CardInst，放在 EncryptInfo 內）。
 *   1  = 一次付清（PAYUNi 的預設值）
 *   3 / 6 / 9 / 12 / 18 / 24 / 30 = 分期
 *
 * 用白名單擋掉其他值：帶了不合法的期數只會拿到 CREDIT02020
 *（信用卡分期數，期數格式錯誤），在收銀台前才失敗代價太高，先擋在這裡。
 *
 * ⚠️ 期數合法不代表一定能刷過，還有兩層限制不在我們控制範圍：
 *   - CREDIT03003 商店未提供信用卡分期 —— 帳號層級，要先向 PAYUNi 申請
 *   - CREDIT02035 不提供國外卡分期 —— 看客人那張卡
 * 這兩種都會在授權階段失敗，錯誤訊息會照實回傳給收銀員。
 */
$allowedInst = array(1, 3, 6, 9, 12, 18, 24, 30);
$cardInst = isset($input['cardInst']) ? $input['cardInst'] : 1;
if (!is_numeric($cardInst) || !in_array((int) $cardInst, $allowedInst, true)) {
    respond(400, array(
        'status' => 'failed',
        'message' => '分期期數不正確，只能是 ' . implode(' / ', $allowedInst),
    ));
}
$cardInst = (int) $cardInst;

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

// 裝置資訊（選填）。App 每次交易都會帶，用來登記收銀機並讓交易可追溯到
// 是哪一台刷的。缺少也不擋交易 —— 收款比登記重要。
$device = isset($input['device']) && is_array($input['device']) ? $input['device'] : null;
$deviceId = ($device && !empty($device['deviceId'])) ? substr((string) $device['deviceId'], 0, 64) : null;
// 機器的硬體序號（UID）。存進訂單當快照，之後裝置資料被改或刪也查得到。
$deviceSerial = ($device && !empty($device['serialNo'])) ? substr((string) $device['serialNo'], 0, 64) : null;

// 速率限制：一定要在送去 PAYUNi「之前」擋，被擋下的請求不會用掉商店額度、
// 不會產生手續費，也不會被 PAYUNi 風控記為異常。
// 資料庫連線在這裡就先開，後面寫訂單紀錄會重複使用。
$conn = null;
try {
    $conn = db_connect();
    $rateLimitMessage = rl_check($conn, $cardNumber);
    if ($rateLimitMessage !== null) {
        respond(429, array('status' => 'failed', 'message' => $rateLimitMessage));
    }
    rl_record_attempt($conn, $cardNumber);
    rl_cleanup_occasionally($conn);
} catch (Exception $e) {
    // 資料庫掛掉時不擋交易（避免收銀完全無法運作），但要記 log。
    // 這是可用性與安全性的取捨：DB 故障是短暫的，硬擋會讓門市無法收款。
    error_log('速率限制檢查失敗（放行）：' . $e->getMessage());
}

// CardExpired 格式為 MMYY（月在前、年在後，兩碼年份）
$cardExpired = str_pad($expiryMonth, 2, '0', STR_PAD_LEFT) . substr((string) $expiryYear, -2);

$encryptInfoParams = array(
    // 用登入客戶的商店代號，不是 config 裡的固定值
    'MerID' => $merIdForTrade,
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
// 一次付清時不帶 CardInst —— 1 本來就是 PAYUNi 的預設值，少帶一個欄位
// 就少一個出錯的可能（這條路徑目前每天都在跑，不要動它的行為）。
if ($cardInst > 1) {
    $encryptInfoParams['CardInst'] = (string) $cardInst;
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
// （$conn 在前面做速率限制時已經開好了，沒開成功就是 null）
if ($conn) {
    // 登記／更新收銀機。失敗不擋交易，只記 log。
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

    /*
     * 伺服器端的冪等保護：這個訂單編號如果已經有結果了，直接回傳既有結果，
     * 不要再打一次 PAYUNi。
     *
     * 為什麼需要 —— 2026-07-20 實測踩到：一次點擊卻送出兩次（OkHttp 的
     * retryOnConnectionFailure 預設會自動重送），第二次拿到
     * 「CREDIT04001 已存在相同商店訂單編號」，然後**用這個錯誤覆蓋掉第一次
     * 真正的失敗原因**，事後完全查不出當初為什麼失敗。
     *
     * PAYUNi 的 MerTradeNo 去重是最後一道防線，不該讓它變成日常依賴。
     * 在這裡擋掉有兩個好處：不會重複送出、也保住了原始的診斷資訊。
     *
     * 只擋已有結論（success/failed）的訂單。still pending 的代表前一次
     * 沒拿到回應，那種情況要讓它重送 —— PAYUNi 會用 MerTradeNo 判斷是否
     * 已經授權過，這是正確的處理方式。
     */
    $existing = db_find_order($conn, $merTradeNo);
    if ($existing && in_array($existing['status'], array('success', 'failed'), true)) {
        error_log("訂單 {$merTradeNo} 已有結果（{$existing['status']}），不重複送出");
        if ($existing['status'] === 'success') {
            respond(200, array(
                'status' => 'success',
                'payuniTradeNo' => $existing['payuni_trade_no'],
                'authCode' => $existing['auth_code'],
                'card4No' => $existing['card4_no'],
                'duplicate' => true,
            ));
        }
        respond(200, array(
            'status' => 'failed',
            'message' => $existing['message'] !== '' ? $existing['message'] : '交易未通過',
            'duplicate' => true,
        ));
    }

    try {
        db_insert_pending_order($conn, $merTradeNo, round($amount), $deviceId, $deviceSerial,
            $cardInst, $merchantId, $merIdForTrade, $storeId, $dealerId);
    } catch (Exception $e) {
        error_log('寫入 pending 訂單失敗：' . $e->getMessage());
        // 資料庫寫入失敗不擋交易，continue，但要記 log 之後追查
    }
}

$postFields = http_build_query(array(
    'MerID' => $merIdForTrade,
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
    $failCard4No = null;
    if (!empty($result['EncryptInfo'])) {
        try {
            $failDetail = payuni_decrypt_trade_info($result['EncryptInfo'], PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
            // ResCodeMsg 是銀行給的具體原因（例如「授權失敗_無此發卡行
            // (No such issuer)」），比籠統的 Message（「授權失敗」）有用，
            // 授權被拒時大多走這條分支，所以這裡也要優先取 ResCodeMsg。
            $decryptedMessage = !empty($failDetail['ResCodeMsg'])
                ? $failDetail['ResCodeMsg']
                : (isset($failDetail['Message']) ? $failDetail['Message'] : null);
            // 失敗時 PAYUNi 也會回卡號後四碼，存下來方便對帳查問題
            $failCard4No = !empty($failDetail['Card4No']) ? $failDetail['Card4No'] : null;
            error_log('解密內容：' . json_encode($failDetail, JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            // EncryptInfo 可能是空的或格式不同，解密失敗就略過，不影響主要回應
        }
    }

    $message = payuni_resolve_error_message(
        isset($result['Status']) ? $result['Status'] : '',
        $decryptedMessage,
        isset($result['Message']) ? $result['Message'] : null,
        '請求失敗'
    );
    if ($conn) {
        try {
            db_update_order_result($conn, $merTradeNo, 'failed', null, null, $failCard4No, $message, $responseBody);
            // 累積失敗次數：測卡的失敗率極高，這是最有效的偵測特徵
            rl_record_failure($conn, $cardNumber);
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

// ResCodeMsg 是銀行回的具體原因（例如「授權失敗_無此發卡行(No such issuer)」），
// 比籠統的 Message（「授權失敗」）有用，優先採用。
$message = !empty($detail['ResCodeMsg'])
    ? $detail['ResCodeMsg']
    : payuni_resolve_error_message(
        isset($detail['Status']) ? $detail['Status'] : '',
        isset($detail['Message']) ? $detail['Message'] : null,
        null,
        '交易未通過'
    );
if ($conn) {
    try {
        db_update_order_result($conn, $merTradeNo, 'failed', null, null, null, $message, $responseBody);
        rl_record_failure($conn, $cardNumber);
    } catch (Exception $e) {
        error_log('更新失敗訂單狀態失敗：' . $e->getMessage());
    }
}
respond(200, array('status' => 'failed', 'message' => $message));
