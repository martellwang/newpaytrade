<?php
/** 查詢單筆訂單目前狀態，App 輪詢用。網址：status.php?merTradeNo=xxx */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

function respond($statusCode, $body) {
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$headers = getallheaders();
$apiKey = isset($headers['X-API-Key']) ? $headers['X-API-Key'] : '';
if ($apiKey === '' || $apiKey !== BACKEND_API_KEY) {
    respond(401, array('status' => 'failed', 'message' => 'unauthorized'));
}

$merTradeNo = isset($_GET['merTradeNo']) ? $_GET['merTradeNo'] : '';
if ($merTradeNo === '') {
    respond(400, array('error' => '缺少 merTradeNo'));
}

try {
    $conn = db_connect();
    $order = db_find_order($conn, $merTradeNo);
} catch (Exception $e) {
    error_log('查詢訂單失敗：' . $e->getMessage());
    respond(500, array('error' => '系統錯誤，請稍後再試'));
}

if (!$order) {
    respond(404, array('error' => '找不到訂單'));
}

respond(200, array(
    'merTradeNo' => $order['mer_trade_no'],
    'status' => $order['status'],
    'amount' => (int) $order['amount'],
    'payuniTradeNo' => $order['payuni_trade_no'],
    'authCode' => $order['auth_code'],
    'card4No' => $order['card4_no'],
    'message' => $order['message'],
    'createdAt' => $order['created_at'],
    'updatedAt' => $order['updated_at'],
));
