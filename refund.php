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
 * 官方文件載明的業務限制（程式沒辦法自己判斷，呼叫端要注意）：
 *   - 一次付清、國外卡：可全額退款，也可部分退款
 *   - 分期付款、銀聯卡：僅能全額退款
 *   - 請款期限：授權成功後 3 天內（平台預設為自動請款）
 *   - 退款期限：請款完成後 180 天內
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payuni_crypto.php';
require_once __DIR__ . '/payuni_error_codes.php';
require_once __DIR__ . '/db.php';

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
// closeType 預設 2（退款），這是最常用的情境。平台預設自動請款，所以
// 一般不需要自己發動 CloseType=1。
$closeType = isset($input['closeType']) ? (int) $input['closeType'] : 2;

if ($merTradeNo === '') {
    respond(400, array('status' => 'failed', 'message' => '缺少 merTradeNo'));
}

if (!in_array($closeType, array(1, 2, -1, -2), true)) {
    respond(400, array('status' => 'failed', 'message' => 'closeType 只能是 1(請款) / 2(退款) / -1(取消請款) / -2(取消退款)'));
}

try {
    $conn = db_connect();
} catch (Exception $e) {
    error_log('退款：資料庫連線失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統錯誤，請稍後再試'));
}

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
    error_log("退款呼叫失敗（$merTradeNo）：" . $curlError);
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
    respond(200, array(
        'status' => 'success',
        'message' => $message,
        'merTradeNo' => $merTradeNo,
        'payuniTradeNo' => $payuniTradeNo,
        'closeType' => $closeType,
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
