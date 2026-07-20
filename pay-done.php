<?php
/**
 * 客人付完款之後瀏覽器落腳的頁面（UPP 的 ReturnURL）。
 *
 * ── ⚠️ 這一頁刻意不宣告交易成功 ─────────────────────────────
 *
 * PAYUNi 會把交易結果 Form Post 到這裡，但**那是經過客人瀏覽器的資料**，
 * 可以被偽造 —— 有人自己組一個表單 POST 過來就能看到「付款成功」的畫面，
 * 拿給店員看就走了。
 *
 * 所以這頁只說「已完成操作，請回櫃檯」，把判斷交給收銀機 ——
 * 那邊的結果來自背景通知與主動查詢，是客人碰不到的路徑。
 *
 * 這也符合實際的收款動線：客人本來就要回到櫃檯拿東西，店員看自己機器上的
 * 結果，不是看客人的手機。
 */

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

// 收到什麼都記下來，之後排查用。不解讀、不據以判斷。
error_log('收到 UPP ReturnURL 回傳：' . substr(json_encode($_POST, JSON_UNESCAPED_UNICODE), 0, 2000));

echo <<<HTML
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>已完成操作</title>
<style>
  :root { color-scheme: light; }
  body {
    margin: 0; padding: 40px 20px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans TC", sans-serif;
    background: #f5f5f7; color: #1c1c1e;
  }
  .card {
    background: #fff; border-radius: 16px; padding: 32px 24px;
    max-width: 420px; margin: 0 auto; text-align: center;
  }
  .h { font-size: 22px; font-weight: 700; margin-bottom: 12px; }
  .d { font-size: 15px; color: #6b6b70; line-height: 1.7; }
</style>
</head>
<body>
<div class="card">
  <div class="h">已完成操作</div>
  <div class="d">
    請回到櫃檯，由店員確認收款結果。<br>
    實際付款狀態以店家收銀機顯示為準。
  </div>
</div>
</body>
</html>
HTML;
