<?php
/**
 * 中轉頁的第二步：客人選好錢包之後，送他到 PAYUNi UPP 完成付款。
 *
 * UPP 的請求方式是 **Form Post**，所以這裡輸出一個自動送出的表單 ——
 * 這正是整個中轉頁存在的原因，QR 只能裝 GET 網址，裝不了表單。
 *
 * ── 只啟用客人選的那一種錢包 ─────────────────────────────────
 *
 * UPP 的支付工具參數（ApplePay / GooglePay / SamsungPay）帶哪個就顯示哪個。
 * 只帶選定的那一個，客人不會在付款頁上又被問一次同樣的問題。
 *
 * 但**改選的路要留著**：BackURL 指回中轉頁，客人若發現選的錢包在他手機上
 * 不能用，按 UPP 的「返回商店」就能回來改選別的，不必找店員重新產生 QR。
 *
 * 這一頁刻意不顯示任何錯誤細節給客人 —— 他只需要知道「能不能付」，
 * 內部原因記在 error_log 給我們查。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payuni_crypto.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

function fail($heading, $detail) {
    $h = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $d = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!doctype html>
<html lang="zh-Hant"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$h}</title>
<style>
 body{margin:0;padding:24px 20px;font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans TC",sans-serif;background:#f5f5f7;color:#1c1c1e}
 .card{background:#fff;border-radius:16px;padding:24px 20px;max-width:420px;margin:0 auto}
 .h{font-size:20px;font-weight:700;margin-bottom:10px}
 .d{font-size:14px;color:#6b6b70;line-height:1.6}
</style></head>
<body><div class="card"><div class="h">{$h}</div><div class="d">{$d}</div></div></body></html>
HTML;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('無法付款', '請重新掃描店家提供的 QR。');
}

$token = isset($_POST['t']) ? $_POST['t'] : '';
$method = isset($_POST['method']) ? $_POST['method'] : '';

// 白名單。只接受這三種 —— 這是「只做錢包、不放信用卡」的實際執行點，
// 其他值一律拒絕，不要讓外部參數決定送什麼給上游。
$allowedMethods = array('apple', 'google', 'samsung');
if (!in_array($method, $allowedMethods, true)) {
    error_log('掃碼付款：不合法的支付方式 ' . $method);
    fail('無法付款', '請重新掃描店家提供的 QR。');
}

try {
    $conn = db_connect();
    // 有效期限由資料庫判斷，過期的 token 這裡就查不到
    $link = db_find_payment_link($conn, $token);
} catch (Exception $e) {
    error_log('掃碼付款導向時資料庫失敗：' . $e->getMessage());
    fail('暫時無法付款', '系統忙碌中，請稍後再試，或改用其他方式付款。');
}

if (!$link) {
    fail('付款連結已失效', '這個付款連結不存在或已超過有效時間，請請店員重新產生一次 QR。');
}

/*
 * 已經付過就不要再送一次。
 *
 * 客人按了付款、成功之後又按上一頁再按一次 —— 這在手機上很常見。
 * 沒有這道檢查就會產生第二筆付款。
 */
try {
    $order = db_find_order($conn, $link['mer_trade_no']);
} catch (Exception $e) {
    error_log('掃碼付款查詢訂單失敗：' . $e->getMessage());
    fail('暫時無法付款', '系統忙碌中，請稍後再試。');
}
if ($order && $order['status'] === 'success') {
    fail('這筆款項已付款完成', '請勿重複付款。若畫面顯示有誤，請洽櫃檯人員確認。');
}

try {
    db_mark_payment_link_opened($conn, $token, $method);
} catch (Exception $e) {
    error_log('記錄付款方式失敗：' . $e->getMessage());
}

/*
 * ── 組出 UPP 的表單並自動送出 ────────────────────────────────
 *
 * 每個值都來自資料庫，沒有一項來自客人的輸入。**金額尤其重要** ——
 * 從外部參數取的話，客人改一下就能決定自己要付多少。
 */
