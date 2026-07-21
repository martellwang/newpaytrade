<?php
/**
 * 收銀機登入。
 *
 * 店員輸入「客戶編號 + 帳號 + 密碼」→ 這裡驗證 → 回傳 token 與可選分店。
 * 之後所有交易都帶那個 token，後端據此決定用哪個商店代號（MerID）送出。
 *
 * === 為什麼要客戶編號 ===
 * 不同客戶很容易取到相同的帳號名稱（admin、pos、001…）。客戶編號讓帳號
 * 只需在同一個客戶底下唯一，各家自由命名不互相卡位。
 *
 * 用系統配發的純數字編號而不是統編／身分證字號：
 *   - 身分證字號去掉開頭字母後不保證唯一（A/M、C/I、K/L 的檢查碼貢獻
 *     相同，C123456789 與 I123456789 可以同時合法）
 *   - 身分證字號是敏感個資，不該進登入流程與 log
 *   - 純數字方便在收銀機的數字鍵盤輸入
 *
 * === 為什麼用 token 而不是每次送帳密 ===
 * 帳密只在登入時出現一次。token 外洩的影響比帳密小，也能單獨撤銷
 *（管理介面的「登出所有收銀機」）。資料庫只存 token 的雜湊。
 *
 * === 錯誤訊息刻意模糊 ===
 * 編號不存在、帳號錯、密碼錯、已停用，一律回同一句。分開講等於告訴
 * 攻擊者「這個編號存在，只是密碼錯了」，那是免費的帳號列舉管道。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rate_limit.php';

header('Content-Type: application/json; charset=utf-8');

function respond($statusCode, $body) {
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 把商店整理成 App 要的精簡格式（只給末四碼，完整 MerID 收銀機不需要） */
function store_public_view($st) {
    return array(
        'id' => (int) $st['id'],
        'name' => $st['name'],
        'merIdMasked' => strlen($st['mer_id']) > 4
            ? str_repeat('*', strlen($st['mer_id']) - 4) . substr($st['mer_id'], -4)
            : $st['mer_id'],
    );
}

/**
 * 員工登入：商店代號 + 感應卡／工號 + PIN → 綁定機器並同時開班。
 *
 * ── 為什麼卡片/工號能開整台機器 ──────────────────────────────
 *
 * 這是刻意的設計選擇：讓一般員工不必知道店主的網站後台密碼，也能在交接班時
 * 把機器開起來。代價是「卡片+PIN」在這條路上等同於一組機器層級的憑證，
 * 信任邊界比較低 —— 但收銀機本來就放在店裡，攻擊者要試 PIN 得先實體接觸
 * 機器，這個取捨在門市場景是可接受的。
 *
 * ── 商店怎麼決定 ──────────────────────────────────────────────
 *
 * 主路徑：**商店代號直接對應一家店**（store_code 唯一），不必選分店。
 * 舊版相容：沒帶商店代號、改帶客戶編號時，退回舊的「靠卡/工號反推分店」邏輯
 * （見 staff_login_by_customer）。
 */
