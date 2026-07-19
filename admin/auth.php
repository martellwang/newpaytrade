<?php
/**
 * 管理介面的登入驗證。
 *
 * 為什麼不沿用 X-API-Key：那把金鑰是給 App 用的機器對機器憑證，放進網頁
 * 表單或網址等於公開，而且一旦外流就要同時換掉 App 端設定。管理介面是
 * 給人用的，需要獨立的帳密與 session。
 *
 * 密碼以 password_hash 儲存於 config.php（只存雜湊，不存明碼）。
 * 產生方式（在主機上執行，不要把明碼寫進任何檔案）：
 *   php -r 'echo password_hash("你的密碼", PASSWORD_DEFAULT), PHP_EOL;'
 * 然後把輸出的字串填進 config.php 的 ADMIN_PASSWORD_HASH。
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../rate_limit.php';

function admin_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    // Cookie 安全設定：
    //   httponly  —— JavaScript 讀不到，降低 XSS 竊取 session 的風險
    //   secure    —— 只在 HTTPS 傳送（本機 http 測試時會關掉）
    //   samesite  —— 擋 CSRF，Strict 讓外站連結過來時不帶 cookie
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(array(
        'lifetime' => 0,          // 關掉瀏覽器就失效
        'path' => '/newpaytrade/admin',
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Strict',
    ));
    session_name('NEWPAYADMIN');
    session_start();
}

function admin_is_logged_in() {
    admin_start_session();
    if (empty($_SESSION['admin_logged_in'])) return false;

    // 閒置逾時：POS 後台可能開著沒人顧，30 分鐘沒動作就登出
    $idleLimit = 1800;
    if (isset($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > $idleLimit) {
        admin_logout();
        return false;
    }
    $_SESSION['last_active'] = time();
    return true;
}

function admin_login($password) {
    admin_start_session();

    if (!defined('ADMIN_PASSWORD_HASH') || ADMIN_PASSWORD_HASH === '') {
        return '尚未設定管理密碼，請先在 config.php 填入 ADMIN_PASSWORD_HASH';
    }

    // 登入也要擋暴力破解，沿用既有的速率限制機制
    try {
        $conn = db_connect();
        $bucket = 'admin_login:' . rl_client_ip();
        if (rl_count($conn, $bucket, 900, 'failed') >= 5) {
            return '登入失敗次數過多，請 15 分鐘後再試';
        }
        if (!password_verify($password, ADMIN_PASSWORD_HASH)) {
            rl_record($conn, $bucket, 'failed');
            error_log('管理介面登入失敗，IP：' . rl_client_ip());
            // 訊息刻意不區分「帳號不存在」與「密碼錯誤」
            return '密碼錯誤';
        }
    } catch (Exception $e) {
        error_log('管理介面登入檢查失敗：' . $e->getMessage());
        return '系統錯誤，請稍後再試';
    }

    // 登入成功後換一組 session id，避免 session fixation 攻擊
    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['last_active'] = time();
    return null;
}

function admin_logout() {
    admin_start_session();
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** 每個管理頁面最上方都要呼叫這個 */
function admin_require_login() {
    if (!admin_is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

/** CSRF token：表單送出時比對，防止外站誘導使用者送出請求 */
function admin_csrf_token() {
    admin_start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function admin_verify_csrf($token) {
    admin_start_session();
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) $token);
}

/** 一律用這個輸出到 HTML，避免 XSS */
function h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
