<?php
/**
 * 設定管理介面密碼（互動式，只能從命令列執行）。
 *
 * 用法：ssh 到主機後執行
 *   cd ~/web/newpaytrade && php admin/set_password.php
 *
 * 為什麼要有這支：bcrypt 雜湊長得像 $2y$10$xxx，手動複製貼上時
 * 那些 $ 很容易被 shell、sed、perl 或雙引號吃掉，導致寫進去的值是壞的
 * （而且不會報錯，只會登入失敗）。這支直接在 PHP 裡產生並寫入，
 * 全程不經過 shell，寫完還會自己驗證一次。
 */

// 絕對不能從網頁執行 —— 否則任何人都能重設後台密碼。
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("這支程式只能從命令列執行\n");
}

$configPath = __DIR__ . '/../config.php';
if (!is_file($configPath)) {
    exit("找不到 config.php：$configPath\n");
}
if (!is_writable($configPath)) {
    exit("config.php 沒有寫入權限，請確認檔案權限\n");
}

echo "設定管理介面登入密碼\n";
echo "（輸入時畫面不會顯示字元，這是正常的）\n\n";

/** 讀取密碼但不回顯到畫面 */
function prompt_hidden($label) {
    echo $label;
    // stty 是 Linux/macOS 的做法；Windows 沒有就退回一般輸入
    $hasStty = (stripos(PHP_OS, 'WIN') !== 0) && (shell_exec('command -v stty') !== null);
    if ($hasStty) {
        shell_exec('stty -echo');
        $value = trim(fgets(STDIN));
        shell_exec('stty echo');
        echo "\n";
    } else {
        echo "\n⚠️ 這個環境無法隱藏輸入，密碼會顯示在畫面上：";
        $value = trim(fgets(STDIN));
    }
    return $value;
}

$pw1 = prompt_hidden('請輸入新密碼：');
if (strlen($pw1) < 8) {
    exit("密碼太短，至少要 8 個字元\n");
}
$pw2 = prompt_hidden('請再輸入一次確認：');
if ($pw1 !== $pw2) {
    exit("兩次輸入不一致，未做任何變更\n");
}

$hash = password_hash($pw1, PASSWORD_DEFAULT);
if ($hash === false) {
    exit("產生雜湊失敗\n");
}

$config = file_get_contents($configPath);
$line = "define('ADMIN_PASSWORD_HASH', " . var_export($hash, true) . ");";

if (preg_match("/define\(\s*['\"]ADMIN_PASSWORD_HASH['\"].*?\);/s", $config)) {
    // 用 callback：一般的 preg_replace 會把雜湊裡的 $2、$10 當成回溯參照吃掉
    $config = preg_replace_callback(
        "/define\(\s*['\"]ADMIN_PASSWORD_HASH['\"].*?\);/s",
        function () use ($line) { return $line; },
        $config,
        1
    );
} else {
    $config .= "\n// 管理介面登入密碼（由 admin/set_password.php 產生）\n" . $line . "\n";
}

// 先備份再寫入，萬一寫壞還原得回來。備份放在 web 目錄外，避免被下載。
$backup = sys_get_temp_dir() . '/config.php.' . date('YmdHis') . '.bak';
file_put_contents($backup, file_get_contents($configPath));

if (file_put_contents($configPath, $config) === false) {
    exit("寫入 config.php 失敗，原檔備份在 $backup\n");
}

// 重新載入驗證（用子行程，避免這支已經 require 過的常數衝突）
$check = shell_exec('php -r ' . escapeshellarg(
    'require ' . var_export($configPath, true) . ';' .
    'echo (strlen(ADMIN_PASSWORD_HASH) === 60 && strpos(ADMIN_PASSWORD_HASH, "$2y$") === 0) ? "OK" : "BAD";'
));

if (trim((string) $check) !== 'OK') {
    exit("⚠️ 寫入後驗證失敗，請用備份還原：cp $backup $configPath\n");
}

// 再實際驗證一次密碼比對得過，確保真的能登入
$verify = shell_exec('php -r ' . escapeshellarg(
    'require ' . var_export($configPath, true) . ';' .
    'echo password_verify(' . var_export($pw1, true) . ', ADMIN_PASSWORD_HASH) ? "OK" : "BAD";'
));

if (trim((string) $verify) !== 'OK') {
    exit("⚠️ 密碼比對驗證失敗，請用備份還原：cp $backup $configPath\n");
}

echo "\n✅ 密碼已設定完成並驗證通過。\n";
echo "   登入網址：" . (defined('PUBLIC_BASE_URL') ? PUBLIC_BASE_URL : '你的網址') . "/admin/login.php\n\n";
echo "請記得刪掉編輯器可能留下的備份檔：\n";
echo "   rm -f " . dirname($configPath) . "/config.php~\n";
echo "暫存備份（含舊設定）：$backup\n";
echo "確認登入沒問題後可以刪除：rm -f $backup\n";
