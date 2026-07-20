<?php
/**
 * PAYUNi 統一金流的上游驅動。
 *
 * 這裡放的是「PAYUNi 特有的知識」—— 別家上游的規則不一樣，各自寫在
 * 自己的驅動檔裡，呼叫端不需要知道差異。
 *
 * PAYUNi 的幾個特點（換上游時要重新查，不要假設別家一樣）：
 *   - 加密：AES-256-GCM，EncryptInfo = hex(base64(密文) + ":::" + base64(tag))
 *   - 每支 API 的 Version 都不同：授權 1.3、請退款 1.0、查詢 2.0
 *   - 交易類 API 都要帶 IsPlatForm=1，那是「以代理商身分串接」的宣告
 *   - 查詢商店狀態這支的回應**最外層沒有 Status**，狀態碼在解密後的內容裡
 */

require_once __DIR__ . '/../payuni_crypto.php';

/**
 * 這家上游支援什麼。
 *
 * 分期期數這裡列的是「PAYUNi 這個平台支援的期數」，不是「這個商店開通的
 * 期數」—— 後者要靠 payuni_merchant_status() 實際查詢。兩者不要混淆：
 * 平台支援 30 期不代表你家店能刷 30 期。
 */
function payuni_capabilities($provider) {
    return array(
        'installments' => array(1, 3, 6, 9, 12, 18, 24, 30),
        'supports_installment' => true,
        'supports_refund' => true,
        'supports_partial_refund' => true,   // 但分期交易只能全額退，見 refund.php
        'supports_void' => true,             // 未請款可取消（CloseType -1）
        'supports_merchant_status' => true,
        'blank_cvc' => true,                 // 感應讀卡沒有 CVV，靠 IsPlatForm=1
    );
}

/**
 * 查詢商店開通了哪些支付工具與分期期數。
 *
 * @return array array('ok' => bool, 'detail' => 解密後的欄位, 'message' => 失敗原因)
 */
function payuni_merchant_status($provider) {
    $agentId = provider_credential($provider, 'agent_id');
    $merId = provider_credential($provider, 'mer_id');
    $hashKey = provider_credential($provider, 'hash_key');
    $hashIv = provider_credential($provider, 'hash_iv');
    $url = provider_endpoint($provider, 'merchant_status');

    if ($agentId === '' || $merId === '' || $hashKey === '' || $hashIv === '') {
        return array('ok' => false, 'message' => '尚未設定 PAYUNI_AGENT_ID，無法查詢商店開通狀態');
    }

    try {
        $encryptInfo = payuni_encrypt_trade_info(array('MerID' => $merId), $hashKey, $hashIv);
        $hashInfo = payuni_generate_hash($encryptInfo, $hashKey, $hashIv);
    } catch (Exception $e) {
        error_log('商店狀態加密失敗：' . $e->getMessage());
        return array('ok' => false, 'message' => '系統錯誤');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(array(
            'AgentID' => $agentId,
            'Version' => '1.0',
            'EncryptInfo' => $encryptInfo,
            'HashInfo' => $hashInfo,
        )),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded',
            'user-agent: payuni',
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ));
    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        error_log('呼叫商店狀態 API 失敗：' . $curlError);
        return array('ok' => false, 'message' => '金流服務暫時無法回應');
    }

    $result = json_decode($responseBody, true);

    /*
     * ⚠️ 這支 API 的回應**最外層沒有 Status 欄位** —— 跟授權／退款／查詢
     *    那三支不一樣。成功時最外層只有 AgentID / MerID / Version /
     *    EncryptInfo / HashInfo，狀態碼在解密後的內容裡。
     *    照其他 API 的慣例檢查外層 Status 會把成功誤判成失敗（實際踩過）。
     */
    if (empty($result['EncryptInfo'])) {
        error_log('商店狀態回應沒有 EncryptInfo：' . $responseBody);
        return array('ok' => false, 'message' => '查詢商店狀態失敗');
    }

    try {
        $detail = payuni_decrypt_trade_info($result['EncryptInfo'], $hashKey, $hashIv);
    } catch (Exception $e) {
        error_log('商店狀態解密失敗：' . $e->getMessage());
        return array('ok' => false, 'message' => '系統錯誤');
    }

    if (!isset($detail['Status']) || $detail['Status'] !== 'SUCCESS') {
        error_log('商店狀態查詢未成功：' . json_encode($detail, JSON_UNESCAPED_UNICODE));
        $message = isset($detail['Message']) && $detail['Message'] !== ''
            ? $detail['Message'] : '查詢商店狀態失敗';
        return array('ok' => false, 'message' => $message);
    }

    return array('ok' => true, 'detail' => $detail);
}

/**
 * 把 PAYUNi 的回應欄位轉成各家上游共用的格式。
 *
 * 這層轉換是多上游架構的關鍵：App 和管理介面只認這個格式，換上游時
 * 只要新驅動也吐出同樣的形狀，前端一行都不用改。
 */
function payuni_normalize_merchant_status($detail, $merId) {
    $instFields = array(
        'Inst3' => 3, 'Inst6' => 6, 'Inst9' => 9, 'Inst12' => 12,
        'Inst18' => 18, 'Inst24' => 24, 'Inst30' => 30,
    );
    $on = function ($v) { return (string) $v === '1'; };

    $installments = array();
    foreach ($instFields as $field => $term) {
        $installments[(string) $term] = isset($detail[$field]) && $on($detail[$field]);
    }
    // 一次付清對應 CREDIT（國內卡）
    $installments['1'] = isset($detail['CREDIT']) && $on($detail['CREDIT']);

    $statusMap = array(
        '1' => '啟用', '2' => '關閉', '8' => '拒絕', '9' => '審核中',
        '91' => '待補資料', '92' => '審核失敗', '99' => '停用／停權',
    );
    $merStatus = isset($detail['MerStatus']) ? (string) $detail['MerStatus'] : '';

    return array(
        'status' => 'success',
        'merId' => isset($detail['MerID']) ? $detail['MerID'] : $merId,
        'merStatus' => $merStatus !== '' ? $merStatus : null,
        'merStatusText' => isset($statusMap[$merStatus]) ? $statusMap[$merStatus] : null,
        'available' => $installments,
        'methods' => array(
            'credit' => isset($detail['CREDIT']) && $on($detail['CREDIT']),
            'foreignCard' => isset($detail['ForeignCard']) && $on($detail['ForeignCard']),
            'unionPay' => isset($detail['UnionPay']) && $on($detail['UnionPay']),
            'applePay' => isset($detail['ApplePay']) && $on($detail['ApplePay']),
            'googlePay' => isset($detail['GooglePay']) && $on($detail['GooglePay']),
            'samsungPay' => isset($detail['SamsungPay']) && $on($detail['SamsungPay']),
            'jkoPay' => isset($detail['JKoPay']) && $on($detail['JKoPay']),
            'cvs' => isset($detail['CVS']) && $on($detail['CVS']),
            'atm' => isset($detail['ATM']) && $on($detail['ATM']),
        ),
    );
}
