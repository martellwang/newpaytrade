<?php
require_once __DIR__ . '/auth.php';

if (admin_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $error = '表單已逾時，請重新送出';
    } else {
        $error = admin_login(isset($_POST['password']) ? $_POST['password'] : '');
        if ($error === null) {
            header('Location: index.php');
            exit;
        }
    }
}
$csrf = admin_csrf_token();
require_once __DIR__ . '/../brand.php';
?><!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="<?= NEWPAY_FAVICON ?>">
<title>交易管理 — 登入</title>
<style>
body { font-family: -apple-system, "Noto Sans TC", "Microsoft JhengHei", sans-serif;
       background:#f5f5f7; display:flex; align-items:center; justify-content:center;
       min-height:100vh; margin:0; }
.box { background:#fff; padding:32px; border-radius:12px; width:100%; max-width:360px;
       box-shadow:0 2px 12px rgba(0,0,0,.08); }
h1 { font-size:20px; margin:0 0 24px; }
input[type=password] { width:100%; padding:12px; font-size:16px; border:1px solid #ccc;
       border-radius:6px; box-sizing:border-box; }
button { width:100%; padding:12px; font-size:16px; margin-top:16px; border:0;
       border-radius:6px; background:#5a3d99; color:#fff; cursor:pointer; }
.err { color:#c62828; margin-top:12px; font-size:14px; }
</style>
</head>
<body>
<div class="box">
  <h1>交易管理</h1>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="password" name="password" placeholder="管理密碼" autofocus required>
    <button type="submit">登入</button>
    <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
  </form>
</div>
</body>
</html>
