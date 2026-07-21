<?php
/**
 * 設備進倉登錄。
 *
 * 由總部進倉人員在收銀機 App 裡用「隱藏的登錄操作」呼叫（見 App 的
 * DeviceEnrollScreen）。把這台機器的硬體資訊寫進 devices 表，讓總後台的
 * 「設備管理」看得到，並蓋上 enrolled_at 代表已進倉。
 *
 * t_id = 硬體序號（serialNo），App 端已能抓到（DeviceInfo.hardwareSerial）。
 * 只需要 API Key（App 內建），不需要登入 token —— 這時機器還沒派給任何客戶。
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

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, array('status' => 'failed', 'message' => '請求格式不是合法的 JSON'));
}

// App 送來的是 DeviceInfo.collect() 那包。至少要有 deviceId。
$d = array(
    'deviceId'       => isset($input['deviceId']) ? (string) $input['deviceId'] : '',
    'serialNo'       => isset($input['serialNo']) ? (string) $input['serialNo'] : '',
    'brand'          => isset($input['brand']) ? (string) $input['brand'] : '',
    'manufacturer'   => isset($input['manufacturer']) ? (string) $input['manufacturer'] : '',
    'model'          => isset($input['model']) ? (string) $input['model'] : '',
    'product'        => isset($input['product']) ? (string) $input['product'] : '',
    'androidVersion' => isset($input['androidVersion']) ? (string) $input['androidVersion'] : '',
    'androidSdk'     => isset($input['androidSdk']) ? (int) $input['androidSdk'] : null,
    'appVersion'     => isset($input['appVersion']) ? (string) $input['appVersion'] : '',
    'hasNfc'         => isset($input['hasNfc']) ? $input['hasNfc'] : null,
    'nfcEnabled'     => isset($input['nfcEnabled']) ? $input['nfcEnabled'] : null,
    'screen'         => isset($input['screen']) ? (string) $input['screen'] : '',
);

if ($d['deviceId'] === '') {
    respond(400, array('status' => 'failed', 'message' => '缺少裝置識別碼'));
}

// ── 授權卡驗證 ──────────────────────────────────────────────────
// 連點 7 下只是找到入口，真正要登錄還得感應一張「授權登錄卡」。
$cardUid = isset($input['cardUid']) ? strtoupper(trim((string) $input['cardUid'])) : '';
if ($cardUid === '') {
    respond(400, array('status' => 'failed', 'message' => '請感應登錄授權卡'));
}

try {
    $conn = db_connect();
    db_create_devices_table_if_not_exists($conn);
    if (!db_is_enroll_card($conn, $cardUid)) {
        // 把 UID 回給 App 顯示，方便管理者到後台把這張卡加成授權卡
        respond(403, array(
            'status' => 'failed',
            'message' => '這張卡未被授權登錄設備',
            'cardUid' => $cardUid,
        ));
    }
    $finalDeviceId = db_enroll_device($conn, $d, $cardUid);
} catch (Exception $e) {
    error_log('設備進倉登錄失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統忙碌，請稍後再試'));
}

respond(200, array(
    'status'   => 'success',
    'message'  => '設備已登錄',
    'serialNo' => $d['serialNo'],       // = t_id
    'model'    => $d['model'],
));
