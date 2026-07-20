<?php
/**
 * 開班／交班／查目前班次。
 *
 * ── 輕量做法：不建 shifts 資料表 ──────────────────────────────
 *
 * 「這一班收了多少」本來就能從交易紀錄推導（這位店員 + 開班時間之後），
 * 多開一張表只是多一份要同步的狀態。班次只存在 merchant_sessions 的
 * 兩個欄位裡：staff_id 與 shift_started_at。
 *
 * 代價：**交班之後那個班次就查不到了**（沒有留存）。日後若需要歷史班次
 * 報表，再補一張表把交班當下的彙總存下來即可，現有欄位都夠用。
 *
 * ── 店員身分綁在 session，不由 App 指定 ──────────────────────
 *
 * 與商店代號同一個原則。App 自己帶 staffId 的話，改一個數字就能把交易
 * 記到別人頭上，而交班單與退款權限都是靠這個算的。
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

try {
    $conn = db_connect();
    db_create_store_staff_table_if_not_exists($conn);
} catch (Exception $e) {
    error_log('班次操作時資料庫失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統忙碌中，請稍後再試'));
}

/** 組出目前班次的狀態，三個動作結束後都回傳同一份 */
function current_shift($conn, $identity) {
    if (!$identity['staffId']) {
        return array('status' => 'success', 'onShift' => false);
    }
    $summary = db_sum_shift($conn, $identity['storeId'], $identity['staffId'],
        $identity['shiftStartedAt']);
    return array(
        'status' => 'success',
        'onShift' => true,
        'staffName' => $identity['staffName'],
        'staffCode' => $identity['staffCode'],
        'canRefund' => $identity['canRefund'],
        'startedAt' => $identity['shiftStartedAt'],
        'count' => $summary['count'],
        'total' => $summary['total'],
    );
}

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'status';

if ($action === 'status') {
    respond(200, current_shift($conn, $identity));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, array('status' => 'failed', 'message' => 'method not allowed'));
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) { $input = array(); }

if ($action === 'start') {
    $staffCode = isset($input['staffCode']) ? trim((string) $input['staffCode']) : '';
    $pin = isset($input['pin']) ? (string) $input['pin'] : '';

    if ($staffCode === '' || $pin === '') {
        respond(400, array('status' => 'failed', 'message' => '請輸入工號與 PIN'));
    }

    $staff = db_find_staff_by_code($conn, $identity['storeId'], $staffCode);

    /*
     * 工號不存在時也跑一次假雜湊比對。
     *
     * 不做的話，回應快慢就洩漏了「哪些工號存在」—— 工號多半是 01、02 這種
     * 好猜的短碼，列舉成本極低。
     */
    $dummy = '$2y$10$usesomesillystringforsalttoavoidtimingleakabcdefghijklmn';
    $ok = $staff
        ? password_verify($pin, $staff['pin_hash'])
        : (password_verify($pin, $dummy) && false);

    if (!$ok) {
        /*
         * 訊息刻意不分「查無此工號」與「PIN 錯誤」。
         *
         * ⚠️ 這裡**沒有做連續失敗鎖定**，與收銀機登入不同。理由是威脅模型
         *    不一樣：要試 PIN 得先有一台已經用商店帳號登入的機器，攻擊者
         *    已經在店裡了。加上鎖定反而會讓店員打錯兩次就開不了班，
         *    在櫃檯前造成的損失大於防護價值。
         *
         *    但這代表 **PIN 不可以被拿去當獨立的認證手段** —— 它的安全性
         *    完全依賴「機器已授權」這個前提。
         */
        error_log("開班失敗：商店 {$identity['storeId']} 工號 {$staffCode}");
        respond(200, array('status' => 'failed', 'message' => '工號或 PIN 不正確'));
    }

    try {
        db_start_shift($conn, $identity['sessionId'], (int) $staff['id']);
    } catch (Exception $e) {
        error_log('開班失敗：' . $e->getMessage());
        respond(500, array('status' => 'failed', 'message' => '系統忙碌中，請稍後再試'));
    }

    // 重新解析一次，讓回傳的班次狀態來自資料庫而不是我們手上的舊值
    $identity = pos_resolve_identity($posToken, false);
    respond(200, current_shift($conn, $identity));
}

if ($action === 'end') {
    if (!$identity['staffId']) {
        respond(200, array('status' => 'success', 'onShift' => false));
    }
    // 先算好彙總再解除綁定 —— 解除後就查不到這個班次了
    $summary = current_shift($conn, $identity);
    try {
        db_end_shift($conn, $identity['sessionId']);
    } catch (Exception $e) {
        error_log('交班失敗：' . $e->getMessage());
        respond(500, array('status' => 'failed', 'message' => '系統忙碌中，請稍後再試'));
    }
    $summary['onShift'] = false;
    $summary['ended'] = true;
    respond(200, $summary);
}

respond(400, array('status' => 'failed', 'message' => 'unknown action'));
