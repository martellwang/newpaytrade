<?php
/**
 * LINE Pay 退款（支援全額與部分退款）。
 *
 * 端點：/api/trade/common/refund/linepay，Version 固定 1.0。
 *
 * ── 為什麼不併進 refund.php ────────────────────────────────────
 *
 * 那支整套是信用卡的模型：先授權、後請款，所以有 CloseType（1=請款
 * 2=退款 -1/-2=取消）與「依請款狀態自動選型別」的判斷。
 *
 * **LINE Pay 沒有請款這個階段** —— 客人掃碼付款當下錢就已經扣了，
 * 只有「退款」一種動作。硬要共用會讓兩邊的判斷式互相糾纏，而退款是動錢的
 * 操作，糾纏的邏輯遲早會退錯金額。分開寫比較安全。
 *
 * ⚠️ 用的識別是 **TradeNo（PAYUNi 序號）**，不是 MerTradeNo。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payuni_crypto.php';
require_once __DIR__ . '/payuni_error_codes.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rate_limit.php';
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

if (!defined('PAYUNI_LINEPAY_REFUND_URL') || PAYUNI_LINEPAY_REFUND_URL === '') {
    respond(503, array('status' => 'failed', 'message' => 'LINE Pay 退款尚未設定'));
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if ($input === null) {
    respond(400, array('status' => 'failed', 'message' => '請求格式不是合法的 JSON'));
}

$merTradeNo = isset($input['merTradeNo']) ? $input['merTradeNo'] : '';
if (!preg_match('/^[A-Za-z0-9_-]{1,25}$/', $merTradeNo)) {
    respond(400, array('status' => 'failed', 'message' => 'merTradeNo 格式不正確'));
}

try {
    $conn = db_connect();
} catch (Exception $e) {
    /*
     * 退款與收款的取捨相反：收款時資料庫掛掉要放行（門市不能停擺），
     * **退款時必須擋下** —— 沒有紀錄就無法防止重複退款，寧可退不了，
     * 不可退兩次。
     */
    error_log('LINE Pay 退款時資料庫連線失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統忙碌中，請稍後再試'));
}

/*
 * ── 退款權限 ──────────────────────────────────────────────────
 *
 * 帶了收銀機 token 就是門市在退款，必須是有退款權限的店員。
 * 沒帶 token 則是後台在退（admin/ 有自己的登入），照舊放行。
 *
 * ⚠️ 這裡的判斷基準是「有沒有帶 token」，不是「token 有沒有效」——
 *    無效的 token 會在 pos_resolve_identity 就被擋下並回 401，
 *    不會掉到後台那條路上變成免驗證放行。
 */
$posToken = isset($_SERVER['HTTP_X_POS_TOKEN']) ? $_SERVER['HTTP_X_POS_TOKEN'] : '';
$refundStaffId = null;
$refundStaffName = null;
if ($posToken !== '') {
    $identity = pos_resolve_identity($posToken, false);
    if (!$identity['ok']) {
        respond($identity['httpCode'], $identity['body']);
    }
    if (!$identity['staffId']) {
        respond(403, array(
            'status' => 'failed',
            'message' => '退款需要先開班。請由有退款權限的人員開班後再操作。',
            'needShift' => true,
        ));
    }
    if (!$identity['canRefund']) {
        respond(403, array(
            'status' => 'failed',
            'message' => '目前開班的人員沒有退款權限，請由店長或值班主管操作。',
            'noPermission' => true,
        ));
    }
    $refundStaffId = $identity['staffId'];
    $refundStaffName = $identity['staffName'];
}

$order = db_find_order($conn, $merTradeNo);
if (!$order) {
    respond(404, array('status' => 'failed', 'message' => '找不到訂單'));
}

$paymentMethod = isset($order['payment_method']) ? $order['payment_method'] : 'credit';
if ($paymentMethod !== 'linepay') {
    respond(400, array(
        'status' => 'failed',
        'message' => '這筆是 ' . $paymentMethod . ' 交易，請改用對應的退款方式',
    ));
}

