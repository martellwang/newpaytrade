<?php
/**
 * 收銀機 OTA 版更：查最新版本 + 下載 APK。
 *
 * 兩種用法（都要 API Key）：
 *   GET pos-apk.php?variant=pos            → 回傳該型態最新版本資訊（JSON）
 *   GET pos-apk.php?variant=pos&file=...   → 串流下載該 APK
 *
 * 安全性：APK 內含 BACKEND_API_KEY，所以下載必須擋在認證後（見 admin/download_apk.php
 * 的說明）。這裡用 API Key —— 呼叫端本來就是已安裝的收銀機，手上已經有這把 Key，
 * 不會比現況多洩漏什麼。檔名一律白名單比對，杜絕 ../ 穿越。
 *
 * variant：pos = 收銀機（kiosk）版、phone = 一般裝置（standard）版。
 */

require_once __DIR__ . '/config.php';

function respond($code, $body) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$apiKey = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '';
if ($apiKey === '' || $apiKey !== BACKEND_API_KEY) {
    respond(401, array('status' => 'failed', 'message' => 'unauthorized'));
}

$variant = isset($_GET['variant']) ? $_GET['variant'] : 'pos';
if ($variant !== 'pos' && $variant !== 'phone') {
    respond(400, array('status' => 'failed', 'message' => 'variant 只能是 pos 或 phone'));
}

$apkDir = __DIR__ . '/../../private/apk/';

/** 掃出某型態最新版（versionCode 最大）。回傳 array|null。 */
function apk_latest($apkDir, $variant) {
    $best = null;
    foreach (glob($apkDir . $variant . '_*.apk') ?: array() as $path) {
        $base = basename($path);
        if (!preg_match('/^' . preg_quote($variant, '/') . '_(\d+)_(.+)\.apk$/', $base, $m)) {
            continue;
        }
        $code = (int) $m[1];
        if ($best === null || $code > $best['versionCode']) {
            $best = array(
                'versionCode' => $code,
                'versionName' => $m[2],
                'file' => $base,
                'sizeBytes' => filesize($path),
                'path' => $path,
            );
        }
    }
    return $best;
}

// ── 下載 ──
$file = isset($_GET['file']) ? (string) $_GET['file'] : '';
if ($file !== '') {
    if (!preg_match('/^(pos|phone)_\d+_[A-Za-z0-9.\-]+\.apk$/', $file)) {
        respond(400, array('status' => 'failed', 'message' => '檔名格式不正確'));
    }
    $path = $apkDir . $file;
    if (!is_file($path)) {
        respond(404, array('status' => 'failed', 'message' => '找不到這個版本'));
    }
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    // 大檔用串流輸出，避免一次讀進記憶體
    $fp = fopen($path, 'rb');
    if ($fp) { fpassthru($fp); fclose($fp); }
    exit;
}

// ── 查版本 ──
$latest = apk_latest($apkDir, $variant);
if ($latest === null) {
    respond(200, array('status' => 'success', 'hasVersion' => false));
}
respond(200, array(
    'status' => 'success',
    'hasVersion' => true,
    'versionCode' => $latest['versionCode'],
    'versionName' => $latest['versionName'],
    'file' => $latest['file'],
    'sizeBytes' => $latest['sizeBytes'],
));
