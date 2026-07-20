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

if ($customerCode === '' || $account === '' || $password === '') {
    respond(400, array('status' => 'failed', 'message' => '請輸入客戶編號、帳號與密碼'));
}

try {
    $conn = db_connect();
    db_create_merchants_table_if_not_exists($conn);
    db_create_pos_locks_table_if_not_exists($conn);
} catch (Exception $e) {
    error_log('登入：資料庫連線失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統忙碌，請稍後再試'));
}

/*
 * 設備鎖定檢查要在驗證帳密**之前**。
 *
 * 放在之後的話，被鎖住的設備仍然可以靠回應時間差異判斷密碼對不對 ——
 * 那等於鎖了個寂寞。
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
