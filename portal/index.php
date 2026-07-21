<?php
/**
 * 客戶後台 — 交易紀錄。只顯示這個客戶自己的交易。
 *
 * ⚠️ 資料隔離：merchant_id 一律取自 session（portal_merchant_id），
 *    絕不從網址／表單取，否則客戶就能看到別家的交易。
 */

require_once __DIR__ . '/auth.php';
portal_require_login();
require_once __DIR__ . '/layout.php';

$conn = db_connect();
$merchantId = portal_merchant_id();   // ← 唯一的資料範圍來源

$from    = isset($_GET['from']) ? trim($_GET['from']) : date('Y-m-d', strtotime('-7 days'));
$to      = isset($_GET['to']) ? trim($_GET['to']) : date('Y-m-d');
$status  = isset($_GET['status']) ? trim($_GET['status']) : '';
$keyword = isset($_GET['q']) ? trim($_GET['q']) : '';
$page    = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
$perPage = 25;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

// merchant_id 永遠是第一個條件，且來自 session
$where = array('merchant_id = ?', 'created_at >= ?', 'created_at < DATE_ADD(?, INTERVAL 1 DAY)');
$types = 'iss';
$args  = array($merchantId, $from, $to);

if (in_array($status, array('success', 'failed', 'pending'), true)) {
    $where[] = 'status = ?'; $types .= 's'; $args[] = $status;
}
if ($keyword !== '') {
    $where[] = '(mer_trade_no LIKE ? OR payuni_trade_no LIKE ? OR card4_no = ?)';
    $types .= 'sss';
    $args[] = '%' . $keyword . '%';
    $args[] = '%' . $keyword . '%';
    $args[] = $keyword;
}
$whereSql = implode(' AND ', $where);

// 合計（整個篩選結果）
$stmt = mysqli_prepare($conn,
    "SELECT COUNT(*) AS c,
            COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) AS success_amt,
            SUM(status='success') AS success_cnt
     FROM orders WHERE $whereSql");
mysqli_stmt_bind_param($stmt, $types, ...$args);
mysqli_stmt_execute($stmt);
$sum = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$total = (int) $sum['c'];
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// 當頁明細
$sql = "SELECT mer_trade_no, amount, status, payment_method, card4_no, auth_code,
               staff_name, created_at
        FROM orders WHERE $whereSql ORDER BY id DESC LIMIT ? OFFSET ?";
$stmt = mysqli_prepare($conn, $sql);
$typesL = $types . 'ii';
$argsL = array_merge($args, array($perPage, $offset));
mysqli_stmt_bind_param($stmt, $typesL, ...$argsL);
mysqli_stmt_execute($stmt);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$methodText = array('credit' => '信用卡', 'wallet' => '行動支付', 'linepay' => 'LINE Pay');

function portal_qs($over = array()) {
    $p = array_merge($_GET, $over);
    return h(http_build_query($p));
}

portal_header('交易紀錄', 'index.php');
?>

<div class="card">
  <form class="filters" method="get">
    <div><label>起始日期</label><input type="date" name="from" value="<?= h($from) ?>"></div>
    <div><label>結束日期</label><input type="date" name="to" value="<?= h($to) ?>"></div>
    <div><label>狀態</label>
      <select name="status">
        <option value="">全部</option>
        <option value="success" <?= $status==='success'?'selected':'' ?>>成功</option>
        <option value="failed"  <?= $status==='failed'?'selected':'' ?>>失敗</option>
        <option value="pending" <?= $status==='pending'?'selected':'' ?>>處理中</option>
      </select>
    </div>
    <div><label>訂單／末四碼</label><input type="text" name="q" value="<?= h($keyword) ?>" placeholder="訂單編號或卡末四"></div>
    <div><button type="submit">查詢</button></div>
  </form>
</div>

<div class="kpi">
  <div><div class="lbl">成功筆數</div><div class="val"><?= number_format((int)$sum['success_cnt']) ?></div></div>
  <div><div class="lbl">成功金額</div><div class="val"><?= h(money($sum['success_amt'])) ?></div></div>
  <div><div class="lbl">總筆數</div><div class="val"><?= number_format($total) ?></div></div>
</div>

<div class="card wrap">
  <table>
    <thead>
      <tr><th>時間</th><th>訂單編號</th><th class="right">金額</th><th>方式</th>
          <th>狀態</th><th>卡末四</th><th>授權碼</th><th>經手店員</th></tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="muted">這個區間沒有交易紀錄</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td class="muted"><?= h($r['created_at']) ?></td>
        <td><?= h($r['mer_trade_no']) ?></td>
        <td class="right"><?= h(money($r['amount'])) ?></td>
        <td><?= h(isset($methodText[$r['payment_method']]) ? $methodText[$r['payment_method']] : $r['payment_method']) ?></td>
        <td><?= portal_status_badge($r['status']) ?></td>
        <td><?= h($r['card4_no'] ?: '—') ?></td>
        <td><?= h($r['auth_code'] ?: '—') ?></td>
        <td><?= h($r['staff_name'] ?: '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <div class="pager">
    <?php if ($page > 1): ?><a class="btn2" href="?<?= portal_qs(array('page'=>$page-1)) ?>">上一頁</a><?php endif; ?>
    <span class="muted">第 <?= $page ?> / <?= $totalPages ?> 頁（共 <?= number_format($total) ?> 筆）</span>
    <?php if ($page < $totalPages): ?><a class="btn2" href="?<?= portal_qs(array('page'=>$page+1)) ?>">下一頁</a><?php endif; ?>
  </div>
</div>

<?php portal_footer(); ?>
