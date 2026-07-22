<?php
/**
 * 掃收執聯 QR → 快速退款。
 *
 * 收執聯下方印的 QR 是簽章 token（見 refund_token.php）。收銀機掃到後把 token
 * 送來這裡。兩步：
 *   1) 預設（不帶 confirm）：驗證 token + 商店歸屬，回傳訂單摘要供店員確認金額
 *   2) confirm=true：確認後才真正退款（內部轉呼叫 refund.php，沿用所有退款規則）
 *
 * 「由該商店產出」的三重檢核：
 *   - token 的 HMAC 簽章有效（＝本系統產生，偽造不了）
 *   - token 內的 storeId == 掃碼收銀機登入的商店
 *   - 該訂單所屬的 store_id == 掃碼收銀機登入的商店
 * 三者任一不符就拒絕。退款權限（開班、canRefund）由 refund.php 再把關。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pos_auth.php';
require_once __DIR__ . '/refund_token.php';

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
$token = isset($input['token']) ? (string) $input['token'] : '';
$confirm = !empty($input['confirm']);

// 1) 掃碼收銀機的登入身分（要知道它登入哪家店）
$posToken = isset($_SERVER['HTTP_X_POS_TOKEN']) ? $_SERVER['HTTP_X_POS_TOKEN'] : '';
$identity = pos_resolve_identity($posToken, false);
if (!$identity['ok']) {
    respond($identity['httpCode'], $identity['body']);
}
$scanStoreId = (int) $identity['storeId'];

// 2) 驗證 QR token 的簽章
$v = refund_token_verify($token);
if (!$v['ok']) {
    respond(400, array('status' => 'failed', 'message' => $v['error']));
}

// 3) token 的 storeId 必須等於掃碼收銀機登入的店
if ((int) $v['storeId'] !== $scanStoreId) {
    respond(403, array(
        'status' => 'failed',
        'message' => '這張收執聯不是這家店開出的，無法在此退款',
    ));
}

$conn = db_connect();
$order = db_find_order($conn, $v['merTradeNo']);
if (!$order) {
    respond(404, array('status' => 'failed', 'message' => '找不到這筆交易'));
}

// 4) 訂單所屬商店也必須等於掃碼收銀機的店（防跨店退款）
if ((int) $order['store_id'] !== $scanStoreId) {
    respond(403, array(
        'status' => 'failed',
        'message' => '這筆交易不屬於這家店，無法退款',
    ));
}

$amount = (int) $order['amount'];
$refunded = db_sum_refunded_amount($conn, $v['merTradeNo']);
$remaining = $amount - $refunded;

// ── 第一步：只回摘要，讓店員先確認金額，不動錢 ──
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
    CURLOPT_POSTFIELDS => json_encode(array('merTradeNo' => $v['merTradeNo'])),
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
