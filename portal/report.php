<?php
/**
 * 客戶後台 — 對帳報表（按日彙總，只算自己）。
 * merchant_id 一律取自 session。
 */

require_once __DIR__ . '/auth.php';
portal_require_login();
require_once __DIR__ . '/layout.php';

$conn = db_connect();
$merchantId = portal_merchant_id();

$from = isset($_GET['from']) ? trim($_GET['from']) : date('Y-m-01');
$to   = isset($_GET['to']) ? trim($_GET['to']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

// 區間總計（只算這個客戶）
$stmt = mysqli_prepare($conn,
    "SELECT COUNT(*) AS total_cnt,
            SUM(status='success') AS success_cnt,
            SUM(status='pending') AS pending_cnt,
            COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) AS success_amt,
            COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) AS pending_amt
     FROM orders
     WHERE merchant_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)");
mysqli_stmt_bind_param($stmt, 'iss', $merchantId, $from, $to);
mysqli_stmt_execute($stmt);
$sum = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

// 逐日
$stmt = mysqli_prepare($conn,
    "SELECT DATE(created_at) AS d,
            SUM(status='success') AS success_cnt,
            SUM(status='failed')  AS failed_cnt,
            SUM(status='pending') AS pending_cnt,
            COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) AS success_amt
     FROM orders
     WHERE merchant_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
     GROUP BY DATE(created_at) ORDER BY d DESC");
mysqli_stmt_bind_param($stmt, 'iss', $merchantId, $from, $to);
mysqli_stmt_execute($stmt);
$daily = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

portal_header('對帳報表', 'report.php');
?>

<div class="card">
  <form class="filters" method="get">
    <div><label>起始日期</label><input type="date" name="from" value="<?= h($from) ?>"></div>
    <div><label>結束日期</label><input type="date" name="to" value="<?= h($to) ?>"></div>
    <div><button type="submit">產生報表</button></div>
  </form>
</div>

<div class="kpi">
  <div><div class="lbl">成功筆數</div><div class="val"><?= number_format((int)$sum['success_cnt']) ?></div></div>
  <div><div class="lbl">成功金額</div><div class="val"><?= h(money($sum['success_amt'])) ?></div></div>
  <div><div class="lbl">處理中</div><div class="val"><?= number_format((int)$sum['pending_cnt']) ?></div></div>
</div>

<?php if ((int)$sum['pending_cnt'] > 0): ?>
<div class="card" style="background:#fff3e0">
  這個區間有 <?= number_format((int)$sum['pending_cnt']) ?> 筆狀態未定（處理中），
  金額 <?= h(money($sum['pending_amt'])) ?>。這些交易可能已扣款但尚未收到結果，
  是對帳差異最常見的來源，請到「交易紀錄」逐筆確認。
</div>
<?php endif; ?>

<div class="card wrap">
  <table>
    <thead><tr><th>日期</th><th class="right">成功筆數</th><th class="right">成功金額</th>
      <th class="right">失敗</th><th class="right">處理中</th></tr></thead>
    <tbody>
      <?php if (!$daily): ?>
        <tr><td colspan="5" class="muted">這個區間沒有交易紀錄</td></tr>
      <?php endif; ?>
      <?php foreach ($daily as $d): ?>
      <tr>
        <td><?= h($d['d']) ?></td>
        <td class="right"><?= number_format((int)$d['success_cnt']) ?></td>
        <td class="right"><?= h(money($d['success_amt'])) ?></td>
        <td class="right"><?= number_format((int)$d['failed_cnt']) ?></td>
        <td class="right"><?= (int)$d['pending_cnt'] > 0
            ? '<span class="badge s-pending">'.number_format((int)$d['pending_cnt']).'</span>' : '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php portal_footer(); ?>
