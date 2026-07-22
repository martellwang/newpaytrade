<?php
/**
 * 查交易 → 人工核對 → 退款（掃碼收款用）。
 *
 * 掃碼收款（LINE Pay／街口／悠遊付等）沒有實體卡可以「感應同卡」核對身分，
 * 但退款一定退回原付款錢包，所以真正的風險只是「惡意作廢別人的正常交易」，
 * 用退款權限＋店員人工核對即可壓下。
 *
 * 流程與 pos-refund-scan／pos-refund-card 同一套時序，App 共用回應解析：
 *   1) 預設（不帶 confirm）：驗權限＋商店歸屬，回訂單摘要（金額/時間/交易序號），
 *      讓店員對照客人手機的錢包紀錄。
 *   2) confirm=true：必須同時帶 manualVerified=true（店員已勾「已核對客人錢包
 *      紀錄」）才真正退款，轉呼叫 refund.php。
 *
 * manualVerified 是店員的聲明，伺服器無法代為查證，但強制帶這個旗標能讓
 * 「未核對就退款」在流程上做不到，也留下這筆退款是經人工核對後送出的軌跡。
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
$confirm = !empty($input['confirm']);
$manualVerified = !empty($input['manualVerified']);

if ($merTradeNo === '') {
    respond(400, array('status' => 'failed', 'message' => '缺少 merTradeNo'));
}

// 1) 收銀機的登入身分（要知道登入哪家店）
$posToken = isset($_SERVER['HTTP_X_POS_TOKEN']) ? $_SERVER['HTTP_X_POS_TOKEN'] : '';
$identity = pos_resolve_identity($posToken, false);
if (!$identity['ok']) {
    respond($identity['httpCode'], $identity['body']);
}
$scanStoreId = (int) $identity['storeId'];

// 1.5) 退款權限前置把關（訊息與旗標與其他退款端點一致）
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

$amount = (int) $order['amount'];
$refunded = db_sum_refunded_amount($conn, $merTradeNo);
$remaining = $amount - $refunded;

// ── 第一步：回摘要，讓店員對照客人錢包紀錄，不動錢 ──
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
            'payuniTradeNo' => isset($order['payuni_trade_no']) ? $order['payuni_trade_no'] : null,
            'paymentMethod' => isset($order['payment_method']) ? $order['payment_method'] : null,
            'createdAt' => isset($order['created_at']) ? $order['created_at'] : null,
            'orderStatus' => $order['status'],
        ),
    ));
}

// ── 第二步：確認退款，但必須已人工核對 ──
if (!$manualVerified) {
    respond(400, array(
        'status' => 'failed',
        'message' => '請先核對客人的付款紀錄並勾選確認，再送出退款',
        'needManualVerify' => true,
    ));
}

// 轉呼叫 refund.php，沿用金額上限、重複退款保護等所有規則，並帶收銀機 token 把關。
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
http_response_code($httpCode ?: 200);
echo $resp;
