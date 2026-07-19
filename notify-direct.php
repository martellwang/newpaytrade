<?php
/**
 * 逾時後的背景通知（NotifyURL）。文件說明：僅於平台點選確認發送，
 * 以及收到回覆為 UNKNOWN 時「後續通知交易結果使用」，是逾時情境的
 * 補救機制，不是每一筆都會呼叫。
 *
 * 這支端點是 PAYUNi 伺服器呼叫的，不是 App 呼叫的，所以不檢查
 * X-API-Key——真偽是靠 AES-GCM 的 authTag 驗證（解密失敗就代表資料
 * 被竄改或金鑰不對），另外再比對 HashInfo 當第二道。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payuni_crypto.php';
require_once __DIR__ . '/db.php';

// 通知內容可能比預期舊多久還算有效。PAYUNi 是逾時後才補發通知，正常
// 不會延遲太久；設 24 小時已經很寬鬆，同時能擋掉重放很久以前的封包。
define('NOTIFY_MAX_AGE_SEC', 86400);

$encryptInfo = isset($_POST['EncryptInfo']) ? $_POST['EncryptInfo'] : '';
$hashInfo = isset($_POST['HashInfo']) ? $_POST['HashInfo'] : '';

try {
    if ($encryptInfo === '') {
        throw new Exception('通知沒有 EncryptInfo');
    }

    // 第二道驗證：HashInfo。GCM 的 authTag 本身已經足以證明來源，
    // 但 PAYUNi 有送 HashInfo 就一起比對，成本很低。
    if ($hashInfo !== '' && !payuni_verify_hash($encryptInfo, $hashInfo, PAYUNI_HASH_KEY, PAYUNI_HASH_IV)) {
        throw new Exception('通知 HashInfo 比對不符，可能是偽造請求');
    }

    $detail = payuni_decrypt_trade_info($encryptInfo, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
    $merTradeNo = isset($detail['MerTradeNo']) ? $detail['MerTradeNo'] : '';

    // 時效檢查：擋掉重放很久以前擷取到的通知。沒有 Timestamp 就跳過檢查
    // （文件沒明訂通知一定會帶，不要因此拒收正常通知）。
    if (!empty($detail['Timestamp']) && is_numeric($detail['Timestamp'])) {
        $age = time() - (int) $detail['Timestamp'];
        if ($age > NOTIFY_MAX_AGE_SEC) {
            throw new Exception("通知已過期（$age 秒前），拒絕處理：$merTradeNo");
        }
    }

    $isPaid = (isset($detail['Status']) && $detail['Status'] === 'SUCCESS'
        && isset($detail['TradeStatus']) && $detail['TradeStatus'] === '1');
    $status = $isPaid ? 'success' : 'failed';

    error_log('收到幕後授權背景通知 ' . $merTradeNo
        . ' Status=' . (isset($detail['Status']) ? $detail['Status'] : '')
        . ' TradeStatus=' . (isset($detail['TradeStatus']) ? $detail['TradeStatus'] : ''));

    if ($merTradeNo === '') {
        // 沒有訂單編號就無從更新，但仍回 1|OK，否則 PAYUNi 會一直重送
        error_log('背景通知沒有 MerTradeNo，略過更新');
        echo '1|OK';
        exit;
    }

    $conn = db_connect();
    $order = db_find_order($conn, $merTradeNo);

    if (!$order) {
        // 注意：中文字串裡插入變數一定要用 {$var} 大括號界定。PHP 解析
        // 雙引號字串的變數名時會把 \x80-\xff 的位元組也算進變數名，全形
        // 標點（：（）等）是多位元組字元，寫成 "$var：" 會被當成變數
        // "$var：" 而報 Undefined variable。
        error_log("背景通知找不到對應訂單：{$merTradeNo}（可能是別的系統的訂單）");
        echo '1|OK';
        exit;
    }

    // ⚠️ 不允許把已經成功的訂單改成失敗。
    // 實測發現的問題：PAYUNi 重送、通知順序顛倒，或我們已先用 query.php
    // 確認成功之後延遲的通知才到，都會把「確實已收款」的訂單覆蓋成 failed，
    // 造成帳務短收。這種情況極少見且需要人工判斷，所以只記錄不自動改。
    if ($order['status'] === 'success' && $status === 'failed') {
        error_log("⚠️ 背景通知想把已成功的訂單 $merTradeNo 改為失敗，已拒絕自動覆蓋，請人工確認。"
            . ' 通知內容：' . json_encode($detail, JSON_UNESCAPED_UNICODE));
        echo '1|OK';
        exit;
    }

    // 狀態沒變就不用寫（避免無意義的 updated_at 變動）
    if ($order['status'] === $status) {
        echo '1|OK';
        exit;
    }

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
    error_log("背景通知更新訂單 {$merTradeNo}：{$order['status']} -> $status");

    echo '1|OK';
} catch (Exception $e) {
    error_log('處理幕後授權背景通知失敗：' . $e->getMessage());
    http_response_code(500);
    echo '0|ERR';
}