function staff_login($conn, $storeCode, $customerCode, $staffCard, $staffCode, $pin, $deviceId, $storeId) {
    $dummyHash = '$2y$10$usesomesillystringforsalttoavoidtimingleakabcdefghijklmn';

    // ── 主路徑：商店代號 ──────────────────────────────────────────
    if ($storeCode !== '') {
        $store = db_find_store_by_code($conn, $storeCode);
        if (!$store || (int) $store['enabled'] !== 1 || (int) $store['merchant_enabled'] !== 1) {
            password_verify($pin, $dummyHash);   // 時間對齊
            rl_record_failure($conn);
            respond(200, array('status' => 'failed', 'message' => '商店代號、卡片／工號或 PIN 不正確'));
        }

        /*
         * ── 資產綁定：機器只能登入被派到的客戶的店 ──────────────────
         *
         * 這台機器（依 deviceId）必須已被總部派給「這家店所屬的客戶」才能登入。
         * 沒派工、或派給別的客戶（含只派到經銷商、還沒落到客戶），一律擋下。
         * 這樣機器擺錯客戶就用不了，達到資產控管。
         */
        $device = db_find_device($conn, $deviceId);
        $dispatchedMerchant = ($device && $device['dispatched_merchant_id'] !== null)
            ? (int) $device['dispatched_merchant_id'] : 0;
        if ($dispatchedMerchant !== (int) $store['merchant_id']) {
            error_log("派工不符：device {$deviceId} 派給客戶 {$dispatchedMerchant}，"
                . "但想登入客戶 {$store['merchant_id']} 的店 {$storeCode}");
            respond(200, array(
                'status' => 'failed',
                'message' => '這台機器尚未派給這家店所屬的客戶，請聯絡總部派工',
            ));
        }

        $staff = $staffCard !== ''
            ? db_find_staff_by_card($conn, (int) $store['id'], $staffCard)
            : db_find_staff_by_code($conn, (int) $store['id'], $staffCode);
        if (!$staff || !password_verify($pin, $staff['pin_hash'])) {
            if (!$staff) { password_verify($pin, $dummyHash); }
            rl_record_failure($conn);
            respond(200, array(
                'status' => 'failed',
                'message' => $staffCard !== '' ? '卡片或 PIN 不正確' : '工號或 PIN 不正確',
            ));
        }

        db_reset_pos_login_failures($conn, $deviceId);
        try {
            $token = db_create_merchant_session($conn, (int) $store['merchant_id'], (int) $store['id'], $deviceId);
            $session = db_find_session_by_token($conn, $token);
            if (!$session) { throw new Exception('剛建立的 session 立即查不到'); }
            db_start_shift($conn, (int) $session['session_id'], (int) $staff['id']);
        } catch (Exception $e) {
            error_log('員工登入建立 session 失敗：' . $e->getMessage());
            respond(500, array('status' => 'failed', 'message' => '系統忙碌，請稍後再試'));
        }
        respond(200, array(
            'status' => 'success',
            'token' => $token,
            'merchantName' => $store['merchant_name'],
            'stores' => array(store_public_view($store)),
            'needStoreSelection' => false,
            'storeId' => (int) $store['id'],
            'storeName' => $store['name'],
            'staffName' => $staff['name'],
        ));
    }

    // ── 舊版相容路徑：客戶編號 ────────────────────────────────────
    staff_login_by_customer($conn, $customerCode, $staffCard, $staffCode, $pin, $deviceId, $storeId);
}