$methodParam = array(
    'apple' => 'ApplePay',
    'google' => 'GooglePay',
    'samsung' => 'SamsungPay',
);

$secondsLeft = max(60, min(600, strtotime($link['expires_at']) - time()));

$encryptInfoParams = array(
    'MerID' => $link['mer_id'],
    'MerTradeNo' => $link['mer_trade_no'],
    'TradeAmt' => (string) (int) $link['amount'],
    'Timestamp' => (string) time(),
    'ProdDesc' => '掃碼收款',
    // 付款頁自己的倒數。範圍 60-600 秒，與我們連結的剩餘時間對齊 ——
    // 兩邊時間不一致的話，會出現「連結還沒過期但付款頁已經失效」的怪狀況。
    'TradeLExpireSec' => (string) $secondsLeft,
    // 只啟用客人選的那一種
    $methodParam[$method] => '1',
    /*
     * 背景通知：交易結果的**唯一權威來源**。
     *
     * 客人瀏覽器上看到什麼都不算數 —— 他可以在付款完成前關掉頁面，也可能
     * 停在一個顯示成功但其實沒完成的畫面。收銀機只認這裡進來的結果，
     * 以及主動查詢。
     */
    'NotifyURL' => PUBLIC_BASE_URL . '/notify-scan.php',
    // 客人付完款後瀏覽器會落在這裡
    'ReturnURL' => PUBLIC_BASE_URL . '/pay-done.php',
    // 「返回商店」按回中轉頁，讓客人能改選別的錢包
    'BackURL' => PUBLIC_BASE_URL . '/pay.php?t=' . $link['token'],
);

try {
    $encryptInfo = payuni_encrypt_trade_info($encryptInfoParams, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
    $hashInfo = payuni_generate_hash($encryptInfo, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
} catch (Exception $e) {
    error_log('行動支付加密失敗：' . $e->getMessage());
    fail('暫時無法付款', '系統忙碌中，請稍後再試，或改用其他方式付款。');
}

$fields = array(
    'MerID' => $link['mer_id'],
    'Version' => '2.0', // UPP 固定 2.0
    'EncryptInfo' => $encryptInfo,
    'HashInfo' => $hashInfo,
    /*
     * ⚠️ 文件沒有列這個參數，但我們是代理商帳號，其他幾支 API 都必須帶
     *    （沒帶會回 DEF01007「Hash比對不符合」）。UPP 尚未實測，若付款頁
     *    出現簽章錯誤，這一行是第一個要拿掉試的。
     */
    'IsPlatForm' => '1',
);

$inputs = '';
foreach ($fields as $k => $v) {
    $inputs .= '<input type="hidden" name="' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8')
        . '" value="' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '">' . "
";
}
$action = htmlspecialchars(PAYUNI_UPP_URL, ENT_QUOTES, 'UTF-8');

/*
 * 自動送出，但也留一顆按鈕：JavaScript 被擋掉時客人還能自己按，
 * 不會停在一個什麼都不會發生的白畫面。
 */
echo <<<HTML
<!doctype html>
<html lang="zh-Hant"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>前往付款</title>
<style>
 body{margin:0;padding:40px 20px;text-align:center;font-family:-apple-system,
 BlinkMacSystemFont,"Segoe UI","Noto Sans TC",sans-serif;background:#f5f5f7;color:#1c1c1e}
 .m{font-size:15px;color:#6b6b70;margin-bottom:20px}
 button{padding:14px 28px;font-size:16px;border:1px solid #d1d1d6;
 border-radius:12px;background:#fff;cursor:pointer}
</style></head>
<body>
<div class="m">正在前往付款頁面…</div>
<form id="f" method="post" action="{$action}">
{$inputs}
<button type="submit">若未自動跳轉，請點此繼續</button>
</form>
<script>document.getElementById('f').submit();</script>
</body></html>
HTML;
