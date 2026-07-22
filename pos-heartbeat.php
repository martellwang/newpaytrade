<?php
/**
 * 收銀機心跳。
 *
 * App 登入後即使閒置也定時（每幾分鐘）呼叫這支，讓 devices.last_seen 持續更新，
 * 後台的「機隊總覽」才能反映「目前開機／連線中」而不只是「最近有交易」。
 *
 * 送的內容就是交易時同一份 DeviceInfo，直接沿用 db_upsert_device：既更新
 * last_seen，也順便讓「開機但還沒交易」的新機自動登記進 devices（顯示為上線）。
 * 只需 API Key（App 手上本來就有）；不動任何金流或個資。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

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

$device = json_decode(file_get_contents('php://input'), true);
if (!is_array($device) || empty($device['deviceId'])) {
    respond(400, array('status' => 'failed', 'message' => '缺少裝置資訊'));
}

try {
    $conn = db_connect();
    db_upsert_device($conn, array(
        'deviceId' => substr((string) $device['deviceId'], 0, 64),
        'serialNo' => isset($device['serialNo']) ? $device['serialNo'] : null,
        'brand' => isset($device['brand']) ? $device['brand'] : null,
        'manufacturer' => isset($device['manufacturer']) ? $device['manufacturer'] : null,
        'model' => isset($device['model']) ? $device['model'] : null,
        'product' => isset($device['product']) ? $device['product'] : null,
        'androidVersion' => isset($device['androidVersion']) ? $device['androidVersion'] : null,
        'androidSdk' => isset($device['androidSdk']) ? $device['androidSdk'] : null,
        'appVersion' => isset($device['appVersion']) ? $device['appVersion'] : null,
        'hasNfc' => isset($device['hasNfc']) ? $device['hasNfc'] : null,
        'nfcEnabled' => isset($device['nfcEnabled']) ? $device['nfcEnabled'] : null,
        'screen' => isset($device['screen']) ? $device['screen'] : null,
    ));
} catch (Exception $e) {
    error_log('心跳登記裝置失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統忙碌'));
}

respond(200, array('status' => 'success', 'serverTimeMs' => (int) round(microtime(true) * 1000)));