/** 舊版相容：客戶編號 + 卡/工號反推分店（新版 App 改用商店代號，見 staff_login）。 */
function staff_login_by_customer($conn, $customerCode, $staffCard, $staffCode, $pin, $deviceId, $storeId) {
    $dummyHash = '$2y$10$usesomesillystringforsalttoavoidtimingleakabcdefghijklmn';

    $merchant = db_find_merchant_by_code($conn, $customerCode);
    if (!$merchant || (int) $merchant['enabled'] !== 1) {
        // 客戶不存在也跑一次假比對，讓回應時間跟 PIN 錯誤一致
        password_verify($pin, $dummyHash);
        rl_record_failure($conn);
        respond(200, array('status' => 'failed', 'message' => '客戶編號、卡片／工號或 PIN 不正確'));
    }

    // 只在啟用中的分店裡找 —— 停用的分店不該能被登入
    $candidates = array();
    foreach (db_list_stores($conn, (int) $merchant['id']) as $st) {
        if ((int) $st['enabled'] !== 1) continue;
        if ($storeId > 0 && (int) $st['id'] !== $storeId) continue;
        $staff = $staffCard !== ''
            ? db_find_staff_by_card($conn, (int) $st['id'], $staffCard)
            : db_find_staff_by_code($conn, (int) $st['id'], $staffCode);
        if ($staff) {
            $candidates[] = array('store' => $st, 'staff' => $staff);
        }
    }

    // 用 PIN 過濾 —— 只有 PIN 對的分店才算數，藉此把「揭露分店」擋在驗證之後
    $matched = array();
    foreach ($candidates as $c) {
        if (password_verify($pin, $c['staff']['pin_hash'])) {
            $matched[] = $c;
        }
    }
    if (empty($matched)) {
        // 沒有任何候選時也要做一次比對，時間才跟「有候選但 PIN 錯」一致
        if (empty($candidates)) { password_verify($pin, $dummyHash); }
        rl_record_failure($conn);
        respond(200, array(
            'status' => 'failed',
            'message' => $staffCard !== '' ? '卡片或 PIN 不正確' : '工號或 PIN 不正確',
        ));
    }

    if (count($matched) > 1) {
        // 同一張卡/工號登記在多家分店：讓 App 選一家後帶 storeId 再呼叫一次
        $stores = array();
        foreach ($matched as $c) { $stores[] = store_public_view($c['store']); }
        respond(200, array(
            'status' => 'success',
            'needStoreSelection' => true,
            'merchantName' => $merchant['name'],
            'stores' => $stores,
        ));
    }

    // 唯一一筆：綁定這家分店並直接開班
    $store = $matched[0]['store'];
    $staff = $matched[0]['staff'];

    // 資產綁定：機器只能登入被派到的客戶的店（同商店代號路徑，這裡是舊版相容路徑）
    $device = db_find_device($conn, $deviceId);
    $dispatchedMerchant = ($device && $device['dispatched_merchant_id'] !== null)
        ? (int) $device['dispatched_merchant_id'] : 0;
    if ($dispatchedMerchant !== (int) $merchant['id']) {
        respond(200, array(
            'status' => 'failed',
            'message' => '這台機器尚未派給這家店所屬的客戶，請聯絡總部派工',
        ));
    }

    db_reset_pos_login_failures($conn, $deviceId);

    try {
        $token = db_create_merchant_session($conn, (int) $merchant['id'], (int) $store['id'], $deviceId);
        // 員工登入即開班 —— 帶機器上線的人就是這一班在收款的人
        $session = db_find_session_by_token($conn, $token);
        if (!$session) {
            throw new Exception('剛建立的 session 立即查不到');
        }
        db_start_shift($conn, (int) $session['session_id'], (int) $staff['id']);
    } catch (Exception $e) {
        error_log('員工登入建立 session 失敗：' . $e->getMessage());
        respond(500, array('status' => 'failed', 'message' => '系統忙碌，請稍後再試'));
    }

    respond(200, array(
        'status' => 'success',
        'token' => $token,
        'merchantName' => $merchant['name'],
        'stores' => array(store_public_view($store)),
        'needStoreSelection' => false,
        'storeId' => (int) $store['id'],
        'storeName' => $store['name'],
        'staffName' => $staff['name'],
    ));
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

$customerCode = isset($input['customerCode']) ? trim($input['customerCode']) : '';
$account = isset($input['account']) ? trim($input['account']) : '';
$password = isset($input['password']) ? $input['password'] : '';
$deviceId = isset($input['deviceId']) ? substr((string) $input['deviceId'], 0, 64) : null;
// 客戶有多家分店時，App 第二次呼叫會帶上選好的分店
$storeId = isset($input['storeId']) ? (int) $input['storeId'] : 0;

/*
 * ── 兩種登入方式 ──────────────────────────────────────────────
 *
 *   商店密碼：客戶編號 + 帳號 + 密碼。裝機時由店主操作，綁定整台機器。
 *   員工登入：客戶編號 + 感應卡／工號 + PIN。讓一般員工也能把機器開起來，
 *            並在同一步直接開班（見下方 staff_login）。
 *
 * 用「有沒有帶 PIN 且帶了卡片或工號」判斷是不是員工登入 —— 兩條路的欄位
 * 不重疊，不需要額外的模式參數。
 */
// 商店代號一律大寫比對（收銀機允許輸入小寫，這裡與 App 都會轉大寫）
$storeCode = isset($input['storeCode']) ? strtoupper(trim((string) $input['storeCode'])) : '';
$staffCard = isset($input['staffCard']) ? strtoupper(trim((string) $input['staffCard'])) : '';
$staffCode = isset($input['staffCode']) ? trim((string) $input['staffCode']) : '';
$pin = isset($input['pin']) ? (string) $input['pin'] : '';
$isStaffLogin = ($pin !== '' && ($staffCard !== '' || $staffCode !== ''));

if (!$isStaffLogin && ($customerCode === '' || $account === '' || $password === '')) {
    respond(400, array('status' => 'failed', 'message' => '請輸入客戶編號、帳號與密碼'));
}
// 員工登入要嘛帶商店代號（新版），要嘛帶客戶編號（舊版相容）
if ($isStaffLogin && $storeCode === '' && $customerCode === '') {
    respond(400, array('status' => 'failed', 'message' => '請輸入商店代號'));
}

try {
    $conn = db_connect();
    db_create_merchants_table_if_not_exists($conn);
    db_create_pos_locks_table_if_not_exists($conn);
    db_create_store_staff_table_if_not_exists($conn);
} catch (Exception $e) {
    error_log('登入：資料庫連線失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統忙碌，請稍後再試'));
}

/*
 * 設備鎖定檢查要在驗證帳密**之前**。
 *
 * 放在之後的話，被鎖住的設備仍然可以靠回應時間差異判斷密碼對不對 ——
 * 那等於鎖了個寂寞。員工登入一樣要看鎖定，否則就成了繞過鎖定的後門。
 */
$lock = db_get_pos_lock($conn, $deviceId);
if ($lock && (int) $lock['locked'] === 1) {
    respond(200, array(
        'status' => 'failed',
        'locked' => true,
        'message' => '這台收銀機因連續登入失敗已被鎖定，請聯絡管理者解鎖',
    ));
}

// 登入是暴力破解的主要目標，速率限制不能省
$rateLimitMessage = rl_check($conn);
if ($rateLimitMessage !== null) {
    respond(429, array('status' => 'failed', 'message' => $rateLimitMessage));
}
rl_record_attempt($conn);

