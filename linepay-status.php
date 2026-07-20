<?php
/**
 * 掃碼付款結果查詢 —— 收銀機在顯示 QR 期間反覆呼叫這支。
 *
 * LINE Pay 與行動支付（UPP）共用：這支只讀訂單紀錄並向 PAYUNi 查詢，
 * 邏輯與是哪一種支付方式無關。檔名保留 linepay- 前綴是為了不動已經寫進
 * App 的網址；內容本身是通用的。
 *
 * 為什麼以「查詢」為主而不是等背景通知：
 *   - 店員就站在櫃檯前等，需要幾秒內知道結果，不能等通知何時到
 *   - 背景通知要求伺服器能被 PAYUNi 從外部連入（80/443），本機開發收不到
 * 通知仍然要接（見 notify-scan.php），但那是後備。
 *
 * ⚠️ **客人手機顯示「付款成功」不算數。** 那是他手機上的 App 或網頁說的，
 *    只有這支查到 TradeStatus=1 才代表錢真的收到了。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payuni_query.php';
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

$merTradeNo = isset($_REQUEST['merTradeNo']) ? $_REQUEST['merTradeNo'] : '';
if (!preg_match('/^[A-Za-z0-9_-]{1,25}$/', $merTradeNo)) {
    respond(400, array('status' => 'failed', 'message' => 'merTradeNo 格式不正確'));
}

$posToken = isset($_SERVER['HTTP_X_POS_TOKEN']) ? $_SERVER['HTTP_X_POS_TOKEN'] : '';
$identity = pos_resolve_identity($posToken, false);
if (!$identity['ok']) {
    respond($identity['httpCode'], $identity['body']);
}

try {
    $conn = db_connect();
} catch (Exception $e) {
    error_log('LINE Pay 查詢時資料庫連線失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統錯誤，請稍後再試'));
}

$order = db_find_order($conn, $merTradeNo);
if (!$order) {
    respond(404, array('status' => 'failed', 'message' => '查無此交易'));
}

/*
 * 只能查自己商店的交易。
 *
 * 沒有這道檢查的話，任何拿到 API Key 與任一組登入帳號的人，都能用猜的
 * 訂單編號把別家商店的交易金額與狀態一筆一筆問出來。
 *
 * 回「查無此交易」而不是「無權查詢」—— 後者等於確認了這個編號存在。
 */
if ((int) $order['store_id'] !== $identity['storeId']) {
    error_log("拒絕跨商店查詢：訂單 {$merTradeNo} 屬於商店 {$order['store_id']}，"
        . "查詢者為商店 {$identity['storeId']}");
    respond(404, array('status' => 'failed', 'message' => '查無此交易'));
}

/*
 * 已有結論就直接回，不再打 PAYUNi。
 *
 * 這同時也是查詢頻率的自然上限：收銀機每幾秒問一次，一旦付款完成或失敗，
 * 後續的詢問都停在資料庫這一層，不會持續消耗 PAYUNi 的查詢額度。
 */
if (in_array($order['status'], array('success', 'failed'), true)) {
    respond(200, array(
        'status' => $order['status'],
        'merTradeNo' => $merTradeNo,
        'payuniTradeNo' => $order['payuni_trade_no'],
        'amount' => (int) $order['amount'],
        'message' => $order['message'],
    ));
}

// 用這家商店自己的代號查 —— 拿別家的代號去查只會查無資料
$fetch = payuni_fetch_trade_record($merTradeNo, $identity['merId']);
if (!$fetch['ok']) {
    // 查不到不代表失敗，可能只是暫時連不上。維持 pending 讓收銀機繼續問。
    respond(200, array(
        'status' => 'pending',
        'merTradeNo' => $merTradeNo,
        'message' => $fetch['message'],
    ));
}
$record = $fetch['record'];

$tradeStatus = isset($record['TradeStatus']) ? (string) $record['TradeStatus'] : '';
$dataSource = isset($record['DataSource']) ? $record['DataSource'] : '';

// 與 query.php 同一套對應表
$statusMap = array(
    '0' => 'pending',  // 取號成功（尚未付款）
    '9' => 'pending',  // 未付款
    '1' => 'success',  // 已付款
    '2' => 'failed',   // 付款失敗
    '3' => 'failed',   // 付款取消
    '4' => 'failed',   // 交易逾期
    '8' => 'pending',  // 訂單待確認
);
$tradeStatusText = array(
    '0' => '等待付款', '9' => '未付款', '1' => '已付款', '2' => '付款失敗',
    '3' => '付款取消', '4' => '交易逾期', '8' => '訂單待確認',
);
$localStatus = isset($statusMap[$tradeStatus]) ? $statusMap[$tradeStatus] : 'pending';

/*
 * DataSource=B 代表 PAYUNi 那邊還在處理、資料不完整。這時不能拿來下結論 ——
 * 可能把還沒完成的交易誤判成失敗，讓店員以為客人沒付錢。
 */
if ($dataSource === 'B') {
    respond(200, array(
        'status' => 'pending',
        'merTradeNo' => $merTradeNo,
        'message' => '交易處理中',
    ));
}

$payuniTradeNo = isset($record['TradeNo']) ? $record['TradeNo'] : $order['payuni_trade_no'];

/*
 * 金額必須跟當初建立時一致才認定成功。
 *
 * 對不上就當作還沒完成並記 log 讓人來查 —— 可能是訂單編號重複用到了
 * （PAYUNi 只保證 10 分鐘內不重複），那會讓店員拿到別筆交易的結果，
 * 明明沒收到錢卻顯示成功。這種錯誤沒有人會當場發現。
 */
if ($localStatus === 'success' && isset($record['TradeAmt'])) {
    $paidAmount = (int) $record['TradeAmt'];
    if ($paidAmount !== (int) $order['amount']) {
        error_log("LINE Pay 金額不符：訂單 {$merTradeNo} 應為 {$order['amount']}，"
            . "查詢結果為 {$paidAmount}，不認定為成功");
        respond(200, array(
            'status' => 'pending',
            'merTradeNo' => $merTradeNo,
            'message' => '交易金額待確認，請聯繫客服',
        ));
    }
}

if ($order['status'] !== $localStatus) {
    try {
        db_update_order_result($conn, $merTradeNo, $localStatus, $payuniTradeNo, null, null,
            isset($tradeStatusText[$tradeStatus]) ? $tradeStatusText[$tradeStatus] : null,
            json_encode($record, JSON_UNESCAPED_UNICODE));
        error_log("LINE Pay 訂單 {$merTradeNo}：{$order['status']} -> $localStatus");
    } catch (Exception $e) {
        error_log('更新 LINE Pay 訂單狀態失敗：' . $e->getMessage());
    }
}

respond(200, array(
    'status' => $localStatus,
    'merTradeNo' => $merTradeNo,
    'payuniTradeNo' => $payuniTradeNo,
    'amount' => (int) $order['amount'],
    'tradeStatus' => $tradeStatus,
    'message' => isset($tradeStatusText[$tradeStatus]) ? $tradeStatusText[$tradeStatus] : null,
));
