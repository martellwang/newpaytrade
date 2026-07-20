<?php
/**
 * PAYUNi 統一金流 — 交易請退款（CREDIT），對應官方文件「交易請退款
 * (CREDIT) Ver 1.0」。
 *
 * 請求 URL：
 *   測試 https://sandbox-api.payuni.com.tw/api/trade/close
 *   正式 https://api.payuni.com.tw/api/trade/close
 *
 * ⚠️ 注意 Version 是 "1.0"，跟幕後授權的 "1.3" 不一樣。
 *
 * EncryptInfo 欄位：MerID、TradeNo（UNi序號）、Timestamp、CloseType、
 * TradeAmt（請退款時必填）。
 *
 * CloseType：1=請款 2=退款 -1=取消請款 -2=取消退款
 *
 * ⚠️ 呼叫端**不需要自己決定 CloseType**。不帶 closeType 時，這支程式會先向
 *    PAYUNi 查詢真實的關帳狀態，未請款自動走取消請款、已請款才走退款。
 *    台灣信用卡是「授權 → 請款 → 退款」三段式，對還沒請款的交易送退款
 *    會被擋下並回「關帳狀態不符合」，操作者看到那個訊息不會知道要改用
 *    取消請款 —— 所以由程式判斷，不要讓人去記這層差異。
 *    仍可帶 closeType 明確指定，處理例外情況時需要。
 *
 * 官方文件載明的業務限制（程式沒辦法自己判斷，呼叫端要注意）：
 *   - 一次付清、國外卡：可全額退款，也可部分退款
 *   - 分期付款、銀聯卡：僅能全額退款
 *   - 請款期限：授權成功後 3 天內（平台預設為自動請款）
 *   - 退款期限：請款完成後 180 天內
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payuni_crypto.php';
require_once __DIR__ . '/payuni_error_codes.php';
require_once __DIR__ . '/payuni_query.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rate_limit.php';

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

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if ($input === null) {
    respond(400, array('status' => 'failed', 'message' => '請求格式不是合法的 JSON'));
}

$merTradeNo = isset($input['merTradeNo']) ? $input['merTradeNo'] : '';

// closeType 沒帶就自動判斷（見下方 decide_close_type）。
// 帶了就照帶的做 —— 保留人工指定的能力，處理例外情況時需要。
$closeTypeExplicit = isset($input['closeType']);
$closeType = $closeTypeExplicit ? (int) $input['closeType'] : null;

if ($merTradeNo === '') {
    respond(400, array('status' => 'failed', 'message' => '缺少 merTradeNo'));
}

if ($closeTypeExplicit && !in_array($closeType, array(1, 2, -1, -2), true)) {
    respond(400, array('status' => 'failed', 'message' => 'closeType 只能是 1(請款) / 2(退款) / -1(取消請款) / -2(取消退款)'));
}

try {
    $conn = db_connect();
} catch (Exception $e) {
    error_log('退款：資料庫連線失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統錯誤，請稍後再試'));
}

// 速率限制：退款沒有卡號可比對，只依 IP。這裡跟授權不同——退款一定要有
// 資料庫才能做（要查訂單），所以 DB 正常時就一定會檢查。
$rateLimitMessage = rl_check($conn);
if ($rateLimitMessage !== null) {
    respond(429, array('status' => 'failed', 'message' => $rateLimitMessage));
}
rl_record_attempt($conn);

$order = db_find_order($conn, $merTradeNo);
if (!$order) {
    respond(404, array('status' => 'failed', 'message' => '找不到訂單'));
}

// 只有授權成功的訂單才有東西可以退
if ($order['status'] !== 'success') {
    respond(400, array(
        'status' => 'failed',
        'message' => '這筆訂單狀態是 ' . $order['status'] . '，只有 success 的訂單可以請退款',
    ));
}

if (empty($order['payuni_trade_no'])) {
    respond(400, array('status' => 'failed', 'message' => '這筆訂單沒有 PAYUNi 交易序號（TradeNo），無法請退款'));
}

// ---------------------------------------------------------------------------
// 自動判斷該用「退款」還是「取消請款」
//
// 台灣信用卡是三段式：授權 → 請款 → 退款。**還沒請款的交易不能退款**，
// 硬送 CloseType=2 會被 PAYUNi 擋下並回「關帳狀態不符合」——操作者看到
// 這個訊息根本不知道要改用取消請款。所以這裡先查一次真實的關帳狀態，
// 自己挑對的動作。
//
// 對未請款的交易來說，取消請款其實比退款更好：款項從頭到尾沒有撥付，
// 持卡人帳單上不會出現「扣款一筆、退款一筆」兩行紀錄。
// ---------------------------------------------------------------------------
$closeStatus = null;
if (!$closeTypeExplicit) {
    $queryResult = payuni_fetch_trade_record($merTradeNo);
    if (!$queryResult['ok']) {
        respond(502, array(
            'status' => 'failed',
            'message' => '無法確認交易的請款狀態，請稍後再試：' . $queryResult['message'],
        ));
    }
    $record = $queryResult['record'];
    $closeStatus = isset($record['CloseStatus']) ? (string) $record['CloseStatus'] : '';

    // CloseStatus 語意：'1' 與 '3' 是 2026-07-20 實測確認的（取消請款前後
    // 分別是 1 → 3）；'0' 與 '2' 依 PAYUNi 慣例推定，尚未實測。
    // 因此**遇到未知值一律拒絕而不是猜**——猜錯會動到真實款項。
    if ($closeStatus === '2') {
        $closeType = 2;   // 已請款 → 退款
    } elseif ($closeStatus === '0' || $closeStatus === '1') {
        $closeType = -1;  // 未請款／待請款 → 取消請款
    } elseif ($closeStatus === '3') {
        respond(400, array(
            'status' => 'failed',
            'message' => '這筆交易已經取消請款，款項不會被撥付，不需要再處理。',
            'closeStatus' => $closeStatus,
        ));
    } else {
        respond(400, array(
            'status' => 'failed',
            'message' => "無法判斷這筆交易的請款狀態（CloseStatus={$closeStatus}），"
                . '為避免誤動款項已中止。請人工確認後改用 closeType 參數明確指定。',
            'closeStatus' => $closeStatus,
        ));
    }
}

$orderAmount = (int) $order['amount'];
// 沒指定金額就是全額（退款情境下最常見）
$amount = isset($input['amount']) ? $input['amount'] : $orderAmount;

if (!is_numeric($amount) || $amount <= 0) {
    respond(400, array('status' => 'failed', 'message' => 'amount 必須是大於 0 的數字'));
}
$amount = (int) round($amount);

if ($amount > $orderAmount) {
    respond(400, array(
        'status' => 'failed',
        'message' => "退款金額 $amount 超過訂單金額 $orderAmount",
    ));
}

// 分期付款只能全額退款（官方文件載明，銀聯卡也是）。不擋的話會送出一筆
// 注定被 PAYUNi 拒絕的請求，而且錯誤訊息不會說是分期的關係，操作者會
// 卡在那邊猜。這裡先擋下並講清楚。
$orderInst = isset($order['card_inst']) ? (int) $order['card_inst'] : 1;
if ($orderInst > 1 && $amount !== $orderAmount) {
    respond(400, array(
        'status' => 'failed',
        'message' => "這筆是 {$orderInst} 期分期交易，只能全額退款（{$orderAmount} 元），不能部分退款。",
        'cardInst' => $orderInst,
        'orderAmount' => $orderAmount,
    ));
}

// 擋住重複退款：已成功退掉的金額 + 這次要退的，不能超過訂單金額。
// 這是防呆，不是完整的併發保護（同時打兩次還是可能都通過檢查），
// 真正的最後一道防線是 PAYUNi 端會拒絕超額退款。
if ($closeType === 2) {
    $alreadyRefunded = db_sum_refunded_amount($conn, $merTradeNo);
    if ($alreadyRefunded + $amount > $orderAmount) {
        respond(400, array(
            'status' => 'failed',
            'message' => "這筆訂單已退款 $alreadyRefunded 元，再退 $amount 元會超過訂單金額 $orderAmount 元",
            'alreadyRefunded' => $alreadyRefunded,
            'orderAmount' => $orderAmount,
        ));
    }
}

$payuniTradeNo = $order['payuni_trade_no'];

$encryptInfoParams = array(
    'MerID' => PAYUNI_MER_ID,
    'TradeNo' => $payuniTradeNo,
    'Timestamp' => (string) time(),
    'CloseType' => (string) $closeType,
    'TradeAmt' => (string) $amount,
);

try {
    $encryptInfo = payuni_encrypt_trade_info($encryptInfoParams, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
    $hashInfo = payuni_generate_hash($encryptInfo, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
} catch (Exception $e) {
    error_log('退款加密失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統錯誤，請稍後再試'));
}

// 送出前先留一筆 pending，就算連線逾時也查得到曾經發動過退款
$refundId = null;
try {
    $refundId = db_insert_pending_refund($conn, $merTradeNo, $payuniTradeNo, $closeType, $amount);
} catch (Exception $e) {
    error_log('寫入 pending 退款紀錄失敗：' . $e->getMessage());
}

$postFields = http_build_query(array(
    'MerID' => PAYUNI_MER_ID,
    'Version' => '1.0', // 請退款 API 固定 1.0，不是授權用的 1.3
    'EncryptInfo' => $encryptInfo,
    'HashInfo' => $hashInfo,
    // 跟幕後授權一樣要帶（這個商店是平台/代理商類型帳號）。沒帶的話
    // PAYUNi 會回 DEF01007「Hash比對不符合」——不是公式錯，是平台端
    // 用錯的金鑰情境去驗證。
    'IsPlatForm' => '1',
));

$ch = curl_init(PAYUNI_REFUND_URL);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/x-www-form-urlencoded',
        'user-agent: payuni',
    ),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 65,
));
$responseBody = curl_exec($ch);
$curlErrno = curl_errno($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($responseBody === false) {
    $msg = ($curlErrno === CURLE_OPERATION_TIMEDOUT) ? '退款請求逾時，請稍後查詢確認結果' : '金流服務暫時無法回應';
    error_log("退款呼叫失敗（{$merTradeNo}）：" . $curlError);
    if ($refundId) {
        try {
            db_update_refund_result($conn, $refundId, 'pending', $msg, null);
        } catch (Exception $e) {
            error_log('更新退款紀錄失敗：' . $e->getMessage());
        }
    }
    // 逾時不能當成失敗，退款可能其實已經成功了
    respond(200, array('status' => 'pending', 'message' => $msg, 'merTradeNo' => $merTradeNo));
}

$result = json_decode($responseBody, true);

// 最外層 Status 不是 SUCCESS：請求層級失敗（簽章、格式、IP 白名單等）
if (!isset($result['Status']) || $result['Status'] !== 'SUCCESS') {
    error_log('退款回應非 SUCCESS，完整內容：' . $responseBody);

    $decryptedMessage = null;
    if (!empty($result['EncryptInfo'])) {
        try {
            $failDetail = payuni_decrypt_trade_info($result['EncryptInfo'], PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
            $decryptedMessage = isset($failDetail['Message']) ? $failDetail['Message'] : null;
            error_log('退款失敗解密內容：' . json_encode($failDetail, JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            // EncryptInfo 可能是空的，解密失敗就略過
        }
    }

    $message = payuni_resolve_error_message(
        isset($result['Status']) ? $result['Status'] : '',
        $decryptedMessage,
        isset($result['Message']) ? $result['Message'] : null,
        '退款請求失敗'
    );
    if ($refundId) {
        try {
            db_update_refund_result($conn, $refundId, 'failed', $message, $responseBody);
        } catch (Exception $e) {
            error_log('更新退款紀錄失敗：' . $e->getMessage());
        }
    }
    respond(200, array('status' => 'failed', 'message' => $message));
}

try {
    $detail = payuni_decrypt_trade_info($result['EncryptInfo'], PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
} catch (Exception $e) {
    error_log('解密退款回應失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統錯誤，請稍後再試'));
}

$detailStatus = isset($detail['Status']) ? $detail['Status'] : '';
$message = isset($detail['Message']) ? $detail['Message'] : '';

if ($detailStatus === 'SUCCESS') {
    if ($refundId) {
        try {
            db_update_refund_result($conn, $refundId, 'success', $message, $responseBody);
        } catch (Exception $e) {
            error_log('更新退款紀錄失敗：' . $e->getMessage());
        }
    }
    $totalRefunded = ($closeType === 2) ? db_sum_refunded_amount($conn, $merTradeNo) : null;
    // 自動判斷時要讓呼叫端知道系統實際做了什麼 —— 「退款」和「取消請款」
    // 對持卡人帳單的呈現不同，操作者需要能對客人說明。
    $actionText = ($closeType === 2) ? '退款' : (($closeType === -1) ? '取消請款' : '請款動作');
    respond(200, array(
        'status' => 'success',
        'message' => $message,
        'merTradeNo' => $merTradeNo,
        'payuniTradeNo' => $payuniTradeNo,
        'closeType' => $closeType,
        'action' => $actionText,
        'autoDetected' => !$closeTypeExplicit,
        'closeStatusBefore' => $closeStatus,
        'amount' => $amount,
        'totalRefunded' => $totalRefunded,
        'orderAmount' => $orderAmount,
    ));
}

if ($refundId) {
    try {
        db_update_refund_result($conn, $refundId, 'failed', $message ?: '退款未通過', $responseBody);
    } catch (Exception $e) {
        error_log('更新退款紀錄失敗：' . $e->getMessage());
    }
}
respond(200, array('status' => 'failed', 'message' => $message ?: '退款未通過'));
