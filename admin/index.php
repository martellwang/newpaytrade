<?php
/** 交易紀錄查詢：可依日期區間、狀態、訂單編號／卡號末四碼篩選 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
admin_require_login();

$conn = db_connect();

// ---- 篩選條件 ----
$from    = isset($_GET['from']) ? trim($_GET['from']) : date('Y-m-d', strtotime('-7 days'));
$to      = isset($_GET['to']) ? trim($_GET['to']) : date('Y-m-d');
$status  = isset($_GET['status']) ? trim($_GET['status']) : '';
$keyword = isset($_GET['q']) ? trim($_GET['q']) : '';
$page    = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
$perPage = 50;

// 日期格式異常就退回預設值，避免帶進查詢造成錯誤
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

// 全部用 prepared statement 綁定，不把使用者輸入拼進 SQL
$where = array('created_at >= ?', 'created_at < DATE_ADD(?, INTERVAL 1 DAY)');
$types = 'ss';
$args  = array($from, $to);

if (in_array($status, array('success', 'failed', 'pending'), true)) {
    $where[] = 'status = ?';
    $types .= 's';
    $args[] = $status;
}
if ($keyword !== '') {
    // 訂單編號、PAYUNi 交易序號、卡號末四碼、刷卡機識別碼／序號都可以查
    $where[] = '(mer_trade_no LIKE ? OR payuni_trade_no LIKE ? OR card4_no = ? OR device_id = ? OR device_serial = ?)';
    $types .= 'sssss';
    $like = '%' . $keyword . '%';
    $args[] = $like;
    $args[] = $like;
    $args[] = $keyword;
    $args[] = $keyword;
    $args[] = $keyword;
}
$whereSql = implode(' AND ', $where);

// 總筆數（算分頁用）
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM orders WHERE $whereSql");
mysqli_stmt_bind_param($stmt, $types, ...$args);
mysqli_stmt_execute($stmt);
$total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
mysqli_stmt_close($stmt);

$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = mysqli_prepare(
    $conn,
    "SELECT id, mer_trade_no, amount, status, payuni_trade_no, auth_code, card4_no,
            message, created_at
     FROM orders WHERE $whereSql ORDER BY id DESC LIMIT ? OFFSET ?"
);
mysqli_stmt_bind_param($stmt, $types . 'ii', ...array_merge($args, array($perPage, $offset)));
mysqli_stmt_execute($stmt);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// 這個區間的退款總額，讓列表頁也能看到淨額
$stmt = mysqli_prepare(
    $conn,
    "SELECT COALESCE(SUM(r.amount),0) AS refunded
     FROM refunds r JOIN orders o ON o.mer_trade_no = r.mer_trade_no
     WHERE r.close_type = 2 AND r.status = 'success'
       AND o.created_at >= ? AND o.created_at < DATE_ADD(?, INTERVAL 1 DAY)"
);
mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
mysqli_stmt_execute($stmt);
$refundedTotal = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['refunded'];
mysqli_stmt_close($stmt);

$qs = http_build_query(array('from' => $from, 'to' => $to, 'status' => $status, 'q' => $keyword));

admin_header('交易紀錄', 'index.php');
?>

<div class="card">
  <form class="filters" method="get">
    <div><label>起始日期</label><input type="date" name="from" value="<?= h($from) ?>"></div>
    <div><label>結束日期</label><input type="date" name="to" value="<?= h($to) ?>"></div>
    <div>
      <label>狀態</label>
      <select name="status">
        <option value="">全部</option>
        <?php foreach (array('success' => '成功', 'failed' => '失敗', 'pending' => '處理中') as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>訂單編號／交易序號／卡號末四碼</label>
      <input type="text" name="q" value="<?= h($keyword) ?>" placeholder="關鍵字">
    </div>
    <div><button type="submit">查詢</button></div>
    <div><a class="btn2" href="export.php?<?= h($qs) ?>">匯出 CSV</a></div>
  </form>
</div>

<div class="card">
  <div class="muted">
    共 <?= number_format($total) ?> 筆
    <?php if ($refundedTotal > 0): ?>
      ．此區間已退款 <?= h(money($refundedTotal)) ?>
    <?php endif; ?>
  </div>
</div>

<div class="card wrap">
  <table>
    <thead>
      <tr>
        <th>時間</th><th>訂單編號</th><th class="right">金額</th><th>狀態</th>
        <th>卡號末四碼</th><th>授權碼</th><th>PAYUNi 交易序號</th><th>訊息</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="muted">這個區間沒有交易紀錄</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r['created_at']) ?></td>
        <td><?= h($r['mer_trade_no']) ?></td>
        <td class="right"><?= h(money($r['amount'])) ?></td>
        <td><?= status_badge($r['status']) ?></td>
        <td><?= h($r['card4_no'] ?: '—') ?></td>
        <td><?= h($r['auth_code'] ?: '—') ?></td>
        <td><?= h($r['payuni_trade_no'] ?: '—') ?></td>
        <td><?= h($r['message'] ?: '') ?></td>
        <td><a class="btn2" href="detail.php?merTradeNo=<?= h(urlencode($r['mer_trade_no'])) ?>">明細</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="card">
  <?php if ($page > 1): ?>
    <a class="btn2" href="?<?= h($qs) ?>&page=<?= $page - 1 ?>">上一頁</a>
  <?php endif; ?>
  <span class="muted">第 <?= $page ?> / <?= $totalPages ?> 頁</span>
  <?php if ($page < $totalPages): ?>
    <a class="btn2" href="?<?= h($qs) ?>&page=<?= $page + 1 ?>">下一頁</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
