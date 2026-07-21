<?php
/**
 * 對帳報表：按日彙總，讓你能跟 PAYUNi 後台的撥款/交易報表逐日核對。
 *
 * 核帳邏輯的重點：
 *   - 「成功金額」只算 status=success 的訂單
 *   - 「退款金額」算 refunds 裡 close_type=2 且 success 的
 *   - 「淨額」= 成功金額 - 退款金額，這才是實際應入帳的數字
 *   - pending 單獨列出：這些是狀態未定的交易，對帳差異多半出在這裡，
 *     要用 query.php 補正後再對一次
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/pagination.php';
admin_require_login();

$conn = db_connect();
db_create_app_settings_table_if_not_exists($conn);

$allowedPerPage = array(25, 50, 100);
$perPage = admin_resolve_page_size($conn, 'page_size_report', $allowedPerPage);
$sort = admin_resolve_sort();
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));

$from = isset($_GET['from']) ? trim($_GET['from']) : date('Y-m-01');
$to   = isset($_GET['to']) ? trim($_GET['to']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

/** 區間總計 */
$stmt = mysqli_prepare($conn,
    "SELECT
        COUNT(*) AS total_cnt,
        SUM(status='success') AS success_cnt,
        SUM(status='failed')  AS failed_cnt,
        SUM(status='pending') AS pending_cnt,
        COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) AS success_amt,
        COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) AS pending_amt
     FROM orders
     WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)");
mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
mysqli_stmt_execute($stmt);
$sum = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

/**
 * 退款以「退款發生的日期」歸屬，而不是原訂單日期——因為 PAYUNi 的撥款
 * 也是按退款實際發生日結算，這樣兩邊才對得起來。
 */
$stmt = mysqli_prepare($conn,
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS amt
     FROM refunds
     WHERE close_type = 2 AND status = 'success'
       AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)");
mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
mysqli_stmt_execute($stmt);
$ref = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$netAmount = (int) $sum['success_amt'] - (int) $ref['amt'];

/*
 * 逐日明細。
 *
 * 這裡不像交易紀錄那樣用 SQL 的 LIMIT/OFFSET 分頁 —— 這是按日彙總
 * （GROUP BY DATE），列數等於篩選區間的天數，本來就被起訖日期夾住，
 * 就算選一整年也才 365 列，PHP 端 array_slice 完全夠用，不需要為了
 * 分頁就把同一個彙總查詢多打一次。
 */
$dailyOrder = ($sort === 'asc') ? 'ASC' : 'DESC';
$stmt = mysqli_prepare($conn,
    "SELECT DATE(created_at) AS d,
            COUNT(*) AS total_cnt,
            SUM(status='success') AS success_cnt,
            SUM(status='failed')  AS failed_cnt,
            SUM(status='pending') AS pending_cnt,
            COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) AS success_amt
     FROM orders
     WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
     GROUP BY DATE(created_at) ORDER BY d $dailyOrder");
mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
mysqli_stmt_execute($stmt);
$dailyAll = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$totalDays = count($dailyAll);
$totalPages = max(1, (int) ceil($totalDays / $perPage));
$page = min($page, $totalPages);
$daily = array_slice($dailyAll, ($page - 1) * $perPage, $perPage);

$baseParams = array('from' => $from, 'to' => $to, 'perPage' => $perPage, 'sort' => $sort);
$qs = http_build_query($baseParams);

/** 逐日退款，跟上面的逐日交易併表顯示 */
$stmt = mysqli_prepare($conn,
    "SELECT DATE(created_at) AS d, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS amt
     FROM refunds
     WHERE close_type = 2 AND status = 'success'
       AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
     GROUP BY DATE(created_at)");
mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
mysqli_stmt_execute($stmt);
$refundByDay = array();
foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $r) {
    $refundByDay[$r['d']] = $r;
}
mysqli_stmt_close($stmt);

admin_header('對帳報表', 'report.php');
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
  <div><div class="lbl">退款筆數</div><div class="val"><?= number_format((int)$ref['cnt']) ?></div></div>
  <div><div class="lbl">退款金額</div><div class="val" style="color:#c62828">-<?= h(money($ref['amt'])) ?></div></div>
  <div><div class="lbl">淨額（應入帳）</div><div class="val" style="color:#2e7d32"><?= h(money($netAmount)) ?></div></div>
</div>

<?php if ((int)$sum['pending_cnt'] > 0): ?>
<div class="card" style="background:#fff3e0">
  <strong>注意：這個區間有 <?= number_format((int)$sum['pending_cnt']) ?> 筆狀態未定（處理中）的交易，
  金額合計 <?= h(money($sum['pending_amt'])) ?>。</strong>
  <div class="muted" style="margin-top:6px">
    這些交易可能已經扣款但我們還沒收到結果，是對帳差異最常見的來源。
    請先到交易紀錄頁逐筆查詢補正狀態，再進行對帳。
  </div>
  <div style="margin-top:10px">
    <a class="btn2" href="index.php?from=<?= h($from) ?>&to=<?= h($to) ?>&status=pending">查看這些交易</a>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="muted">
    失敗 <?= number_format((int)$sum['failed_cnt']) ?> 筆（失敗不影響帳務，僅供參考授權成功率）．
    授權成功率
    <?= (int)$sum['total_cnt'] > 0
        ? number_format((int)$sum['success_cnt'] / (int)$sum['total_cnt'] * 100, 1) . '%'
        : '—' ?>
  </div>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <?php admin_render_pager($page, $totalPages, $qs); ?>
    <?php admin_render_page_size_switcher($allowedPerPage, $perPage, $baseParams); ?>
  </div>
</div>

<div class="card wrap">
  <table>
    <thead>
      <tr>
        <th><?php admin_sortable_header('日期', $sort, $baseParams); ?></th>
        <th class="right">成功筆數</th><th class="right">成功金額</th>
        <th class="right">退款筆數</th><th class="right">退款金額</th>
        <th class="right">淨額</th>
        <th class="right">失敗</th><th class="right">處理中</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$daily): ?>
        <tr><td colspan="8" class="muted">這個區間沒有交易紀錄</td></tr>
      <?php endif; ?>
      <?php foreach ($daily as $d):
          $rf = isset($refundByDay[$d['d']]) ? $refundByDay[$d['d']] : array('cnt' => 0, 'amt' => 0);
          $net = (int)$d['success_amt'] - (int)$rf['amt'];
      ?>
      <tr>
        <td><?= h($d['d']) ?></td>
        <td class="right"><?= number_format((int)$d['success_cnt']) ?></td>
        <td class="right"><?= h(money($d['success_amt'])) ?></td>
        <td class="right"><?= number_format((int)$rf['cnt']) ?></td>
        <td class="right"><?= (int)$rf['amt'] > 0 ? '-' . h(money($rf['amt'])) : '—' ?></td>
        <td class="right"><strong><?= h(money($net)) ?></strong></td>
        <td class="right"><?= number_format((int)$d['failed_cnt']) ?></td>
        <td class="right">
          <?= (int)$d['pending_cnt'] > 0
              ? '<span class="badge s-pending">' . number_format((int)$d['pending_cnt']) . '</span>'
              : '—' ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <?php admin_render_pager($page, $totalPages, $qs); ?>
</div>

<div class="card muted">
  對帳說明：退款以「退款實際發生日」歸屬，與 PAYUNi 的撥款結算方式一致。
  若與 PAYUNi 後台數字有差異，優先檢查上方的「處理中」交易。
</div>

<?php admin_footer(); ?>
