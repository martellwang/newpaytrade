<?php
/**
 * 提供 App 安裝檔下載，必須先登入管理介面。
 *
 * 為什麼要擋在登入後面：APK 裡編譯進了 BACKEND_API_KEY，任何人拿到 APK
 * 反組譯就能取得那把金鑰，進而直接呼叫授權 API。所以絕對不能放在公開
 * 網址上讓人直接抓。
 *
 * APK 實際檔案放在 web 目錄之外（../../../private/apk/），版本化檔名見 apk.php。
 */

require_once __DIR__ . '/auth.php';
admin_require_login();

$apkDir = __DIR__ . '/../../../private/apk/';

// 只接受 apk.php 產生的版本化檔名，白名單比對後才拿去組路徑，杜絕 ../ 穿越。
$file = isset($_GET['file']) ? (string) $_GET['file'] : '';
if (!preg_match('/^(pos|phone)_\d+_[A-Za-z0-9.\-]+\.apk$/', $file)) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>檔名不正確</h1>';
    exit;
}

$apkPath = $apkDir . $file;
if (!is_file($apkPath)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>找不到安裝檔</h1><p>' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8')
        . ' 不存在，可能已被保留策略清除，請回安裝檔頁面選最新版。</p>';
    exit;
}

// application/vnd.android.package-archive 才會讓 Android 瀏覽器認得這是
// 可安裝的 App，而不是當成一般檔案下載。
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($apkPath));
header('X-Content-Type-Options: nosniff');

// 大檔案要清掉輸出緩衝再送，避免記憶體不足
if (ob_get_level()) ob_end_clean();
readfile($apkPath);
