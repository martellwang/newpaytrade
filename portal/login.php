<?php
/** 客戶後台登入：客戶編號 + 登入帳號 + 密碼（同收銀機客戶憑證）。 */

require_once __DIR__ . '/auth.php';

// 已登入就直接進首頁
if (portal_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portal_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $error = '表單已過期，請重新登入';
    } else {
        $error = portal_login(
            trim(isset($_POST['customer_code']) ? $_POST['customer_code'] : ''),
            trim(isset($_POST['account']) ? $_POST['account'] : ''),
            isset($_POST['password']) ? $_POST['password'] : ''
        );
        if ($error === null) {
            header('Location: index.php');
            exit;
        }
    }
}
$csrf = portal_csrf_token();
$code = isset($_POST['customer_code']) ? h($_POST['customer_code']) : '';
$account = isset($_POST['account']) ? h($_POST['account']) : '';
require_once __DIR__ . '/../brand.php';
?><!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="<?= NEWPAY_FAVICON ?>">
<title>客戶登入 — 交易後台</title>
<style>
* { box-sizing:border-box; }
body { font-family:-apple-system,"Noto Sans TC","Microsoft JhengHei",sans-serif;
       margin:0; background:#f0f0f5; color:#1c1c1e; display:flex; min-height:100vh;
       align-items:center; justify-content:center; padding:20px; }
.box { background:#fff; border-radius:14px; padding:28px 24px; width:100%; max-width:380px;
       box-shadow:0 4px 24px rgba(0,0,0,.08); }
h1 { font-size:20px; margin:0 0 4px; }
.sub { color:#888; font-size:13px; margin-bottom:20px; }
label { display:block; font-size:13px; color:#555; margin:12px 0 4px; }
input { width:100%; padding:11px; border:1px solid #ccc; border-radius:8px; font-size:15px; }
button { width:100%; margin-top:20px; padding:12px; border:0; border-radius:8px;
         background:#5a3d99; color:#fff; font-size:16px; cursor:pointer; }
.err { background:#ffebee; color:#c62828; border-radius:8px; padding:10px 12px;
       font-size:14px; margin-bottom:14px; }
</style>
</head>
<body>
<div class="box">
  <h1>客戶登入</h1>
  <div class="sub">請輸入店家的客戶編號、帳號與密碼</div>
  <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label>客戶編號</label>
    <input type="text" name="customer_code" inputmode="numeric" value="<?= $code ?>" required autofocus>
    <label>登入帳號</label>
    <input type="text" name="account" value="<?= $account ?>" required>
    <label>密碼</label>
    <input type="password" name="password" required>
    <button type="submit">登入</button>
  </form>
</div>
</body>
</html>
