<?php
/** 管理介面共用的頁首／頁尾與樣式 */

require_once __DIR__ . '/../brand.php';

function admin_header($title, $active = '') {
    $nav = array(
        'index.php' => '交易紀錄',
        'report.php' => '對帳報表',
        'devices.php' => '設備管理',
        'dealers.php' => '經銷商',
        'merchants.php' => '客戶管理',
        'merchant.php' => '商店狀態',
        'providers.php' => '上游管理',
        'apk.php' => '安裝檔',
        'settings.php' => '系統設定',
    );
    ?><!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="<?= NEWPAY_FAVICON ?>">
<link rel="apple-touch-icon" href="<?= NEWPAY_FAVICON ?>">
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
.pager { display:flex; align-items:center; gap:12px; }

/* ── 左右分欄版面（設備管理、系統設定用）──────────────────────
 * 左邊固定寬的功能選單，右邊是內容。窄螢幕時上下堆疊。
 * 這個管理平台是給桌機用的，小螢幕只求不破版、能捲。 */
.split { display:flex; gap:18px; align-items:flex-start; }
.split-nav { flex:0 0 200px; background:#fff; border-radius:10px; padding:10px;
             box-shadow:0 1px 4px rgba(0,0,0,.06); }
.split-body { flex:1; min-width:0; }   /* min-width:0 讓右欄的寬表格能自己捲，不撐破版面 */
.split-nav .grp { font-size:12px; color:#999; padding:10px 10px 4px; font-weight:600; }
.split-nav a { display:block; padding:9px 12px; border-radius:8px; text-decoration:none;
               color:#333; font-size:14px; margin-bottom:2px; }
.split-nav a:hover { background:#f3f0fb; }
.split-nav a.on { background:#5a3d99; color:#fff; font-weight:600; }
.split-nav a.sub { padding-left:22px; font-size:13px; }
@media (max-width:760px){ .split{flex-direction:column} .split-nav{flex-basis:auto;width:100%} }
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

/**
 * 左右分欄的左側功能選單。
 *
 * $nodes 每一項可以是：
 *   array('label'=>'POS機管理', 'key'=>'pos')        —— 可點的第一層項目
 *   array('label'=>'顯示設定', 'children'=>array(     —— 第一層標題 + 可點的第二層
 *       'page_size' => '清單預設每頁筆數'))
 *
 * 點選後以 ?section=<key> 帶回同一頁；$active 是目前選中的 key。
 * $baseUrl 是目前頁面檔名（例如 'settings.php'），保留其他既有查詢參數由呼叫端處理。
 */
function admin_render_split_nav($nodes, $active, $baseUrl) {
    echo '<nav class="split-nav">';
    foreach ($nodes as $node) {
        if (isset($node['key'])) {
            $on = ($node['key'] === $active) ? ' on' : '';
            echo '<a class="' . $on . '" href="' . h($baseUrl) . '?section=' . h(urlencode($node['key'])) . '">'
                . h($node['label']) . '</a>';
        } else {
            echo '<div class="grp">' . h($node['label']) . '</div>';
            foreach ($node['children'] as $key => $label) {
                $on = ($key === $active) ? ' on' : '';
                echo '<a class="sub' . $on . '" href="' . h($baseUrl) . '?section=' . h(urlencode($key)) . '">'
                    . h($label) . '</a>';
            }
        }
    }
    echo '</nav>';
}

function money($n) {
    return 'NT$ ' . number_format((int) $n);
}
