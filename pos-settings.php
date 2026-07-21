<?php
/**
 * 收銀機開機／定時拉取的設定。目前只有校時用的時間伺服器。
 *
 * 為什麼獨立一支：這些是「後台可調、App 要跟著變」的參數，不該編進 APK
 * （改一次就要重新簽章／發版）。放這裡讓 App 定時來拉，後台改完即時生效。
 *
 * 只需要 API Key（不含敏感資料，也不綁特定商店）。回傳伺服器目前時間，
 * 讓 App 萬一連不到 NTP 時，至少能用「跟我們後端的時間差」當備援校時。
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

try {
    $conn = db_connect();
    db_create_app_settings_table_if_not_exists($conn);
    $timeServer = db_get_setting($conn, 'time_server', '');
} catch (Exception $e) {
    error_log('pos-config 讀取設定失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統忙碌，請稍後再試'));
}

respond(200, array(
    'status' => 'success',
    // 校時用的時間伺服器（NTP）。空字串代表後台未設定 = 停用校時。
    'timeServer' => $timeServer,
    // 伺服器目前時間（epoch 毫秒），供 App 備援校時與比對用
    'serverTimeMs' => (int) round(microtime(true) * 1000),
));
