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

    // 新格式：config.php 裡定義 $PROVIDERS 陣列
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
