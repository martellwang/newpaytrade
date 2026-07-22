<?php
/**
 * 收銀機拉取「列印簽單範本」+ 本店 logo。
 *
 * 範本（行內容、字級、粗體、對齊）由總後台「列印範本」統一設定；logo 由各
 * 商店在客戶後台上傳，所以要用 POS token 解析出是哪一家店，才知道帶哪張 logo。
 * 收銀機列印時把每行的 {{參數}} 換成該筆交易的值。
 *
 * 需要 API Key + 有效的登入 token。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pos_auth.php';

header('Content-Type: application/json; charset=utf-8');

function respond($code, $body) {
    http_response_code($code);
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
    db_create_app_settings_table_if_not_exists($conn);
    $lines = db_get_receipt_lines($conn);
    $storeId = $identity['storeId'] ? (int) $identity['storeId'] : 0;
    $logo = $storeId ? db_get_store_logo($conn, $storeId) : null;
    $printMerchantCopy = $storeId ? db_get_store_print_merchant_copy($conn, $storeId) : true;
    $printRefundQr = $storeId ? db_get_store_print_refund_qr($conn, $storeId) : true;
    // 掃碼收款是否列印簽單。與刷卡分開，預設關閉。
    $printScanPay = $storeId ? db_get_store_print_scan_pay($conn, $storeId) : false;
    // 存根聯要加簽名欄的金額門檻（含）。可由 app_settings 調整，預設 3000。
    $signatureThreshold = (int) db_get_setting($conn, 'receipt_signature_threshold', 3000);
} catch (Exception $e) {
    error_log('pos-receipt-template 讀取失敗：' . $e->getMessage());
    respond(500, array('status' => 'failed', 'message' => '系統忙碌，請稍後再試'));
}

respond(200, array(
    'status' => 'success',
    // 每行：text（含 {{參數}}）、size（small/normal/large/xlarge）、bold、align
    'lines' => $lines,
    // 本店列印 logo（data URI），沒設就是 null
    'logo' => $logo,
    // 是否列印「存根聯」（店家留存那聯）。收銀機列印順序：
    //   1) 若為 true，先印存根聯（copyLabel = merchant）
    //   2) 印完印分隔線並暫停，等店員撕下
    //   3) 再由店員現場決定要不要印「收執聯」（copyLabel = customer）
    'printMerchantCopy' => $printMerchantCopy,
    // 收執聯下方要不要印掃碼退款 QR（QR 內容 = 交易回應帶的 refundToken）
    'printRefundQr' => $printRefundQr,
    // 掃碼收款（LINE Pay／行動支付）要不要列印簽單。與刷卡分開，預設關閉。
    'printScanPay' => $printScanPay,
    // 存根聯專用：金額 >= 這個數字時，在存根聯尾端加「持卡人簽名欄」，
    // 讓店員撕下給客人簽名後收回留存。0 = 一律不加。
    'signatureThreshold' => $signatureThreshold,
    // 聯別標籤文字，填進範本的 {{copyLabel}}
    'copyLabels' => array(
        'merchant' => '存根聯（店家存查）',
        'customer' => '收執聯（客戶收執）',
    ),
));
