<?php
/**
 * PAYUNi 交易查詢的共用函式。
 *
 * 為什麼獨立出來：退款前需要先知道這筆交易「請款了沒」——未請款要走
 * 取消請款（CloseType=-1），已請款才是退款（CloseType=2）。搞錯的話
 * PAYUNi 會回「關帳狀態不符合」，操作者看不懂為什麼。
 *
 * ⚠️ query.php 目前有一份幾乎相同的邏輯，沒有一併改用這裡的函式，
 *    是因為那支是已在正式環境驗證過的金流端點，重構它的風險不值得。
 *    日後若要合併，改完務必重新跑一次實機驗證。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payuni_crypto.php';

/**
 * 向 PAYUNi 查詢單筆交易的即時狀態。
 *
 * @param string $merTradeNo 商店訂單編號
 * @param string $merId      要用哪個商店代號查。多商店情境下**必須帶** ——
 *                           拿 A 商店的代號去查 B 商店的交易只會查無資料。
 *                           留空時退回 config 的預設值（單商店的舊行為）。
 * @return array 成功：array('ok' => true, 'record' => 該筆交易的欄位陣列)
 *               失敗：array('ok' => false, 'message' => 失敗原因)
 */
function payuni_fetch_trade_record($merTradeNo, $merId = '') {
    if ($merId === '' || $merId === null) {
        $merId = PAYUNI_MER_ID;
    }
    $encryptInfoParams = array(
        'MerID' => $merId,
        'MerTradeNo' => $merTradeNo,
        'Timestamp' => (string) time(),
    );

    try {
        $encryptInfo = payuni_encrypt_trade_info($encryptInfoParams, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
        $hashInfo = payuni_generate_hash($encryptInfo, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
    } catch (Exception $e) {
        error_log('查詢加密失敗（退款前置查詢）：' . $e->getMessage());
        return array('ok' => false, 'message' => '系統錯誤，請稍後再試');
    }

    $postFields = http_build_query(array(
        'MerID' => $merId,
        'Version' => '2.0', // 查詢是 2.0，跟授權(1.3)、退款(1.0)都不同
        'EncryptInfo' => $encryptInfo,
        'HashInfo' => $hashInfo,
        'IsPlatForm' => '1', // 平台/代理商帳號，三支 API 都要帶
    ));

    $ch = curl_init(PAYUNI_QUERY_URL);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded',
            'user-agent: payuni',
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ));
    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        error_log('退款前置查詢呼叫失敗：' . $curlError);
        return array('ok' => false, 'message' => '金流服務暫時無法回應');
    }

    $result = json_decode($responseBody, true);
    if (!isset($result['Status']) || $result['Status'] !== 'SUCCESS') {
        error_log('退款前置查詢回應非 SUCCESS：' . $responseBody);
        return array('ok' => false, 'message' => '無法查詢交易狀態');
    }

    try {
        $detail = payuni_decrypt_trade_info($result['EncryptInfo'], PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
    } catch (Exception $e) {
        error_log('退款前置查詢解密失敗：' . $e->getMessage());
        return array('ok' => false, 'message' => '系統錯誤，請稍後再試');
    }

    // 查詢結果包在 Result 陣列裡（同一個端點也支援多筆查詢），不是最外層
    if (empty($detail['Result']) || !is_array($detail['Result'])) {
        return array('ok' => false, 'message' => '查無此交易資料');
    }

    return array('ok' => true, 'record' => $detail['Result'][0]);
}
