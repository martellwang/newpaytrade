<?php
/**
 * 退款 QR 的簽章工具。
 *
 * 收執聯（客人那聯）下方印一個 QR，收銀機掃了就能快速退這筆。為了不讓人偽造
 * 隨便一張 QR 來觸發退款，QR 內容用 HMAC-SHA256 簽章：
 *
 *   token = "NPRF1." + base64url(payload) + "." + base64url(HMAC 前 16 bytes)
 *   payload = {"m": merTradeNo, "s": storeId, "t": 產生時間}
 *
 * - 「只有 App 看得懂的規矩」= NPRF1 前綴 + 這個格式，App 靠前綴辨識這是退款 QR。
 * - 「由該商店產出」= 簽章綁了 storeId，且密鑰只在主機；退款端點還會再比對
 *   「掃碼的收銀機登入的店」與「訂單所屬的店」是否都等於 token 裡的 storeId。
 *
 * 密鑰不另外設定，從既有的 BACKEND_API_KEY 派生一把專用 key（域分離）。
 */

require_once __DIR__ . '/config.php';

function refund_qr_key() {
    return hash('sha256', BACKEND_API_KEY . '|refund-qr-v1', true);
}

function rt_b64url_encode($bin) {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function rt_b64url_decode($str) {
    return base64_decode(strtr($str, '-_', '+/'));
}

/** 產生退款 token（印進收執聯的 QR）。 */
function refund_token_make($merTradeNo, $storeId) {
    $payload = json_encode(
        array('m' => (string) $merTradeNo, 's' => (int) $storeId, 't' => time()),
        JSON_UNESCAPED_SLASHES
    );
    $p = rt_b64url_encode($payload);
    $sig = substr(hash_hmac('sha256', $p, refund_qr_key(), true), 0, 16);
    return 'NPRF1.' . $p . '.' . rt_b64url_encode($sig);
}

/**
 * 驗證退款 token。
 * @return array ok=true 時含 merTradeNo / storeId / issuedAt；否則 ok=false + error
 */
function refund_token_verify($token) {
    if (!is_string($token) || strpos($token, 'NPRF1.') !== 0) {
        return array('ok' => false, 'error' => 'QR 格式不符');
    }
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return array('ok' => false, 'error' => 'QR 格式不符');
    }
    $p = $parts[1];
    $s = $parts[2];
    $expect = rt_b64url_encode(substr(hash_hmac('sha256', $p, refund_qr_key(), true), 0, 16));
    // hash_equals 防時序攻擊
    if (!hash_equals($expect, $s)) {
        return array('ok' => false, 'error' => 'QR 簽章不符（可能不是本系統產生）');
    }
    $payload = json_decode(rt_b64url_decode($p), true);
    if (!is_array($payload) || !isset($payload['m']) || !isset($payload['s'])) {
        return array('ok' => false, 'error' => 'QR 內容不完整');
    }
    return array(
        'ok' => true,
        'merTradeNo' => (string) $payload['m'],
        'storeId' => (int) $payload['s'],
        'issuedAt' => isset($payload['t']) ? (int) $payload['t'] : 0,
    );
}
