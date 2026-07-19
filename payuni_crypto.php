<?php
/**
 * PAYUNi 統一金流 加解密工具（傳統程序式寫法，不用 class）
 *
 * 依據官方「資料加解密」文件的 Java 範例核對過，加密方式是
 * AES-256-GCM（HashKey 32 碼當 AES key，HashIV 16 碼當 GCM IV）。
 *
 * 1. 把交易參數組成 key1=value1&key2=value2 格式的字串（明文）
 * 2. AES-256-GCM 加密，得到密文與 128-bit 的 authTag
 * 3. base64(密文) + ":::" + base64(authTag) 這串文字轉成 UTF-8 bytes
 *    後，再做 hex 編碼 -> EncryptInfo
 * 4. 對 "{HashKey}{EncryptInfo}{HashIV}"（直接字串相接）做 SHA256
 *    -> HashInfo（大寫）
 */

/** 檢查 HashKey/HashIV 長度是否正確，不對就直接中止並回錯誤 */
function payuni_assert_keys_valid($hashKey, $hashIV) {
    if (strlen($hashKey) !== 32 || strlen($hashIV) !== 16) {
        throw new Exception('HashKey 必須是 32 碼，HashIV 必須是 16 碼');
    }
}

/**
 * 將關聯陣列依「加入順序」組成 querystring 並做 AES-256-GCM 加密。
 * $params 請依官方文件規定的欄位順序放入（PHP 的關聯陣列本身就會
 * 保留 insertion order，不用額外排序）。
 */
function payuni_encrypt_trade_info($params, $hashKey, $hashIV) {
    payuni_assert_keys_valid($hashKey, $hashIV);

    $pairs = array();
    foreach ($params as $key => $value) {
        $pairs[] = $key . '=' . rawurlencode((string) $value);
    }
    $qs = implode('&', $pairs);

    $tag = '';
    $ciphertext = openssl_encrypt($qs, 'aes-256-gcm', $hashKey, OPENSSL_RAW_DATA, $hashIV, $tag, '', 16);
    if ($ciphertext === false) {
        throw new Exception('EncryptInfo 加密失敗');
    }

    $finalString = base64_encode($ciphertext) . ':::' . base64_encode($tag);
    return bin2hex($finalString);
}

/** 產生 HashInfo 簽章，用於送出交易時附上，讓 PAYUNi 驗證資料未被竄改 */
function payuni_generate_hash($encryptInfoHex, $hashKey, $hashIV) {
    payuni_assert_keys_valid($hashKey, $hashIV);
    $raw = $hashKey . $encryptInfoHex . $hashIV;
    return strtoupper(hash('sha256', $raw));
}

/**
 * 解密 PAYUNi 回傳的 EncryptInfo，回傳關聯陣列（用於同步回應、
 * 背景通知 Notify）。
 */
function payuni_decrypt_trade_info($encryptInfoHex, $hashKey, $hashIV) {
    payuni_assert_keys_valid($hashKey, $hashIV);

    $combined = hex2bin($encryptInfoHex);
    if ($combined === false) {
        throw new Exception('EncryptInfo 不是合法的 hex 字串');
    }

    $parts = explode(':::', $combined, 2);
    if (count($parts) !== 2) {
        throw new Exception('EncryptInfo 格式不正確，缺少 ":::" 分隔的密文與 authTag');
    }

    $ciphertext = base64_decode($parts[0]);
    $tag = base64_decode($parts[1]);

    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $hashKey, OPENSSL_RAW_DATA, $hashIV, $tag);
    if ($plain === false) {
        throw new Exception('EncryptInfo 解密失敗（authTag 驗證不過或金鑰不對）');
    }

    $result = array();
    parse_str($plain, $result);
    return $result;
}

/** 驗證收到的 HashInfo 是否與自己重新計算的一致，避免資料被竄改或偽造請求 */
function payuni_verify_hash($encryptInfoHex, $hashInfoFromPayuni, $hashKey, $hashIV) {
    $expected = payuni_generate_hash($encryptInfoHex, $hashKey, $hashIV);
    return $expected === strtoupper($hashInfoFromPayuni);
}
