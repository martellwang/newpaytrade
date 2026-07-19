<?php
/**
 * PAYUNi 統一金流 — 交易查詢，對應官方文件「單筆交易查詢 Ver 2.0」。
 *
 * 請求 URL：
 *   測試 https://sandbox-api.payuni.com.tw/api/trade/query
 *   正式 https://api.payuni.com.tw/api/trade/query
 *
 * ⚠️ Version 是 "2.0"（授權是 1.3、退款是 1.0，三支都不一樣）。
 *
 * 用途：幕後授權若 60 秒內沒收到銀行回應會變成 pending，這時我們並不知道
 * 銀行到底有沒有扣款。這支 API 去 PAYUNi 問出真實結果，把本地的 pending
 * 訂單補正成 success / failed，避免漏帳或重複收款。
 *
 * EncryptInfo 欄位：MerID、MerTradeNo 或 TradeNo（擇一）、Timestamp。
 *
 * 回應重點欄位：
 *   TradeStatus  0=取號成功 9=未付款 1=已付款 2=付款失敗
 *                3=付款取消 4=交易逾期 8=訂單待確認
 *   DataSource   A=完整資料 B=處理中未完整（B 建議 10 分鐘後再查一次）
 *   信用卡另有 AuthCode、CloseStatus（請款狀態）、RefundStatus（退款狀態）、
 *   RemainAmt（剩餘可退款金額，這是 PAYUNi 端的權威數字）
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

// 查詢用 GET 或 POST 都可以，方便從瀏覽器或排程直接呼叫
$merTradeNo = isset($_REQUEST['merTradeNo']) ? $_REQUEST['merTradeNo'] : '';
$tradeNo = isset($_REQUEST['tradeNo']) ? $_REQUEST['tradeNo'] : '';

if ($merTradeNo === '' && $tradeNo === '') {
    respond(400, array('status' => 'failed', 'message' => 'merTradeNo 與 tradeNo 請擇一提供'));
}

$encryptInfoParams = array('MerID' => PAYUNI_MER_ID);
// 文件寫明兩者擇一，同時帶可能造成非預期行為，優先用商店自己的訂單編號
if ($merTradeNo !== '') {
    $encryptInfoParams['MerTradeNo'] = $merTradeNo;
} else {
    $encryptInfoParams['TradeNo'] = $tradeNo;
}
$encryptInfoParams['Timestamp'] = (string) time();

try {
    $encryptInfo = payuni_encrypt_trade_info($encryptInfoParams, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
    $hashInfo = payuni_generate_hash($encryptInfo, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
} catch (Exception $e) {
    error_log('查詢加密失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統錯誤，請稍後再試'));
}

$postFields = http_build_query(array(
    'MerID' => PAYUNI_MER_ID,
    'Version' => '2.0', // 查詢是 2.0，跟授權(1.3)、退款(1.0)都不同
    'EncryptInfo' => $encryptInfo,
    'HashInfo' => $hashInfo,
    'IsPlatForm' => '1', // 平台/代理商帳號，三支 API 都要帶
));

$ch = curl_init(PAYUNI_QUERY_URL);
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
    error_log('呼叫 PAYUNi 查詢 API 失敗：' . $curlError);
    respond(502, array('status' => 'failed', 'message' => '金流服務暫時無法回應'));
}

$result = json_decode($responseBody, true);

if (!isset($result['Status']) || $result['Status'] !== 'SUCCESS') {
    error_log('查詢回應非 SUCCESS：' . $responseBody);
    $decryptedMessage = null;
    if (!empty($result['EncryptInfo'])) {
        try {
            $failDetail = payuni_decrypt_trade_info($result['EncryptInfo'], PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
            $decryptedMessage = isset($failDetail['Message']) ? $failDetail['Message'] : null;
        } catch (Exception $e) {
            // 解不開就靠錯誤碼對照表
        }
    }
    $message = payuni_resolve_error_message(
        isset($result['Status']) ? $result['Status'] : '',
        $decryptedMessage,
        isset($result['Message']) ? $result['Message'] : null,
        '查詢失敗'
    );
    respond(200, array('status' => 'failed', 'message' => $message));
}

try {
    $detail = payuni_decrypt_trade_info($result['EncryptInfo'], PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
} catch (Exception $e) {
    error_log('解密查詢回應失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統錯誤，請稍後再試'));
}

// 查詢結果包在 Result 陣列裡（同一個端點也支援多筆查詢），不是放在最外層。
// 單筆查詢固定取第一筆。
if (empty($detail['Result']) || !is_array($detail['Result'])) {
    error_log('查詢回應沒有 Result 內容：' . json_encode($detail, JSON_UNESCAPED_UNICODE));
    respond(200, array('status' => 'failed', 'message' => '查無此交易資料'));
}
$record = $detail['Result'][0];

$tradeStatus = isset($record['TradeStatus']) ? $record['TradeStatus'] : '';
$dataSource = isset($record['DataSource']) ? $record['DataSource'] : '';

// TradeStatus 對應到我們自己的訂單狀態
$statusMap = array(
    '0' => 'pending',  // 取號成功（尚未付款）
    '9' => 'pending',  // 未付款
    '1' => 'success',  // 已付款
    '2' => 'failed',   // 付款失敗
    '3' => 'failed',   // 付款取消
    '4' => 'failed',   // 交易逾期
    '8' => 'pending',  // 訂單待確認
);
$localStatus = isset($statusMap[$tradeStatus]) ? $statusMap[$tradeStatus] : 'pending';

$tradeStatusText = array(
    '0' => '取號成功', '9' => '未付款', '1' => '已付款', '2' => '付款失敗',
    '3' => '付款取消', '4' => '交易逾期', '8' => '訂單待確認',
);

// DataSource=B 代表 PAYUNi 那邊還在處理，資料不完整，這時不要拿來覆蓋
// 本地狀態（可能會把還沒完成的交易誤判成失敗），文件建議 10 分鐘後再查。
$authoritative = ($dataSource !== 'B');

$localMerTradeNo = isset($record['MerTradeNo']) ? $record['MerTradeNo'] : $merTradeNo;
$updated = false;
if ($authoritative && $localMerTradeNo !== '') {
    try {
        $conn = db_connect();
        $order = db_find_order($conn, $localMerTradeNo);
        // 只在本地狀態跟查詢結果不一致時才更新，避免蓋掉已經正確的紀錄
        if ($order && $order['status'] !== $localStatus) {
            db_update_order_result(
                $conn,
                $localMerTradeNo,
                $localStatus,
                isset($record['TradeNo']) ? $record['TradeNo'] : null,
                isset($record['AuthCode']) ? $record['AuthCode'] : null,
                isset($record['Card4No']) ? $record['Card4No'] : null,
                isset($tradeStatusText[$tradeStatus]) ? ('交易查詢補正：' . $tradeStatusText[$tradeStatus]) : null,
                $responseBody
            );
            $updated = true;
            error_log("交易查詢補正訂單 {$localMerTradeNo}：{$order['status']} -> $localStatus");
        }
    } catch (Exception $e) {
        error_log('交易查詢更新本地訂單失敗：' . $e->getMessage());
    }
}

respond(200, array(
    'status' => 'success',
    'merTradeNo' => $localMerTradeNo,
    'payuniTradeNo' => isset($record['TradeNo']) ? $record['TradeNo'] : null,
    'tradeStatus' => $tradeStatus,
    'tradeStatusText' => isset($tradeStatusText[$tradeStatus]) ? $tradeStatusText[$tradeStatus] : $tradeStatus,
    'localStatus' => $localStatus,
    'localStatusUpdated' => $updated,
    'dataSource' => $dataSource,
    // DataSource=B 時資料還不完整，呼叫端不應該把結果當定案
    'dataComplete' => $authoritative,
    'tradeAmt' => isset($record['TradeAmt']) ? (int) $record['TradeAmt'] : null,
    'tradeFee' => isset($record['TradeFee']) ? $record['TradeFee'] : null,
    'paymentDay' => isset($record['PaymentDay']) ? $record['PaymentDay'] : null,
    'createDay' => isset($record['CreateDay']) ? $record['CreateDay'] : null,
    // 以下為信用卡專屬欄位
    'card4No' => isset($record['Card4No']) ? $record['Card4No'] : null,
    'authCode' => isset($record['AuthCode']) ? $record['AuthCode'] : null,
    'closeStatus' => isset($record['CloseStatus']) ? $record['CloseStatus'] : null,
    'closeAmt' => isset($record['CloseAmt']) ? $record['CloseAmt'] : null,
    'refundStatus' => isset($record['RefundStatus']) ? $record['RefundStatus'] : null,
    'refundAmt' => isset($record['RefundAmt']) ? $record['RefundAmt'] : null,
    // PAYUNi 端的權威數字，比我們自己累加的可靠，退款前建議以這個為準
    'remainAmt' => isset($record['RemainAmt']) ? $record['RemainAmt'] : null,
));
