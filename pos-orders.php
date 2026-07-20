<?php
/**
 * 收銀機的交易紀錄查詢。
 *
 * 給店員在櫃檯查「剛才那筆到底成功了沒」、日結時對帳，以及日後退款時
 * 找出要退的那一筆。
 *
 * ── 範圍一律由登入身分決定 ──────────────────────────────────
 *
 * 查得到什麼**完全由 X-POS-Token 決定**，呼叫端不能指定商店。
 * 讓 App 自己帶 storeId 的話，任何拿到 API Key 的人都能把別家商店的
 * 營業額一天一天問出來。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
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

$posToken = isset($_SERVER['HTTP_X_POS_TOKEN']) ? $_SERVER['HTTP_X_POS_TOKEN'] : '';
$identity = pos_resolve_identity($posToken, false);
if (!$identity['ok']) {
    respond($identity['httpCode'], $identity['body']);
}

/*
 * scope=device（預設）只看這台機器，scope=store 看整家商店。
 *
 * 預設只看本機，是因為店員最常做的事是「找剛才那筆」和「跟自己這台的
 * 現金抽屜對帳」—— 混進別台的交易只會干擾。要看全店是店長的需求，
 * 得明確指定。
 */
$scope = isset($_REQUEST['scope']) ? $_REQUEST['scope'] : 'device';
$deviceId = isset($_REQUEST['deviceId']) ? substr((string) $_REQUEST['deviceId'], 0, 64) : '';

if ($scope === 'device' && $deviceId === '') {
    respond(400, array('status' => 'failed', 'message' => '缺少 deviceId'));
}
$filterDevice = ($scope === 'store') ? null : $deviceId;

// 日期。預設今天 —— 收銀機上翻歷史紀錄的需求遠少於查當天。
$date = isset($_REQUEST['date']) ? (string) $_REQUEST['date'] : date('Y-m-d');
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    respond(400, array('status' => 'failed', 'message' => '日期格式不正確'));
}

$limit = isset($_REQUEST['limit']) ? (int) $_REQUEST['limit'] : 100;
// 上限 200：收銀機螢幕小，撈太多只是拖慢畫面，翻頁請改日期
$limit = max(1, min(200, $limit));

try {
    $conn = db_connect();
    $orders = db_list_store_orders($conn, $identity['storeId'], $filterDevice, $date, $limit);
    $summary = db_sum_store_orders($conn, $identity['storeId'], $filterDevice, $date);
} catch (Exception $e) {
    error_log('收銀機交易紀錄查詢失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統忙碌中，請稍後再試'));
}

// 把資料庫的內部值翻成收銀員看得懂的字
$methodText = array(
    'credit' => '信用卡',
    'wallet' => '行動支付',
    'linepay' => 'LINE Pay',
);
$statusText = array(
    'success' => '成功',
    'failed' => '失敗',
    'pending' => '處理中',
);

$list = array();
foreach ($orders as $o) {
    $method = isset($o['payment_method']) ? $o['payment_method'] : 'credit';
    $inst = isset($o['card_inst']) ? (int) $o['card_inst'] : 1;
    $list[] = array(
        'merTradeNo' => $o['mer_trade_no'],
        'amount' => (int) $o['amount'],
        'status' => $o['status'],
        'statusText' => isset($statusText[$o['status']]) ? $statusText[$o['status']] : $o['status'],
        'method' => $method,
        'methodText' => isset($methodText[$method]) ? $methodText[$method] : $method,
        // 分期期數只在大於 1 時才有意義，1 就是一次付清
        'cardInst' => $inst,
        'card4No' => $o['card4_no'],
        'authCode' => $o['auth_code'],
        'payuniTradeNo' => $o['payuni_trade_no'],
        'message' => $o['message'],
        'createdAt' => $o['created_at'],
        // 讓店長看全店時分得出是哪一台收的
        'deviceId' => $o['device_id'],
        // 經手人。沒開班就收款的交易這裡是空的
        'staffName' => isset($o['staff_name']) ? $o['staff_name'] : null,
    );
}

respond(200, array(
    'status' => 'success',
    'scope' => $scope,
    'date' => $date,
    'orders' => $list,
    /*
     * 彙總只算成功的並扣掉退款 —— 這個數字是拿來跟現場對帳的。
     * 把處理中的算進去會讓店員以為收到了實際上沒收到的錢。
     */
    'summary' => $summary,
));
