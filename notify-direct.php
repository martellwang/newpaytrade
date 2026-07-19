<?php
/**
 * 逾時後的背景通知（NotifyURL）。文件說明：僅於平台點選確認發送，
 * 以及收到回覆為 UNKNOWN 時「後續通知交易結果使用」，是逾時情境的
 * 補救機制，不是每一筆都會呼叫。
 *
 * 這支端點是 PAYUNi 伺服器呼叫的，不是 App 呼叫的，所以不檢查
 * X-API-Key——真偽是靠 AES-GCM 的 authTag 驗證（解密失敗就代表資料
 * 被竄改或金鑰不對）。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payuni_crypto.php';
require_once __DIR__ . '/db.php';

$encryptInfo = isset($_POST['EncryptInfo']) ? $_POST['EncryptInfo'] : '';

try {
    $detail = payuni_decrypt_trade_info($encryptInfo, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
    $merTradeNo = isset($detail['MerTradeNo']) ? $detail['MerTradeNo'] : '';
    $status = (isset($detail['Status']) && $detail['Status'] === 'SUCCESS'
        && isset($detail['TradeStatus']) && $detail['TradeStatus'] === '1') ? 'success' : 'failed';

    error_log('收到幕後授權背景通知 ' . $merTradeNo . ' ' . (isset($detail['Status']) ? $detail['Status'] : '') . ' ' . (isset($detail['TradeStatus']) ? $detail['TradeStatus'] : ''));

    if ($merTradeNo !== '') {
        $conn = db_connect();
        db_update_order_result(
            $conn,
            $merTradeNo,
            $status,
            isset($detail['TradeNo']) ? $detail['TradeNo'] : null,
            isset($detail['AuthCode']) ? $detail['AuthCode'] : null,
            isset($detail['Card4No']) ? $detail['Card4No'] : null,
            isset($detail['Message']) ? $detail['Message'] : null,
            json_encode($detail, JSON_UNESCAPED_UNICODE)
        );
    }

    echo '1|OK';
} catch (Exception $e) {
    error_log('處理幕後授權背景通知失敗：' . $e->getMessage());
    http_response_code(500);
    echo '0|ERR';
}
