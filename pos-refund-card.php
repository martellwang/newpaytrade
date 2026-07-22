<?php
/**
 * 感應同卡 → 退款（收執聯 QR 掃不出來時的補救路徑）。
 *
 * 情境：客人的收執聯破損／QR 掃不出來，無法走 pos-refund-scan.php。
 * 有退款權限的人在收銀機「交易紀錄」找到那筆交易，請客人把卡交出來，
 * 感應同一張卡；App 讀到卡號的前六碼＋末四碼送來這裡比對。
 *
 * 兩步（與 pos-refund-scan.php 同一套時序，方便 App 共用回應解析）：
 *   1) 預設（不帶 confirm）：驗證權限＋商店歸屬＋卡號相符，回傳訂單摘要供確認
 *   2) confirm=true：確認後才真正退款（內部轉呼叫 refund.php，沿用所有退款規則）
 *
 * 憑證是「卡在現場再感應一次」取代 QR。三重把關：
 *   - 操作者具退款權限（開班＋canRefund）
 *   - 訂單所屬 store_id == 掃碼收銀機登入的商店（不能跨店退）
 *   - 感應到的卡號前六＋末四 == 該筆交易存的卡號（同一張卡）
 * 全卡號基於 PCI 不落地，所以只能比對前六＋末四 —— 碰撞機率極低，
 * 再加上「操作者有權限＋卡在現場」兩道，足以作為退款憑證。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pos_auth.php';

header('Content-Type: application/json; charset=utf-8');

function respond($code, $body) {
    http_response_code($code);
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

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, array('status' => 'failed', 'message' => '請求格式不是合法的 JSON'));
}
$merTradeNo = isset($input['merTradeNo']) ? (string) $input['merTradeNo'] : '';
$card6 = isset($input['card6']) ? preg_replace('/\D/', '', (string) $input['card6']) : '';
$last4 = isset($input['last4']) ? preg_replace('/\D/', '', (string) $input['last4']) : '';
$confirm = !empty($input['confirm']);

if ($merTradeNo === '') {
    respond(400, array('status' => 'failed', 'message' => '缺少 merTradeNo'));
}
if (strlen($card6) !== 6 || strlen($last4) !== 4) {
    respond(400, array('status' => 'failed', 'message' => '卡號資料不完整，請重新感應卡片'));
}

// 1) 掃碼收銀機的登入身分（要知道它登入哪家店）
$posToken = isset($_SERVER['HTTP_X_POS_TOKEN']) ? $_SERVER['HTTP_X_POS_TOKEN'] : '';
$identity = pos_resolve_identity($posToken, false);
if (!$identity['ok']) {
    respond($identity['httpCode'], $identity['body']);
}
$scanStoreId = (int) $identity['storeId'];

// 1.5) 退款權限前置把關（訊息與旗標與 refund.php／pos-refund-scan.php 一致）
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

$conn = db_connect();
$order = db_find_order($conn, $merTradeNo);
if (!$order) {
    respond(404, array('status' => 'failed', 'message' => '找不到這筆交易'));
}

// 2) 訂單所屬商店必須等於收銀機登入的店（防跨店退款）
if ((int) $order['store_id'] !== $scanStoreId) {
    respond(403, array(
        'status' => 'failed',
        'message' => '這筆交易不屬於這家店，無法退款',
    ));
}

// 3) 感應到的卡號必須與這筆交易的卡相符（前六＋末四）
$orderCard6 = isset($order['card6_no']) ? preg_replace('/\D/', '', (string) $order['card6_no']) : '';
$orderCard4 = isset($order['card4_no']) ? preg_replace('/\D/', '', (string) $order['card4_no']) : '';
if ($orderCard6 === '' || $orderCard4 === '') {
    // 沒存卡號的交易（例如掃碼收款）無法用感應卡比對
    respond(422, array(
        'status' => 'failed',
        'message' => '這筆交易沒有可比對的卡號，無法用感應卡退款',
    ));
}
if (!hash_equals($orderCard6, $card6) || !hash_equals($orderCard4, $last4)) {
    respond(200, array(
        'status' => 'failed',
        'message' => '感應的卡片與這筆交易不符，請確認是同一張卡',
        'cardMismatch' => true,
    ));
}

$amount = (int) $order['amount'];
$refunded = db_sum_refunded_amount($conn, $merTradeNo);
$remaining = $amount - $refunded;

// ── 第一步：卡號相符，只回摘要讓店員確認金額，不動錢 ──
if (!$confirm) {
    respond(200, array(
        'status' => 'success',
        'stage' => 'confirm',
        'order' => array(
            'merTradeNo' => $order['mer_trade_no'],
            'storeOrderNo' => isset($order['store_order_no']) ? $order['store_order_no'] : null,
            'amount' => $amount,
            'refunded' => $refunded,
            'remaining' => $remaining,
            'card4No' => isset($order['card4_no']) ? $order['card4_no'] : null,
            'paymentMethod' => isset($order['payment_method']) ? $order['payment_method'] : null,
            'createdAt' => isset($order['created_at']) ? $order['created_at'] : null,
            'orderStatus' => $order['status'],
        ),
    ));
}

// ── 第二步：確認後才退款。轉呼叫 refund.php，沿用金額上限、分期只能全額退、
//    重複退款保護等所有規則；並把收銀機 token 帶過去做開班／退款權限把關。 ──
$ch = curl_init(PUBLIC_BASE_URL . '/refund.php');
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array('merTradeNo' => $merTradeNo)),
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json',
        'X-API-Key: ' . BACKEND_API_KEY,
        'X-POS-Token: ' . $posToken,
    ),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 40,
));
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false) {
    respond(502, array('status' => 'failed', 'message' => '退款服務連線失敗，請稍後再試'));
}
// 原封不動把 refund.php 的結果與狀態碼回給 App
http_response_code($httpCode ?: 200);
echo $resp;