if ($isStaffLogin) {
    staff_login($conn, $storeCode, $customerCode, $staffCard, $staffCode, $pin, $deviceId, $storeId);
    // staff_login 一定會 respond 後結束，不會回到這裡
}

$merchant = db_find_merchant_by_login($conn, $customerCode, $account);

/*
 * 找不到客戶時仍跑一次雜湊比對，讓回應時間跟密碼錯誤的情況一致。
 * 否則從回應快慢就能推出哪些編號存在（時序攻擊）。
 */
$dummyHash = '$2y$10$usesomesillystringforsalttoavoidtimingleakabcdefghijklmn';
$passwordOk = password_verify($password, $merchant ? $merchant['password_hash'] : $dummyHash);

if (!$merchant || !$passwordOk || (int) $merchant['enabled'] !== 1) {
    rl_record_failure($conn);

    // 記錄失敗並判斷是否要鎖定。merchant_id 記得下才能讓管理者在自己的
    // 後台看到「我旗下有台設備被鎖住」。
    $result = db_record_pos_login_failure(
        $conn, $deviceId,
        $merchant ? (int) $merchant['id'] : null,
        $customerCode, $account
    );

    if (!empty($result['locked'])) {
        respond(200, array(
            'status' => 'failed',
            'locked' => true,
            'message' => '連續登入失敗次數過多，這台收銀機已被鎖定，請聯絡管理者解鎖',
        ));
    }

    // 告知剩餘次數，讓真的打錯字的店員知道還有幾次機會。
    // 這不算洩漏 —— 攻擊者自己數也知道試了幾次。
    $msg = '客戶編號、帳號或密碼不正確';
    if (isset($result['remaining']) && $result['remaining'] !== null && $result['remaining'] <= 3) {
        $msg .= "（再失敗 {$result['remaining']} 次此設備將被鎖定）";
    }
    respond(200, array('status' => 'failed', 'message' => $msg, 'remaining' => $result['remaining']));
}

// 登入成功，把連續失敗次數歸零
db_reset_pos_login_failures($conn, $deviceId);

// 只列出啟用中的分店 —— 停用的分店不該出現在選單裡
$allStores = db_list_stores($conn, (int) $merchant['id']);
$stores = array();
foreach ($allStores as $st) {
    if ((int) $st['enabled'] === 1) {
        $stores[] = array(
            'id' => (int) $st['id'],
            'name' => $st['name'],
            // 只給末四碼讓店員確認選對店，完整 MerID 收銀機不需要知道
            'merIdMasked' => strlen($st['mer_id']) > 4
                ? str_repeat('*', strlen($st['mer_id']) - 4) . substr($st['mer_id'], -4)
                : $st['mer_id'],
        );
    }
}

if (!$stores) {
    respond(200, array(
        'status' => 'failed',
        'message' => '這個帳號底下沒有可用的商店，請聯絡系統管理者',
    ));
}

/*
 * 分店的決定方式：
 *   只有一家 → 自動綁定，店員不必多按一次
 *   多家且 App 已指定 → 驗證那家確實屬於這個客戶後綁定
 *   多家且未指定 → 回傳清單，App 顯示選擇畫面後再呼叫一次
 *
 * 驗證歸屬很重要：不檢查的話，帶入別家客戶的 storeId 就能用自己的帳密
 * 以別人的商店代號收款。
 */
$boundStoreId = null;
if (count($stores) === 1) {
    $boundStoreId = $stores[0]['id'];
} elseif ($storeId > 0) {
    foreach ($stores as $st) {
        if ($st['id'] === $storeId) {
            $boundStoreId = $storeId;
            break;
        }
    }
    if ($boundStoreId === null) {
        respond(200, array('status' => 'failed', 'message' => '選擇的商店不存在或已停用'));
    }
}

try {
    $token = db_create_merchant_session($conn, (int) $merchant['id'], $boundStoreId, $deviceId);
} catch (Exception $e) {
    error_log('建立登入 token 失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統忙碌，請稍後再試'));
}

$selected = null;
foreach ($stores as $st) {
    if ($st['id'] === $boundStoreId) {
        $selected = $st;
        break;
    }
}

respond(200, array(
    'status' => 'success',
    'token' => $token,
    'merchantName' => $merchant['name'],
    'stores' => $stores,
    // needStoreSelection = true 代表 App 要顯示分店選擇畫面，
    // 選好後帶著 storeId 重新呼叫一次登入
    'needStoreSelection' => $boundStoreId === null,
    'storeId' => $boundStoreId,
    'storeName' => $selected ? $selected['name'] : null,
));
