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

/*
 * 兩種安裝型態：
 *
 *   pos（預設）—— 專用收銀機用。會宣告成裝置桌面，開機直接進收銀畫面。
 *   phone      —— 一般手機／平板用。就是普通 App，不會變成桌面。
 *
 * 分開的原因：桌面版在個人手機上安裝後，按 HOME 會跳出「要使用哪個主畫面」
 * 的選擇框，選錯會被鎖在收銀畫面裡。那是收銀機的必要之惡，不該波及一般裝置。
 */
$variants = array(
    'pos'   => 'newpaytrade-pos.apk',
    'phone' => 'newpaytrade-pos-phone.apk',
);

$variant = isset($_GET['variant']) ? $_GET['variant'] : 'pos';
// 白名單比對，不要把使用者輸入拼進路徑（免得被 ../ 穿越）
if (!isset($variants[$variant])) {
    $variant = 'pos';
}
$fileName = $variants[$variant];

// 放在 web 根目錄之外。主機的 ~/private 是 ISPConfig 提供的非公開目錄。
$apkPath = __DIR__ . '/../../../private/' . $fileName;

if (!is_file($apkPath)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>找不到安裝檔</h1><p>請先由開發者上傳 ' . htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8')
        . ' 到主機的 private 目錄。</p>';
    exit;
}

// application/vnd.android.package-archive 才會讓 Android 瀏覽器認得這是
// 可安裝的 App，而不是當成一般檔案下載。
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($apkPath));
header('X-Content-Type-Options: nosniff');

// 大檔案要清掉輸出緩衝再送，避免記憶體不足
if (ob_get_level()) ob_end_clean();
readfile($apkPath);
