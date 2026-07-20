<?php
/**
 * LINE Pay 背景通知接收端。
 *
 * ── 這支刻意不信任通知的內容 ──────────────────────────────────
 *
 * 目前**還沒拿到背景通知的欄位規格與驗簽方式**（PAYUNi 的 LINE Pay 文件
 * 只寫「會通知」，沒列格式）。與其照猜的格式去解讀，這裡把通知當成單純的
 * 「有事情發生了，去查一下」訊號：只從裡面撈出訂單編號，其餘一律丟棄，
 * 然後主動呼叫查詢 API 拿權威結果。
 *
 * 這樣做有三個好處，就算日後拿到規格也值得保留：
 *   1. 不需要驗簽 —— 偽造的通知最多只能讓我們去查一筆真實的交易，
 *      查詢結果來自 PAYUNi，偽造者無法影響
 *   2. 通知格式改版不會讓我們誤判交易狀態
 *   3. 與 linepay-status.php 共用同一套判斷，不會出現兩邊結論不一致
 *
 * ⚠️ PAYUNi 需要能從外部連到這個網址（僅限 80 / 443 port）。
 *    本機開發環境收不到通知，收銀機靠 linepay-status.php 主動查詢。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payuni_crypto.php';
require_once __DIR__ . '/payuni_query.php';

// PAYUNi 只看 HTTP 狀態碼，回什麼內容都可以
header('Content-Type: text/plain; charset=utf-8');

$rawBody = file_get_contents('php://input');
error_log('收到 LINE Pay 背景通知：' . substr($rawBody, 0, 2000));

/*
 * 撈出訂單編號。可能出現在三個地方，依序嘗試：
 *   1. POST 表單的 MerTradeNo（若 PAYUNi 直接帶明文）
 *   2. EncryptInfo 解密後的 MerTradeNo（與其他 API 同一套信封）
 *   3. 放棄
 */
$merTradeNo = '';
if (!empty($_POST['MerTradeNo'])) {
    $merTradeNo = (string) $_POST['MerTradeNo'];
} elseif (!empty($_POST['EncryptInfo'])) {
    try {
        $detail = payuni_decrypt_trade_info($_POST['EncryptInfo'], PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
        if (!empty($detail['MerTradeNo'])) {
            $merTradeNo = (string) $detail['MerTradeNo'];
        }
        error_log('LINE Pay 通知解密內容：' . json_encode($detail, JSON_UNESCAPED_UNICODE));
    } catch (Exception $e) {
        error_log('LINE Pay 通知解密失敗：' . $e->getMessage());
    }
}

if (!preg_match('/^[A-Za-z0-9_-]{1,25}$/', $merTradeNo)) {
    error_log('LINE Pay 通知找不到可用的訂單編號，略過');
    // 仍回 200 —— 回錯誤只會讓 PAYUNi 一直重送同一筆解不開的通知
    http_response_code(200);
    echo 'OK';
    exit;
}

try {
    $conn = db_connect();
    $order = db_find_order($conn, $merTradeNo);
} catch (Exception $e) {
    error_log('LINE Pay 通知查詢訂單失敗：' . $e->getMessage());
    http_response_code(500); // 這種是我們自己的問題，讓 PAYUNi 重送
    echo 'ERROR';
    exit;
}

if (!$order) {
    error_log("LINE Pay 通知的訂單 {$merTradeNo} 不存在，略過");
    http_response_code(200);
    echo 'OK';
    exit;
}

// 已有結論就不再動它 —— 避免把已經正確的紀錄改掉
if (in_array($order['status'], array('success', 'failed'), true)) {
    http_response_code(200);
    echo 'OK';
    exit;
}

// 用這筆訂單當初送出時的商店代號去查
$fetch = payuni_fetch_trade_record($merTradeNo, $order['mer_id']);
if (!$fetch['ok']) {
    error_log("LINE Pay 通知後查詢失敗（{$merTradeNo}）：" . $fetch['message']);
    http_response_code(500); // 讓 PAYUNi 之後重送，我們再查一次
    echo 'ERROR';
    exit;
}
$record = $fetch['record'];

$tradeStatus = isset($record['TradeStatus']) ? (string) $record['TradeStatus'] : '';
$dataSource = isset($record['DataSource']) ? $record['DataSource'] : '';

$statusMap = array(
    '0' => 'pending', '9' => 'pending', '1' => 'success',
    '2' => 'failed', '3' => 'failed', '4' => 'failed', '8' => 'pending',
);
$tradeStatusText = array(
    '0' => '等待付款', '9' => '未付款', '1' => '已付款', '2' => '付款失敗',
    '3' => '付款取消', '4' => '交易逾期', '8' => '訂單待確認',
);
$localStatus = isset($statusMap[$tradeStatus]) ? $statusMap[$tradeStatus] : 'pending';

// 資料還不完整就先不下結論
if ($dataSource === 'B') {
    http_response_code(200);
    echo 'OK';
    exit;
}

// 金額不符不認定成功（理由同 linepay-status.php）
if ($localStatus === 'success' && isset($record['TradeAmt'])
    && (int) $record['TradeAmt'] !== (int) $order['amount']) {
    error_log("LINE Pay 通知金額不符：訂單 {$merTradeNo} 應為 {$order['amount']}，"
        . "查詢結果為 {$record['TradeAmt']}，不認定為成功");
    http_response_code(200);
    echo 'OK';
    exit;
}

if ($order['status'] !== $localStatus) {
    try {
        db_update_order_result($conn, $merTradeNo, $localStatus,
            isset($record['TradeNo']) ? $record['TradeNo'] : $order['payuni_trade_no'],
            null, null,
            isset($tradeStatusText[$tradeStatus]) ? $tradeStatusText[$tradeStatus] : null,
            json_encode($record, JSON_UNESCAPED_UNICODE));
        error_log("LINE Pay 背景通知補正訂單 {$merTradeNo}：{$order['status']} -> $localStatus");
    } catch (Exception $e) {
        error_log('LINE Pay 通知更新訂單失敗：' . $e->getMessage());
        http_response_code(500);
        echo 'ERROR';
        exit;
    }
}

http_response_code(200);
echo 'OK';
