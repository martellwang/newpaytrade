<?php
/**
 * 掃碼付款中轉頁 —— 客人用手機掃收銀機上的 QR 之後開啟的就是這一頁。
 *
 *   收銀機顯示 QR（指向這裡）
 *     → 客人掃碼，手機瀏覽器開啟本頁
 *       → 顯示店名與金額，讓客人選錢包
 *         → 導向 PAYUNi UPP 完成付款
 *
 * ── 為什麼要有這一頁，不直接把 UPP 網址放進 QR ──────────────
 *
 *   1. UPP 是為網購設計的，多半需要表單 POST；QR 只能裝 GET 網址
 *   2. 客人在付款前應該看到「哪一家店、多少錢」，直接跳進付款頁不會有
 *   3. 支付方式由我們決定要顯示哪些，不受 UPP 頁面的設定影響
 *
 * ── ⚠️ 這是整個系統唯一對外公開、不需要 API Key 的頁面 ──────
 *
 * 任何人拿到網址都能打開，所以：
 *   - **金額只從資料庫讀，絕不接受網址參數** —— 否則等於讓客人自己
 *     決定付多少錢
 *   - token 是隨機的，不可推測（訂單編號是 POS+時間戳，猜得到）
 *   - 有效期限由資料庫判斷，不信任畫面上的倒數
 *   - 只顯示店名與金額，不透露商店代號、客戶編號等內部資料
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');
// 這頁含交易金額，不要讓瀏覽器或中間的快取留存
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

/** 把畫面包成一頁輸出。手機瀏覽器開啟，版面以單欄大按鈕為主。 */
function render_page($title, $bodyHtml) {
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{$t}</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 24px 20px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans TC", sans-serif;
    background: #f5f5f7; color: #1c1c1e;
    display: flex; flex-direction: column; align-items: center;
    min-height: 100vh;
  }
  .card {
    background: #fff; border-radius: 16px; padding: 24px 20px;
    width: 100%; max-width: 420px; box-shadow: 0 2px 12px rgba(0,0,0,.06);
  }
  .store { font-size: 15px; color: #6b6b70; }
  .amount { font-size: 40px; font-weight: 700; margin: 6px 0 4px; }
  .note { font-size: 13px; color: #6b6b70; line-height: 1.6; }
  .pick { font-size: 14px; color: #6b6b70; margin: 22px 0 10px; }
  /* 錢包按鈕做大：客人常常站著單手操作 */
  .wallet {
    display: block; width: 100%; padding: 16px; margin-bottom: 12px;
    font-size: 17px; font-weight: 600; text-align: center;
    border: 1px solid #d1d1d6; border-radius: 12px;
    background: #fff; color: #1c1c1e; cursor: pointer;
  }
  .wallet:active { background: #ececf0; }
  .wallet.suggested { border-color: #1c1c1e; border-width: 2px; }
  .tag { display: block; font-size: 12px; font-weight: 400; color: #6b6b70; margin-top: 3px; }
  .warn {
    background: #fff4e5; border-radius: 12px; padding: 14px;
    font-size: 13px; line-height: 1.6; margin-top: 18px;
  }
  .err { font-size: 20px; font-weight: 700; margin-bottom: 10px; }
  .countdown { font-size: 13px; color: #6b6b70; margin-top: 16px; text-align: center; }
</style>
</head>
<body>
{$bodyHtml}
</body>
</html>
HTML;
    exit;
}

function render_error($heading, $detail) {
    $h = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $d = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
    render_page($heading, <<<HTML
<div class="card">
  <div class="err">{$h}</div>
  <div class="note">{$d}</div>
</div>
HTML);
}

$token = isset($_GET['t']) ? $_GET['t'] : '';

try {
    $conn = db_connect();
    db_create_payment_links_table_if_not_exists($conn);
    $link = db_find_payment_link($conn, $token);
} catch (Exception $e) {
    error_log('掃碼付款頁資料庫失敗：' . $e->getMessage());
    render_error('暫時無法付款', '系統忙碌中，請稍後再試，或改用其他方式付款。');
}

/*
 * 找不到與已過期回同一句話。
 *
 * 分開講的話（「此連結已過期」vs「查無此連結」）等於告訴掃到隨機網址的人
 * 哪些 token 曾經存在過。對客人來說兩者的處理方式也完全相同：請店員重新
 * 產生一次。
 */
if (!$link) {
    render_error('付款連結已失效', '這個付款連結不存在或已超過有效時間，請請店員重新產生一次 QR。');
}

$storeName = $link['store_name'] !== null && $link['store_name'] !== ''
    ? $link['store_name'] : '商店';
$amount = (int) $link['amount'];
$secondsLeft = max(0, strtotime($link['expires_at']) - time());

try {
    db_mark_payment_link_opened($conn, $token);
} catch (Exception $e) {
    // 只是統計用，失敗不影響付款
    error_log('標記付款連結開啟失敗：' . $e->getMessage());
}

/*
 * ── 錢包可用性只能靠猜，所以三個都顯示 ────────────────────
 *
 * Apple Pay 網頁版只在 Safari 有效、Google Pay 要 Chrome、Samsung Pay 要
 * 三星瀏覽器。但 User-Agent 判斷不可靠（各家瀏覽器互相偽裝已行之有年），
 * 用它來**隱藏**選項的話，判斷錯就等於讓客人完全無法付款。
 *
 * 所以三個一律顯示，只把最可能可用的那個標示出來當建議。猜錯的代價只是
 * 建議標錯，客人仍可自己選。
 */
$ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$isIos = (bool) preg_match('/iPhone|iPad|iPod/i', $ua);
$isSamsungBrowser = (bool) preg_match('/SamsungBrowser/i', $ua);
if ($isIos) {
    $suggested = 'apple';
} elseif ($isSamsungBrowser) {
    $suggested = 'samsung';
} else {
    $suggested = 'google';
}

/*
 * App 內嵌瀏覽器（LINE、Facebook、Instagram 的掃碼器）多半不支援網頁版
 * 錢包。這是實際櫃檯最常見的失敗原因 —— 客人會說「這裡沒有付款選項」，
 * 店員完全不知道為什麼。偵測得到就先講清楚該怎麼辦。
 */
$isInAppBrowser = (bool) preg_match('/(FBAN|FBAV|Instagram|Line\/|MicroMessenger)/i', $ua);

$wallets = array(
    'apple' => 'Apple Pay',
    'google' => 'Google Pay',
    'samsung' => 'Samsung Pay',
);

$buttons = '';
foreach ($wallets as $key => $label) {
    $cls = ($key === $suggested) ? 'wallet suggested' : 'wallet';
    $tag = ($key === $suggested) ? '<span class="tag">建議使用</span>' : '';
    $k = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
    $l = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $tk = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    $buttons .= <<<HTML
<form method="post" action="pay-redirect.php">
  <input type="hidden" name="t" value="{$tk}">
  <input type="hidden" name="method" value="{$k}">
  <button type="submit" class="{$cls}">{$l}{$tag}</button>
</form>
HTML;
}

$warnHtml = '';
if ($isInAppBrowser) {
    $warnHtml = <<<HTML
<div class="warn">
  <b>目前的瀏覽器可能無法使用行動支付</b><br>
  您似乎是從其他 App 內開啟這個頁面。請點右上角選單選擇「用瀏覽器開啟」，
  或直接用手機的相機重新掃描一次 QR。
</div>
HTML;
}

$amountText = number_format($amount);
$store = htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8');

render_page("付款 NT$ {$amountText}", <<<HTML
<div class="card">
  <div class="store">{$store}</div>
  <div class="amount">NT$ {$amountText}</div>
  <div class="note">請確認金額後選擇付款方式</div>

  <div class="pick">選擇行動支付</div>
  {$buttons}
  {$warnHtml}

  <div class="countdown" id="cd"></div>
</div>
<script>
// 倒數只是給客人看的提示。真正的有效期限在伺服器判斷 —— 改掉這段
// JavaScript 不會讓過期的連結變得可用。
(function () {
  var left = {$secondsLeft};
  var el = document.getElementById('cd');
  function tick() {
    if (left <= 0) {
      el.textContent = '此付款連結已過期，請請店員重新產生';
      return;
    }
    var m = Math.floor(left / 60), s = left % 60;
    el.textContent = '請於 ' + m + ' 分 ' + (s < 10 ? '0' : '') + s + ' 秒內完成付款';
    left--;
    setTimeout(tick, 1000);
  }
  tick();
})();
</script>
HTML);
