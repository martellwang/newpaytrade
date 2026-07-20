<?php
/**
 * 上游金流機構的註冊表。
 *
 * 這套系統是 TMS（終端管理系統），未來會接不只一家上游 —— 可能是別的
 * 金流服務商、收單銀行，或電子支付機構。每一家的串接參數、加密方式、
 * 欄位名稱、支援的功能都不一樣，不能繼續散落在一堆 PAYUNI_* 常數裡。
 *
 * === 這個檔案負責什麼 ===
 * 只負責「設定的存放與取用」，不負責呼叫上游。
 * 各家怎麼加密、怎麼組欄位，寫在 providers/<名稱>.php 裡。
 *
 * === 為什麼保留舊常數的相容 ===
 * config.php 是使用者自己維護、不進版控的檔案，主機上那份現在正在跑
 * 真實交易。如果這裡改成只認新格式，部署當下所有交易會立刻中斷。
 * 所以：新格式優先，沒有就從舊常數組出來，兩種寫法都能動。
 * 等到真的要接第二家上游時，再請使用者改成新格式即可。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/*
 * ══ 金鑰的加密儲存 ═══════════════════════════════════════════════
 *
 * 上游的串接金鑰存在資料庫（讓管理介面可以線上新增／編輯），但**一定要
 * 加密**：資料庫備份、SQL 注入、管理帳號外洩，任何一個都會把明文金鑰
 * 一次全部交出去。
 *
 * 加密金鑰本身**必須放在 config.php，不能放資料庫** —— 鑰匙跟鎖放在
 * 同一個抽屜等於沒鎖。config.php 不進版控、也不在 web 可讀範圍。
 *
 * 產生一把新的：
 *   php -r "echo bin2hex(random_bytes(32));"
 * 填進 config.php：
 *   define('PROVIDER_SECRET_KEY', '<那 64 個十六進位字元>');
 *
 * ⚠️ 這把金鑰換掉的話，資料庫裡已加密的上游設定就解不開了，要重新輸入。
 */

/** 加密金鑰是否已設定。沒設定就不能把金鑰存進資料庫。 */
function provider_secret_key_ready() {
    return defined('PROVIDER_SECRET_KEY') && strlen(PROVIDER_SECRET_KEY) >= 32;
}

function provider_secret_encrypt($plain) {
    if (!provider_secret_key_ready()) {
        throw new Exception('尚未設定 PROVIDER_SECRET_KEY，無法安全儲存金鑰');
    }
    $key = hash('sha256', PROVIDER_SECRET_KEY, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new Exception('加密失敗');
    }
    // iv + tag + 密文，一起 base64 方便存進 TEXT 欄位
    return base64_encode($iv . $tag . $cipher);
}

