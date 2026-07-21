<?php
/**
 * 客戶登入後台的認證。與總後台 admin/ 分開：不同目錄、不同 session、
 * 不同的登入身分（客戶自己 vs 公司管理者）。
 *
 * 登入用**跟收銀機同一組客戶憑證**：客戶編號 + 登入帳號 + 密碼
 * （merchants 表，同 pos_login.php 的驗證）。
 *
 * ── 資料隔離是這個後台最關鍵的安全性質 ──────────────────────
 * 登入後每一頁都只能看到自己的資料 —— 一律用 session 裡的 merchant_id 過濾，
 * **絕不接受從網址／表單帶進來的 merchant_id**。
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../rate_limit.php';

function portal_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/newpaytrade/portal',   // 與 admin 的 session 分開
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Strict',
    ));
    session_name('NEWPAYPORTAL');
    session_start();
}

function portal_is_logged_in() {
    portal_start_session();
    if (empty($_SESSION['portal_merchant_id'])) return false;
    // 閒置 30 分鐘自動登出
    if (isset($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > 1800) {
        portal_logout();
        return false;
    }
    $_SESSION['last_active'] = time();
    return true;
}

function portal_merchant_id() {
    portal_start_session();
    return isset($_SESSION['portal_merchant_id']) ? (int) $_SESSION['portal_merchant_id'] : 0;
}
function portal_merchant_name() {
    portal_start_session();
    return isset($_SESSION['portal_merchant_name']) ? $_SESSION['portal_merchant_name'] : '';
}
function portal_customer_code() {
    portal_start_session();
    return isset($_SESSION['portal_customer_code']) ? $_SESSION['portal_customer_code'] : '';
}

/**
 * 驗證客戶編號 + 帳號 + 密碼。成功回 null，失敗回錯誤訊息字串。
 * 沿用 pos_login 那套（db_find_merchant_by_login + password_verify），
 * 加上與 admin 相同的速率限制擋暴力破解。
 */
function portal_login($customerCode, $account, $password) {
    portal_start_session();
    if ($customerCode === '' || $account === '' || $password === '') {
        return '請輸入客戶編號、帳號與密碼';
    }
    try {
        $conn = db_connect();
        $bucket = 'portal_login:' . rl_client_ip();
        if (rl_count($conn, $bucket, 900, 'failed') >= 5) {
            return '登入失敗次數過多，請 15 分鐘後再試';
        }
        $merchant = db_find_merchant_by_login($conn, $customerCode, $account);
        $dummy = '$2y$10$usesomesillystringforsalttoavoidtimingleakabcdefghijklmn';
        $ok = password_verify($password, $merchant ? $merchant['password_hash'] : $dummy);
        if (!$merchant || !$ok || (int) $merchant['enabled'] !== 1) {
            rl_record($conn, $bucket, 'failed');
            error_log('客戶後台登入失敗，IP：' . rl_client_ip());
            return '客戶編號、帳號或密碼不正確';
        }
    } catch (Exception $e) {
        error_log('客戶後台登入檢查失敗：' . $e->getMessage());
        return '系統錯誤，請稍後再試';
    }

    session_regenerate_id(true);
    $_SESSION['portal_merchant_id'] = (int) $merchant['id'];
    $_SESSION['portal_merchant_name'] = $merchant['name'];
    $_SESSION['portal_customer_code'] = $merchant['customer_code'];
    $_SESSION['last_active'] = time();
    return null;
}

function portal_logout() {
    portal_start_session();
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function portal_require_login() {
    if (!portal_is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function portal_csrf_token() {
    portal_start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function portal_verify_csrf($token) {
    portal_start_session();
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) $token);
}

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
