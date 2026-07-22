<?php
/**
 * 下載設備型號的開發元件檔案（韌體／SDK／文件）。
 *
 * 檔案存在 web 根目錄外的私有區（device_model_files_dir()），無法直接由網址
 * 取得，一律經這支驗證登入後串流。檔名以 DB 紀錄為準，不吃使用者傳入的路徑，
 * 杜絕 ../ 穿越。
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
admin_require_login();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$conn = db_connect();
$f = db_get_device_model_file($conn, $id);
if (!$f) {
    http_response_code(404);
    echo '找不到檔案';
    exit;
}

// stored_name 是我們自己產生的安全檔名（見 devices.php 上傳處），但仍再擋一次
// 目錄穿越，只取 basename。
$stored = basename($f['stored_name']);
$path = device_model_files_dir() . '/' . (int) $f['model_id'] . '/' . $stored;
if (!is_file($path)) {
    http_response_code(404);
    echo '檔案不存在（可能已被刪除）';
    exit;
}

// 用原始檔名讓使用者下載時看到熟悉的名字
$downloadName = $f['orig_name'] !== '' ? $f['orig_name'] : $stored;
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
$fp = fopen($path, 'rb');
if ($fp) { fpassthru($fp); fclose($fp); }
exit;