function provider_secret_decrypt($encoded) {
    if (!provider_secret_key_ready() || $encoded === '' || $encoded === null) {
        return null;
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $key = hash('sha256', PROVIDER_SECRET_KEY, true);
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

/**
 * 取得所有已設定的上游。
 *
 * @return array 以代號為鍵，例如 array('payuni' => array(...))
 */
function provider_all() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    /*
     * 來源優先序：資料庫 → config.php 的 $PROVIDERS → 舊的 PAYUNI_* 常數。
     *
     * 資料庫優先是因為管理介面改的是那裡；但資料庫查不到（還沒建表、
     * 連不上、或根本還沒設定過）時一定要能退回檔案設定 —— **不能因為
     * 上游設定讀不到就讓所有交易停擺**。
     */
    $fromDb = provider_all_from_db();
    if (!empty($fromDb)) {
        $cache = $fromDb;
        return $cache;
    }

    // config.php 裡定義 $PROVIDERS 陣列
    if (isset($GLOBALS['PROVIDERS']) && is_array($GLOBALS['PROVIDERS'])) {
        $cache = $GLOBALS['PROVIDERS'];
        return $cache;
    }

    // 舊格式：從既有的 PAYUNI_* 常數組出來。
    // 這是為了讓現有主機不改 config.php 也能繼續運作。
    $cache = array(
        'payuni' => array(
            'label' => 'PAYUNi 統一金流',
            'driver' => 'payuni',
            'enabled' => true,
            'credentials' => array(
                'mer_id' => defined('PAYUNI_MER_ID') ? PAYUNI_MER_ID : '',
                'hash_key' => defined('PAYUNI_HASH_KEY') ? PAYUNI_HASH_KEY : '',
                'hash_iv' => defined('PAYUNI_HASH_IV') ? PAYUNI_HASH_IV : '',
                // 代理商代號。PAYUNi 的商店金鑰本身就是代理商金鑰，
                // 所以交易 API 才要帶 IsPlatForm=1 宣告身分。
                'agent_id' => defined('PAYUNI_AGENT_ID') ? PAYUNI_AGENT_ID : '',
            ),
            'endpoints' => array(
                'authorize' => defined('PAYUNI_DIRECT_AUTH_URL') ? PAYUNI_DIRECT_AUTH_URL : '',
                'close' => defined('PAYUNI_REFUND_URL') ? PAYUNI_REFUND_URL : '',
                'query' => defined('PAYUNI_QUERY_URL') ? PAYUNI_QUERY_URL : '',
                'merchant_status' => defined('PAYUNI_MERCHANT_STATUS_URL')
                    ? PAYUNI_MERCHANT_STATUS_URL
                    : 'https://api.payuni.com.tw/api/agent/search_merchant_status',
            ),
        ),
    );
    return $cache;
}

/**
 * 從資料庫讀出所有上游設定。
 *
 * 任何一步失敗都回空陣列讓呼叫端退回檔案設定 —— 這條路徑在每次交易時
 * 都會經過，不能因為它出問題就中斷收款。
 */
function provider_all_from_db() {
    try {
        $conn = db_connect();
        db_create_providers_table_if_not_exists($conn);
        $rows = db_list_providers($conn);
    } catch (Exception $e) {
        error_log('讀取上游設定失敗，改用檔案設定：' . $e->getMessage());
        return array();
    }

    $out = array();
    foreach ($rows as $r) {
        $credentials = array();
        $decrypted = provider_secret_decrypt($r['credentials_enc']);
        if ($decrypted !== null) {
            $parsed = json_decode($decrypted, true);
            if (is_array($parsed)) {
                $credentials = $parsed;
            }
        } elseif (!empty($r['credentials_enc'])) {
            // 有密文但解不開 —— 多半是 PROVIDER_SECRET_KEY 被換掉了。
            // 這種情況要讓人看得到，不然只會得到「交易莫名失敗」。
            error_log("上游 {$r['name']} 的金鑰無法解密，請確認 PROVIDER_SECRET_KEY 是否變更");
        }
        $endpoints = json_decode($r['endpoints'], true);
        $out[$r['name']] = array(
            'label' => $r['label'],
            'driver' => $r['driver'],
            'enabled' => (int) $r['enabled'] === 1,
            'credentials' => $credentials,
            'endpoints' => is_array($endpoints) ? $endpoints : array(),
            'from_db' => true,
        );
    }
    return $out;
}

/**
 * 預設使用哪一家上游。
 *
 * 現階段只有一家。日後要依收銀機、依商店或依卡別分流時，改這裡的邏輯，
 * 呼叫端不用動 —— 這正是把它包成函式而不是常數的原因。
 */
function provider_default_name() {
    if (defined('DEFAULT_PROVIDER') && DEFAULT_PROVIDER !== '') {
        return DEFAULT_PROVIDER;
    }
    $all = provider_all();
    $names = array_keys($all);
    return isset($names[0]) ? $names[0] : 'payuni';
}

/**
 * 取得某一家上游的設定。
 *
 * @param string|null $name 留空則取預設那家
 * @return array|null 找不到或未啟用時回 null
 */
function provider_get($name = null) {
    $all = provider_all();
    if ($name === null || $name === '') {
        $name = provider_default_name();
    }
    if (!isset($all[$name])) {
        return null;
    }
    $p = $all[$name];
    if (isset($p['enabled']) && !$p['enabled']) {
        return null;
    }
    $p['name'] = $name;
    return $p;
}

/** 讀取某家上游的單一憑證欄位，缺少時回空字串而不是噴錯 */
function provider_credential($provider, $key) {
    return isset($provider['credentials'][$key]) ? $provider['credentials'][$key] : '';
}

/** 讀取某家上游的端點網址 */
function provider_endpoint($provider, $key) {
    return isset($provider['endpoints'][$key]) ? $provider['endpoints'][$key] : '';
}

/**
 * 載入某家上游的驅動程式（providers/<driver>.php）。
 *
 * 驅動程式要提供的函式，以 <driver>_ 開頭：
 *   <driver>_capabilities($provider)      回報支援哪些功能、分期期數等
 *   <driver>_merchant_status($provider)   查詢商店開通狀態
 *   <driver>_authorize($provider, $req)   幕後授權
 *   <driver>_close($provider, $req)       請款／退款／取消
 *   <driver>_query($provider, $req)       交易查詢
 *
 * 目前只有 payuni 一家，而且授權／退款／查詢仍由既有檔案直接處理 ——
 * 那三支是每天在跑真錢的路徑，等真的要接第二家時再一起搬，不要為了
 * 假想的第二家先去動它們。
 *
 * @return bool 載入成功與否
 */
function provider_load_driver($provider) {
    $driver = isset($provider['driver']) ? $provider['driver'] : '';
    if ($driver === '' || !preg_match('/^[a-z0-9_]+$/', $driver)) {
        return false;
    }
    $file = __DIR__ . '/providers/' . $driver . '.php';
    if (!is_file($file)) {
        return false;
    }
    require_once $file;
    return true;
}
