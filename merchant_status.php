<?php
/**
 * 查詢這個商店代號目前開通了哪些支付工具與分期期數。
 *
 * 這支是第一個走「上游驅動」架構的端點：本身不知道 PAYUNi 的任何規則，
 * 只負責認證、快取、回傳。實際怎麼呼叫上游寫在 providers/<driver>.php。
 * 未來接第二家上游時，這個檔案不用改。
 *
 * 收銀 App 開機時呼叫一次，用回傳的分期期數狀態把沒開通的期數在畫面上
 * 停用（灰色），避免收銀員選了之後在客人面前才被拒。
 *
 * === 為什麼要快取 ===
 * 每台收銀機開機都查，加上 App 可能被系統回收後重啟，實際呼叫次數會遠超
 * 想像。商店的開通狀態是幾天甚至幾個月才變一次的東西，沒有理由即時查。
 * 預設快取 6 小時，可用 ?refresh=1 強制重新抓（管理介面用）。
 *
 * === 查不到的時候怎麼辦 ===
 * 一律回傳 available=null 讓 App 退回「所有期數都可選」。
 * **不能因為狀態查不到就擋住收款** —— 那是拿確定的營業損失去換一個
 * 不確定的體驗改善。選了沒開通的期數頂多授權失敗，收銀員改一次付清即可。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/providers.php';
require_once __DIR__ . '/db.php';

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

/** 快取多久（秒）。商店開通狀態變動頻率極低，6 小時很夠。 */
define('MERCHANT_STATUS_CACHE_SEC', 21600);

// 之後要支援「這台收銀機走哪家上游」時，這裡改成依裝置查即可
$providerName = isset($_REQUEST['provider']) ? $_REQUEST['provider'] : null;
$provider = provider_get($providerName);

if ($provider === null || !provider_load_driver($provider)) {
    respond(200, array(
        'status' => 'unavailable',
        'message' => '找不到可用的上游設定',
        'available' => null,
    ));
}

$driver = $provider['driver'];
$merId = provider_credential($provider, 'mer_id');
// 快取以「上游 + 商店代號」為鍵：不同上游的同一個商店代號是不同的東西
$cacheKey = $provider['name'] . ':' . $merId;

try {
    $conn = db_connect();
    db_create_merchant_status_table_if_not_exists($conn);
} catch (Exception $e) {
    error_log('商店狀態：資料庫連線失敗：' . $e->getMessage());
    respond(200, array('status' => 'unavailable', 'message' => '系統忙碌', 'available' => null));
}

$forceRefresh = isset($_REQUEST['refresh']) && $_REQUEST['refresh'] === '1';
$cached = db_get_merchant_status($conn, $cacheKey);

if (!$forceRefresh && $cached && (time() - strtotime($cached['fetched_at'])) < MERCHANT_STATUS_CACHE_SEC) {
    $payload = json_decode($cached['payload'], true);
    $payload['cached'] = true;
    $payload['fetchedAt'] = $cached['fetched_at'];
    respond(200, $payload);
}

/**
 * 查詢失敗時優先回傳過期的快取 —— 舊資料也比沒資料好。
 * 商店開通狀態幾乎不變，用昨天的結果做灰階顯示完全可以接受。
 */
function fallback_to_stale_cache($cached, $message) {
    if ($cached) {
        $payload = json_decode($cached['payload'], true);
        $payload['cached'] = true;
        $payload['stale'] = true;
        $payload['fetchedAt'] = $cached['fetched_at'];
        $payload['message'] = $message . '（顯示上次查詢結果）';
        respond(200, $payload);
    }
    respond(200, array('status' => 'unavailable', 'message' => $message, 'available' => null));
}

// ── 交給上游驅動處理，這裡不知道任何一家的規則 ──────────────────
$fetch = call_user_func($driver . '_merchant_status', $provider);
if (empty($fetch['ok'])) {
    fallback_to_stale_cache($cached, isset($fetch['message']) ? $fetch['message'] : '查詢失敗');
}

$payload = call_user_func($driver . '_normalize_merchant_status', $fetch['detail'], $merId);
$payload['provider'] = $provider['name'];
$payload['providerLabel'] = isset($provider['label']) ? $provider['label'] : $provider['name'];
$payload['cached'] = false;

try {
    db_save_merchant_status($conn, $cacheKey, json_encode($payload, JSON_UNESCAPED_UNICODE));
} catch (Exception $e) {
    // 存不進去不影響這次回應，下次再查就是了
    error_log('商店狀態寫入快取失敗：' . $e->getMessage());
}

respond(200, $payload);
