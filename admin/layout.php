<?php
/** 管理介面共用的頁首／頁尾與樣式 */

function admin_header($title, $active = '') {
    $nav = array(
        'index.php' => '交易紀錄',
        'report.php' => '對帳報表',
        'devices.php' => '收銀機',
        'dealers.php' => '經銷商',
        'merchants.php' => '客戶管理',
        'merchant.php' => '商店狀態',
        'providers.php' => '上游管理',
        'apk.php' => '安裝檔',
    );
    ?><!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — 交易管理</title>
<style>
* { box-sizing: border-box; }
body { font-family:-apple-system,"Noto Sans TC","Microsoft JhengHei",sans-serif;
       margin:0; background:#f5f5f7; color:#1c1c1e; }
header { background:#5a3d99; color:#fff; padding:12px 20px; display:flex;
         align-items:center; gap:20px; flex-wrap:wrap; }
header h1 { font-size:17px; margin:0; }
header nav a { color:#e6dcff; text-decoration:none; margin-right:16px; font-size:15px; }
header nav a.on { color:#fff; font-weight:bold; border-bottom:2px solid #fff; padding-bottom:2px; }
header .sp { margin-left:auto; }
header .sp a { color:#e6dcff; font-size:14px; }
/*
 * 用瀏覽器寬度的 95%。交易列表欄位多（時間／訂單編號／金額／狀態／
 * 卡末四／授權碼／交易序號／訊息／明細鈕），原本固定 1100px 在寬螢幕上
 * 會把右側的「明細」按鈕擠出可視範圍，得橫向捲動才點得到。
 *
 * 這個管理平台是給桌機用的（使用者明確表示不需為手機最佳化），所以沒有
 * 特別處理小螢幕 —— 真的用手機開，.wrap 的 overflow-x 仍可橫向捲動。
 */
main { padding:20px; width:95%; margin:0 auto; }
.card { background:#fff; border-radius:10px; padding:18px; margin-bottom:18px;
        box-shadow:0 1px 4px rgba(0,0,0,.06); }
table { width:100%; border-collapse:collapse; font-size:14px; }
th, td { padding:9px 8px; text-align:left; border-bottom:1px solid #eee; white-space:nowrap; }
th { background:#fafafa; font-weight:600; color:#555; }
.wrap { overflow-x:auto; }
.badge { padding:2px 8px; border-radius:10px; font-size:12px; display:inline-block; }
.s-success { background:#e8f5e9; color:#2e7d32; }
.s-failed  { background:#ffebee; color:#c62828; }
.s-pending { background:#fff3e0; color:#ef6c00; }
form.filters { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
label { font-size:13px; color:#555; display:block; margin-bottom:4px; }
input, select { padding:8px; border:1px solid #ccc; border-radius:6px; font-size:14px; }
button { padding:8px 18px; border:0; border-radius:6px; background:#5a3d99;
         color:#fff; cursor:pointer; font-size:14px; }
.btn2 { background:#fff; color:#5a3d99; border:1px solid #5a3d99; text-decoration:none;
        padding:8px 14px; border-radius:6px; font-size:14px; display:inline-block; }
.kpi { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; }
.kpi div { background:#fff; border-radius:10px; padding:14px;
           box-shadow:0 1px 4px rgba(0,0,0,.06); }
.kpi .lbl { font-size:12px; color:#777; }
.kpi .val { font-size:22px; font-weight:600; margin-top:4px; }
.muted { color:#888; font-size:13px; }
.right { text-align:right; }
</style>
</head>
<body>
<header>
  <h1>交易管理</h1>
  <nav>
    <?php foreach ($nav as $file => $label): ?>
      <a href="<?= h($file) ?>" class="<?= $active === $file ? 'on' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <span class="sp"><a href="logout.php">登出</a></span>
</header>
<main>
<?php
}

function admin_footer() {
    ?>
</main>
</body>
</html>
<?php
}

/** 訂單狀態的顯示樣式 */
function status_badge($status) {
    $labels = array('success' => '成功', 'failed' => '失敗', 'pending' => '處理中');
    $label = isset($labels[$status]) ? $labels[$status] : $status;
    return '<span class="badge s-' . h($status) . '">' . h($label) . '</span>';
}

function money($n) {
    return 'NT$ ' . number_format((int) $n);
}
