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
    /*
     * 兩種開班方式併行：
     *   工號 + PIN   店員自己打，不需要卡片
     *   感應卡 + PIN 刷卡帶出身分，比較快
     *
     * 兩者都要 PIN。**卡片不能單獨開班** —— UID 任何手機都讀得到，
     * 空白卡也能改成別人的 UID，它只是「你有什麼」那一半。
     */
    $staffCode = isset($input['staffCode']) ? trim((string) $input['staffCode']) : '';
    $cardUid = isset($input['cardUid']) ? strtoupper(trim((string) $input['cardUid'])) : '';
    $pin = isset($input['pin']) ? (string) $input['pin'] : '';

    if ($cardUid !== '' && !preg_match('/^[0-9A-F]{8,32}$/', $cardUid)) {
        respond(400, array('status' => 'failed', 'message' => '卡片資料格式不正確'));
    }
    if (($staffCode === '' && $cardUid === '') || $pin === '') {
        respond(400, array('status' => 'failed', 'message' => '請輸入工號或感應卡片，並輸入 PIN'));
    }

    $staff = $cardUid !== ''
        ? db_find_staff_by_card($conn, $identity['storeId'], $cardUid)
        : db_find_staff_by_code($conn, $identity['storeId'], $staffCode);

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
        error_log("開班失敗：商店 {$identity['storeId']} "
            . ($cardUid !== '' ? "卡片 {$cardUid}" : "工號 {$staffCode}"));
        respond(200, array(
            'status' => 'failed',
            'message' => $cardUid !== '' ? '卡片或 PIN 不正確' : '工號或 PIN 不正確',
        ));
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

/*
 * ── 建檔：把一張卡登記給某位店員 ──────────────────────────────
 *
 * 授權方式：**必須先由一位有建檔權限的店員開班**。這樣門市自己就能新增
 * 人員，不用每次都回後台。
 *
 * 第一張卡有雞生蛋問題（還沒有任何店員可以授權），這時改用**商店的登入
 * 密碼**放行 —— 那組密碼本來就是開這台機器的人才知道的。
 *
 * ⚠️ 只在「這家店一位店員都還沒有」時才走密碼那條路。有人之後就一律
 *    要求管理者卡，否則等於留了一個永久的密碼後門。
 */
if ($action === 'enroll') {
    $cardUid = isset($input['cardUid']) ? strtoupper(trim((string) $input['cardUid'])) : '';
    $name = isset($input['name']) ? trim((string) $input['name']) : '';
    $staffCode = isset($input['staffCode']) ? trim((string) $input['staffCode']) : '';
    $pin = isset($input['pin']) ? (string) $input['pin'] : '';
    $canRefund = !empty($input['canRefund']) ? 1 : 0;
    $canEnroll = !empty($input['canEnroll']) ? 1 : 0;
    $storePassword = isset($input['storePassword']) ? (string) $input['storePassword'] : '';

    if (!preg_match('/^[0-9A-F]{8,32}$/', $cardUid)) {
        respond(400, array('status' => 'failed', 'message' => '卡片資料格式不正確'));
    }
    if ($name === '') {
        respond(400, array('status' => 'failed', 'message' => '請輸入持卡人姓名'));
    }
    if (!preg_match('/^\d{4,8}$/', $pin)) {
        respond(400, array('status' => 'failed', 'message' => 'PIN 必須是 4 到 8 位數字'));
    }
    if ($staffCode !== '' && !preg_match('/^[A-Za-z0-9_-]{1,16}$/', $staffCode)) {
        respond(400, array('status' => 'failed', 'message' => '工號格式不正確'));
    }

    $existingCount = db_count_staff($conn, $identity['storeId']);
    $authorized = false;

    if ($identity['staffId']) {
        // 已經有人開班 —— 看他有沒有建檔權限
        $me = db_find_staff($conn, $identity['staffId']);
        $authorized = $me && (int) $me['can_enroll'] === 1;
        if (!$authorized) {
            respond(403, array(
                'status' => 'failed',
                'message' => '目前開班的人員沒有建檔權限，請由店長操作。',
                'noPermission' => true,
            ));
        }
    } elseif ($existingCount === 0) {
        // 第一張卡：用商店登入密碼放行
        $merchant = db_find_merchant($conn, $identity['merchantId']);
        $authorized = $merchant && $storePassword !== ''
            && password_verify($storePassword, $merchant['password_hash']);
        if (!$authorized) {
            respond(403, array(
                'status' => 'failed',
                'message' => '這是第一張卡，請輸入收銀機登入用的商店密碼以確認身分。',
                'needStorePassword' => true,
            ));
        }
    } else {
        respond(403, array(
            'status' => 'failed',
            'message' => '請先由有建檔權限的店長刷卡開班，才能登記新卡片。',
            'needShift' => true,
        ));
    }

    // 這張卡已經給別人用了就擋下 —— 不然會有兩個人共用一張卡的身分
    $dup = db_find_staff_by_card($conn, $identity['storeId'], $cardUid);
    if ($dup) {
        respond(400, array(
            'status' => 'failed',
            'message' => '這張卡已經登記給「' . $dup['name'] . '」，請先在後台解除綁定。',
        ));
    }

    // 沒填工號就自動編一個，讓兩種開班方式都能用
    if ($staffCode === '') {
        $staffCode = 'C' . substr($cardUid, -6);
    }

    try {
        db_save_staff($conn, 0, $identity['storeId'], $staffCode, $name, $pin,
            $canRefund, 1, '收銀機建檔', $cardUid, $canEnroll);
    } catch (Exception $e) {
        error_log('收銀機建檔失敗：' . $e->getMessage());
        respond(500, array('status' => 'failed', 'message' => '建檔失敗，工號可能重複'));
    }

    error_log("收銀機建檔：商店 {$identity['storeId']} 新增 {$name}（{$staffCode}）");
    respond(200, array(
        'status' => 'success',
        'name' => $name,
        'staffCode' => $staffCode,
        'bootstrap' => ($existingCount === 0),
    ));
}

respond(400, array('status' => 'failed', 'message' => 'unknown action'));
