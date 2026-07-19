<?php
/**
 * 提供 App 安裝檔下載，必須先登入管理介面。
 *
 * 為什麼要擋在登入後面：APK 裡編譯進了 BACKEND_API_KEY，任何人拿到 APK
 * 反組譯就能取得那把金鑰，進而直接呼叫授權 API。所以絕對不能放在公開
 * 網址上讓人直接抓。
 *
 * APK 實際檔案放在 web 目錄之外（../../private/），這樣就算 .htaccess
 * 哪天設定錯了也不會被直接下載到。
 */

require_once __DIR__ . '/auth.php';
admin_require_login();

// 放在 web 根目錄之外。主機的 ~/private 是 ISPConfig 提供的非公開目錄。
$apkPath = __DIR__ . '/../../../private/newpaytrade-pos.apk';

if (!is_file($apkPath)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>找不到安裝檔</h1><p>請先由開發者上傳 APK 到主機的 private 目錄。</p>';
    exit;
}

// application/vnd.android.package-archive 才會讓 Android 瀏覽器認得這是
// 可安裝的 App，而不是當成一般檔案下載。
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="newpaytrade-pos.apk"');
header('Content-Length: ' . filesize($apkPath));
header('X-Content-Type-Options: nosniff');

// 大檔案要清掉輸出緩衝再送，避免記憶體不足
if (ob_get_level()) ob_end_clean();
readfile($apkPath);
