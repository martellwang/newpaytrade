<?php
/** 客戶後台共用頁首／頁尾／樣式。只放客戶自己能看的功能。 */

require_once __DIR__ . '/../brand.php';

function portal_header($title, $active = '') {
    // 客戶後台的選單 —— 公司內部功能（經銷商、客戶管理、上游、系統設定）一律不放
    $nav = array(
        'index.php'  => '交易紀錄',
        'report.php' => '對帳報表',
        'stores.php' => '商店與店員',
        'devices.php' => '設備',
        'apk.php'    => '安裝檔',
    );
    // 這個客戶若同時經營某個經銷商，多一個「經銷商介面」入口
    try {
        $conn = db_connect();
        if (db_find_dealers_by_owner($conn, portal_merchant_id())) {
            $nav['dealer.php'] = '經銷商介面';
        }
    } catch (Exception $e) { /* 偵測失敗就當一般客戶，不影響其他功能 */ }
    ?><!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="<?= NEWPAY_FAVICON ?>">
<link rel="apple-touch-icon" href="<?= NEWPAY_FAVICON ?>">
<title><?= h($title) ?> — 客戶後台</title>
<style>
* { box-sizing:border-box; }
body { font-family:-apple-system,"Noto Sans TC","Microsoft JhengHei",sans-serif;
       margin:0; background:#f5f5f7; color:#1c1c1e; }
/* 最上的導覽列固定不捲動（全站版面規範，與總後台一致） */
header { background:#00695c; color:#fff; padding:12px 20px; display:flex;
         align-items:center; gap:20px; flex-wrap:wrap;
         position:sticky; top:0; z-index:30; }
header h1 { font-size:16px; margin:0; }
header .who { font-size:12px; color:#c8e6df; }
header nav a { color:#c8e6df; text-decoration:none; margin-right:16px; font-size:15px; }
header nav a.on { color:#fff; font-weight:bold; border-bottom:2px solid #fff; padding-bottom:2px; }
header .sp { margin-left:auto; }
header .sp a { color:#c8e6df; font-size:14px; }
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
button { padding:8px 18px; border:0; border-radius:6px; background:#00695c;
         color:#fff; cursor:pointer; font-size:14px; }
.btn2 { background:#fff; color:#00695c; border:1px solid #00695c; text-decoration:none;
        padding:8px 14px; border-radius:6px; font-size:14px; display:inline-block; }
.kpi { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; }
.kpi div { background:#fff; border-radius:10px; padding:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
.kpi .lbl { font-size:12px; color:#777; }
.kpi .val { font-size:22px; font-weight:600; margin-top:4px; }
.muted { color:#888; font-size:13px; }
.right { text-align:right; }
.pager { display:flex; align-items:center; gap:12px; }

/* ── 左右分欄版面（全站版面規範，與總後台一致）───────────────
   左邊固定寬的功能選單、右邊內容；捲動時左欄與上方導覽都固定不動。 */
.split { display:flex; gap:18px; align-items:flex-start; }
.split-nav { flex:0 0 200px; background:#fff; border-radius:10px; padding:10px;
             box-shadow:0 1px 4px rgba(0,0,0,.06);
             position:sticky; top:70px; align-self:flex-start;
             max-height:calc(100vh - 88px); overflow-y:auto; }
.split-body { flex:1; min-width:0; }
.split-nav .grp { font-size:12px; color:#999; padding:10px 10px 4px; font-weight:600; }
.split-nav a { display:block; padding:9px 12px; border-radius:8px; text-decoration:none;
               color:#333; font-size:14px; margin-bottom:2px; }
.split-nav a:hover { background:#eef5f3; }
.split-nav a.on { background:#00695c; color:#fff; font-weight:600; }
.split-nav a.sub { padding-left:22px; font-size:13px; }
@media (max-width:760px){ .split{flex-direction:column} .split-nav{flex-basis:auto;width:100%} }
</style>
</head>
<body>
<header>
  <div>
    <h1>客戶後台</h1>
    <div class="who"><?= h(portal_merchant_name()) ?>（編號 <?= h(portal_customer_code()) ?>）</div>
  </div>
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

function portal_footer() {
    ?>
</main>
</body>
</html>
<?php
}

if (!function_exists('money')) {
    function money($n) { return 'NT$ ' . number_format((int) $n); }
}

/**
 * 左右分欄的左側功能選單（與總後台 admin_render_split_nav 同一套用法）。
 * $nodes 每項：array('label'=>..,'key'=>..) 可點項，或 array('label'=>..,'children'=>array(key=>label)) 群組。
 * 點選以 ?section=<key> 帶回同一頁；$active 是目前選中的 key；$baseUrl 是頁面檔名。
 */
if (!function_exists('portal_render_split_nav')) {
    function portal_render_split_nav($nodes, $active, $baseUrl) {
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
}

/**
 * 付款方式可讀標籤（與總後台 admin/layout.php 同一套邏輯）。
 * wallet 底下的錢包種類只在 raw_response 的 AuthType：4=Apple、5=Google、6=Samsung。
 */
if (!function_exists('payment_method_label')) {
    function payment_method_label($method, $raw = null) {
        if ($method === 'wallet') {
            $authType = '';
            if ($raw) {
                $j = json_decode($raw, true);
                if (is_array($j) && isset($j['AuthType'])) $authType = (string) $j['AuthType'];
            }
            $wallets = array('4' => 'Apple Pay', '5' => 'Google Pay', '6' => 'Samsung Pay');
            return isset($wallets[$authType]) ? $wallets[$authType] : '行動支付';
        }
        $labels = array('credit' => '信用卡', 'linepay' => 'LINE Pay', 'jkopay' => '街口支付', 'icash' => '愛金卡');
        return isset($labels[$method]) ? $labels[$method] : ($method ?: '—');
    }
}
function portal_status_badge($status) {
    $labels = array('success' => '成功', 'failed' => '失敗', 'pending' => '處理中');
    $label = isset($labels[$status]) ? $labels[$status] : $status;
    return '<span class="badge s-' . h($status) . '">' . h($label) . '</span>';
}
