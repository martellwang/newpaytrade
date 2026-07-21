<?php
/** 客戶後台的 APK 下載，必須先登入客戶後台。檔名白名單比對後才組路徑。 */

require_once __DIR__ . '/auth.php';
portal_require_login();

$apkDir = __DIR__ . '/../../../private/apk/';
$file = isset($_GET['file']) ? (string) $_GET['file'] : '';
if (!preg_match('/^(pos|phone)_\d+_[A-Za-z0-9.\-]+\.apk$/', $file)) {
    http_response_code(400);
    echo '檔名不正確';
    exit;
}
$path = $apkDir . $file;
if (!is_file($path)) {
    http_response_code(404);
    echo '找不到安裝檔';
    exit;
}
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
if (ob_get_level()) ob_end_clean();
readfile($path);
