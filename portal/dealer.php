<?php
/**
 * 客戶後台 — 經銷商介面。
 *
 * 只有「同時是經銷商」的客戶看得到（dealers.owner_merchant_id = 自己）。
 * 功能：看旗下商店的交易彙總、旗下商店清單，以及把「派到自己的設備」
 * 再派給旗下客戶（dealer → customer 再派工）。
 *
 * ⚠️ 授權：dealer_id 只從「自己經營的經銷商」來，絕不從網址帶入；再派工時
 *    也驗證「設備確實派給自己」且「目標客戶確實在自己旗下」。
 */

require_once __DIR__ . '/auth.php';
portal_require_login();
require_once __DIR__ . '/layout.php';

$conn = db_connect();
$merchantId = portal_merchant_id();

// 這個客戶經營的經銷商（可能多個；先用第一個，之後要多經銷商切換再擴充）
$owned = db_find_dealers_by_owner($conn, $merchantId);
if (!$owned) {
    // 不是經銷商，不該進來
    header('Location: index.php');
    exit;
}
$dealer = $owned[0];
$dealerId = (int) $dealer['id'];

$flash = null; $flashOk = false;

// ── 再派工：把派到自己的設備派給旗下客戶 ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portal_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $flash = '表單已過期，請重新操作';
    } else {
        $deviceId = isset($_POST['device_id']) ? (string) $_POST['device_id'] : '';
        $targetMerchant = (int) (isset($_POST['merchant_id']) ? $_POST['merchant_id'] : 0);
        // 雙重授權：設備要真的派給我、客戶要真的在我旗下
        if (!db_device_is_dispatched_to_dealer($conn, $deviceId, $dealerId)) {
            $flash = '這台設備不是派給你的';
        } elseif (!db_merchant_under_dealer($conn, $targetMerchant, $dealerId)) {
            $flash = '這個客戶不在你旗下';
        } else {
            db_dispatch_device_to_merchant($conn, $deviceId, $targetMerchant);
            $flashOk = true; $flash = '已把設備派給客戶';
        }
    }
}

$from = isset($_GET['from']) ? trim($_GET['from']) : date('Y-m-01');
$to   = isset($_GET['to']) ? trim($_GET['to']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$summary = db_dealer_order_summary($conn, $dealerId, $from, $to);
$stores  = db_list_stores_by_dealer($conn, $dealerId);
$pendingDevices = db_list_devices_by_dealer($conn, $dealerId);   // 派到我、還沒再派的設備
$downstreamMerchants = db_list_merchants($conn, $dealerId);       // 旗下客戶（再派工的目標）

portal_header('經銷商介面', 'dealer.php');
?>

<?php if ($flash): ?>
  <div class="card" style="border-left:4px solid <?= $flashOk ? '#2e7d32' : '#c62828' ?>"><?= h($flash) ?></div>
<?php endif; ?>

<div class="card">
  <strong style="font-size:16px">經銷商：<?= h($dealer['name']) ?></strong>
  <div class="muted" style="margin-top:4px">以下是你旗下所有商店的彙總與設備派發。</div>
</div>

<div class="card">
  <form class="filters" method="get">
    <div><label>起始日期</label><input type="date" name="from" value="<?= h($from) ?>"></div>
    <div><label>結束日期</label><input type="date" name="to" value="<?= h($to) ?>"></div>
    <div><button type="submit">彙總</button></div>
  </form>
</div>

<div class="kpi">
  <div><div class="lbl">旗下商店</div><div class="val"><?= number_format(count($stores)) ?></div></div>
  <div><div class="lbl">成功筆數</div><div class="val"><?= number_format((int)$summary['success_cnt']) ?></div></div>
  <div><div class="lbl">成功金額</div><div class="val"><?= h(money($summary['success_amt'])) ?></div></div>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;font-size:16px">旗下商店</h3>
  <div class="wrap">
    <table>
      <thead><tr><th>商店代號</th><th>商店名稱</th><th>所屬客戶</th><th>狀態</th></tr></thead>
      <tbody>
        <?php if (!$stores): ?>
          <tr><td colspan="4" class="muted">旗下還沒有商店。</td></tr>
        <?php endif; ?>
        <?php foreach ($stores as $st): ?>
        <tr>
          <td><strong style="letter-spacing:1px"><?= h($st['store_code'] ?: '—') ?></strong></td>
          <td><?= h($st['name']) ?></td>
          <td class="muted"><?= h($st['customer_code'] . ' ' . $st['merchant_name']) ?></td>
          <td><?= (int) $st['enabled'] === 1
                ? '<span class="badge s-success">啟用</span>'
                : '<span class="badge s-failed">停用</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h3 style="margin:0 0 6px;font-size:16px">派發設備給旗下客戶</h3>
  <div class="muted" style="margin-bottom:12px">
    這些是總部派給你的設備，你可以再把它派給旗下的某個客戶（派出後客戶就能在自己的店登入使用）。
  </div>
  <?php if (!$pendingDevices): ?>
    <div class="muted">目前沒有待派發的設備。</div>
  <?php elseif (!$downstreamMerchants): ?>
    <div class="muted">你旗下還沒有客戶可以派發，請聯絡總部。</div>
  <?php else: ?>
    <div class="wrap">
      <table>
        <thead><tr><th>設備序號</th><th>機型</th><th>派給客戶</th></tr></thead>
        <tbody>
          <?php foreach ($pendingDevices as $dv): ?>
          <tr>
            <td><strong><?= h($dv['serial_no'] ?: $dv['device_id']) ?></strong></td>
            <td class="muted"><?= h(trim(($dv['brand'] ?: '') . ' ' . ($dv['model'] ?: '')) ?: '—') ?></td>
            <td>
              <form method="post" style="display:flex;gap:8px;align-items:center">
                <input type="hidden" name="csrf" value="<?= h(portal_csrf_token()) ?>">
                <input type="hidden" name="device_id" value="<?= h($dv['device_id']) ?>">
                <select name="merchant_id" required>
                  <option value="">選擇客戶</option>
                  <?php foreach ($downstreamMerchants as $m): ?>
                    <option value="<?= (int) $m['id'] ?>"><?= h($m['customer_code'] . ' ' . $m['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit">派發</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php portal_footer(); ?>