if ($order['status'] !== 'success') {
    respond(400, array(
        'status' => 'failed',
        'message' => '這筆訂單狀態是 ' . $order['status'] . '，只有已收款的訂單可以退款',
    ));
}

if (empty($order['payuni_trade_no'])) {
    respond(400, array(
        'status' => 'failed',
        'message' => '這筆訂單沒有 PAYUNi 交易序號（TradeNo），無法退款',
    ));
}

// 用交易當下的商店代號，不是 config 預設值（多商店情境下會退錯身分）
$refundMerId = !empty($order['mer_id']) ? $order['mer_id'] : PAYUNI_MER_ID;

$orderAmount = (int) $order['amount'];
// 沒指定金額就是全額退款（最常見的情境）
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

/*
 * 擋住重複退款：已成功退掉的 + 這次要退的，不能超過訂單金額。
 *
 * 這是防呆而不是完整的併發保護（同時打兩次仍可能都通過檢查），最後一道
 * 防線是 PAYUNi 端會拒絕超額退款。但這道還是要有 —— 大部分的重複退款
 * 是人為的連按兩次，不是併發。
 */
$alreadyRefunded = db_sum_refunded_amount($conn, $merTradeNo);
if ($alreadyRefunded + $amount > $orderAmount) {
    respond(400, array(
        'status' => 'failed',
        'message' => "這筆訂單已退款 $alreadyRefunded 元，再退 $amount 元會超過訂單金額 $orderAmount 元",
        'alreadyRefunded' => $alreadyRefunded,
        'orderAmount' => $orderAmount,
    ));
}

$payuniTradeNo = $order['payuni_trade_no'];

$encryptInfoParams = array(
    'MerID' => $refundMerId,
    // ⚠️ LINE Pay 退款認的是 PAYUNi 序號，不是商店訂單編號
    'TradeNo' => $payuniTradeNo,
    'TradeAmt' => (string) $amount,
    'Timestamp' => (string) time(),
);

try {
    $encryptInfo = payuni_encrypt_trade_info($encryptInfoParams, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
    $hashInfo = payuni_generate_hash($encryptInfo, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
} catch (Exception $e) {
    error_log('LINE Pay 退款加密失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統錯誤，請稍後再試'));
}

/*
 * 送出前先留一筆 pending。就算後面連線逾時，也查得到「曾經發動過這筆退款」
 * —— 沒有這筆紀錄的話，逾時之後沒有人知道錢到底退出去了沒。
 *
 * close_type 沿用信用卡的 2（退款）。LINE Pay 沒有請款/取消授權的概念，
 * 只會出現這一個值；這樣寫是為了讓 db_sum_refunded_amount() 的累計邏輯
 * 兩邊共用，不必再開一張表。
 */
$refundId = null;
try {
    $refundId = db_insert_pending_refund($conn, $merTradeNo, $payuniTradeNo, 2, $amount,
        $refundStaffId, $refundStaffName);
} catch (Exception $e) {
    error_log('寫入 LINE Pay pending 退款紀錄失敗：' . $e->getMessage());
}

$postFields = http_build_query(array(
    'MerID' => $refundMerId,
    'Version' => '1.0', // LINE Pay 退款固定 1.0（建立交易是 1.2）
    'EncryptInfo' => $encryptInfo,
    'HashInfo' => $hashInfo,
    'IsPlatForm' => '1', // 以代理商身分串接
));

$ch = curl_init(PAYUNI_LINEPAY_REFUND_URL);
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
    $msg = ($curlErrno === CURLE_OPERATION_TIMEDOUT)
        ? '退款請求逾時，請稍後確認結果，不要直接重試'
        : '金流服務暫時無法回應';
    error_log("LINE Pay 退款呼叫失敗（{$merTradeNo}）：" . $curlError);
    if ($refundId) {
        try {
            db_update_refund_result($conn, $refundId, 'pending', $msg, null);
        } catch (Exception $e) {
            error_log('更新 LINE Pay 退款紀錄失敗：' . $e->getMessage());
        }
    }
    // 逾時不能當失敗 —— 退款可能其實已經成功了，當失敗會導致有人再退一次
    respond(200, array('status' => 'pending', 'message' => $msg, 'merTradeNo' => $merTradeNo));
}

$result = json_decode($responseBody, true);

if (!isset($result['Status']) || $result['Status'] !== 'SUCCESS') {
    error_log('LINE Pay 退款回應非 SUCCESS：' . $responseBody);
    $decryptedMessage = null;
    if (!empty($result['EncryptInfo'])) {
        try {
            $failDetail = payuni_decrypt_trade_info($result['EncryptInfo'], PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
            $decryptedMessage = isset($failDetail['Message']) ? $failDetail['Message'] : null;
            error_log('LINE Pay 退款解密內容：' . json_encode($failDetail, JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            // 略過，不影響主要回應
        }
    }
    $message = payuni_resolve_error_message(
        isset($result['Status']) ? $result['Status'] : '',
        $decryptedMessage,
        isset($result['Message']) ? $result['Message'] : null,
        '退款失敗'
    );
    if ($refundId) {
        try {
            db_update_refund_result($conn, $refundId, 'failed', $message, $responseBody);
        } catch (Exception $e) {
            error_log('更新 LINE Pay 退款紀錄失敗：' . $e->getMessage());
        }
    }
    respond(200, array('status' => 'failed', 'message' => $message));
}

try {
    $detail = payuni_decrypt_trade_info($result['EncryptInfo'], PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
} catch (Exception $e) {
    /*
     * 解密失敗但外層說 SUCCESS：**退款很可能已經成功了**，只是我們讀不懂
     * 回應。這種情況絕對不能標成 failed —— 那會讓操作者再退一次。
     */
    error_log('解密 LINE Pay 退款回應失敗（外層為 SUCCESS）：' . $e->getMessage());
    if ($refundId) {
        try {
            db_update_refund_result($conn, $refundId, 'pending',
                '退款已送出但回應無法解讀，請查明實際狀態', $responseBody);
        } catch (Exception $e2) {
            error_log('更新 LINE Pay 退款紀錄失敗：' . $e2->getMessage());
        }
    }
    respond(200, array(
        'status' => 'pending',
        'message' => '退款已送出但回應無法解讀，請查明實際狀態後再決定是否重試',
        'merTradeNo' => $merTradeNo,
    ));
}

if (isset($detail['Status']) && $detail['Status'] === 'SUCCESS') {
    $refundNo = isset($detail['RefundNo']) ? $detail['RefundNo'] : null;
    $refundDT = isset($detail['RefundDT']) ? $detail['RefundDT'] : null;
    if ($refundId) {
        try {
            db_update_refund_result($conn, $refundId, 'success',
                '退款成功' . ($refundNo ? "（退款序號 $refundNo）" : ''), $responseBody);
        } catch (Exception $e) {
            error_log('更新 LINE Pay 退款紀錄失敗：' . $e->getMessage());
        }
    }
    $totalRefunded = $alreadyRefunded + $amount;
    respond(200, array(
        'status' => 'success',
        'merTradeNo' => $merTradeNo,
        'payuniTradeNo' => $payuniTradeNo,
        'refundNo' => $refundNo,
        'refundDT' => $refundDT,
        'amount' => $amount,
        'orderAmount' => $orderAmount,
        'totalRefunded' => $totalRefunded,
        // 讓呼叫端知道還剩多少可退，不必自己算
        'refundable' => $orderAmount - $totalRefunded,
    ));
}

$message = !empty($detail['Message']) ? $detail['Message'] : '退款未成功';
if ($refundId) {
    try {
        db_update_refund_result($conn, $refundId, 'failed', $message, $responseBody);
    } catch (Exception $e) {
        error_log('更新 LINE Pay 退款紀錄失敗：' . $e->getMessage());
    }
}
respond(200, array('status' => 'failed', 'message' => $message));
