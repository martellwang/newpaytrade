<?php
/** 交易紀錄查詢：可依日期區間、狀態、訂單編號／卡號末四碼篩選 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/pagination.php';
admin_require_login();

$conn = db_connect();
db_create_app_settings_table_if_not_exists($conn);

// ---- 篩選條件 ----
$from    = isset($_GET['from']) ? trim($_GET['from']) : date('Y-m-d', strtotime('-7 days'));
$to      = isset($_GET['to']) ? trim($_GET['to']) : date('Y-m-d');
$status  = isset($_GET['status']) ? trim($_GET['status']) : '';
$keyword = isset($_GET['q']) ? trim($_GET['q']) : '';
$page    = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));

$allowedPerPage = array(25, 50, 100);
$perPage = admin_resolve_page_size($conn, 'page_size_orders', $allowedPerPage);
$sort = admin_resolve_sort();

// 日期格式異常就退回預設值，避免帶進查詢造成錯誤
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

// 全部用 prepared statement 綁定，不把使用者輸入拼進 SQL
// 加了別名 o（下面 JOIN merchants/merchant_stores），created_at 會歧義，要限定 o.
$where = array('o.created_at >= ?', 'o.created_at < DATE_ADD(?, INTERVAL 1 DAY)');
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

/*
 * 總筆數與金額合計。
 *
 * 合計是**整個篩選結果**的，不是當頁的 —— 對帳時要看的是「這段期間收了
 * 多少」，只算當頁 20 筆沒有意義，還會讓人誤以為那就是全部。
 *
 * 成功金額另外算：失敗的交易沒有收到錢，混在一起加總會虛報營收。
 */
$stmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS c,
            COALESCE(SUM(amount), 0) AS total_amount,
            COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) AS success_amount,
            COALESCE(SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END), 0) AS success_count
     FROM orders o WHERE $whereSql"
);
mysqli_stmt_bind_param($stmt, $types, ...$args);
mysqli_stmt_execute($stmt);
$summary = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$total = (int) $summary['c'];
$successAmount = (int) $summary['success_amount'];
$successCount = (int) $summary['success_count'];
mysqli_stmt_close($stmt);

$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$orderSql = ($sort === 'asc') ? 'o.id ASC' : 'o.id DESC';
$stmt = mysqli_prepare(
    $conn,
    "SELECT o.id, o.mer_trade_no, o.amount, o.status, o.payuni_trade_no, o.auth_code, o.card4_no,
            o.card6_no, o.card_bank, o.user_ip, o.store_order_no,
            o.message, o.created_at, o.card_inst, o.provider, o.payment_method, o.raw_response,
            m.name AS merchant_name, m.customer_code, s.store_code, s.name AS store_name
     FROM orders o
     LEFT JOIN merchants m ON m.id = o.merchant_id
     LEFT JOIN merchant_stores s ON s.id = o.store_id
     WHERE $whereSql ORDER BY $orderSql LIMIT ? OFFSET ?"
);
mysqli_stmt_bind_param($stmt, $types . 'ii', ...array_merge($args, array($perPage, $offset)));
mysqli_stmt_execute($stmt);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

/*
 * 這一頁各筆的已退款金額。
 *
 * 用一次 IN 查詢而不是每列查一次 —— 後者是典型的 N+1，20 筆就是 20 次
 * 往返資料庫，筆數一多會明顯拖慢頁面。
 */
$refundedByOrder = array();
if ($rows) {
    $nos = array_column($rows, 'mer_trade_no');
    $placeholders = implode(',', array_fill(0, count($nos), '?'));
    $stmt = mysqli_prepare(
        $conn,
        "SELECT mer_trade_no, COALESCE(SUM(amount),0) AS refunded
         FROM refunds
         WHERE status = 'success' AND close_type IN (2, -1)
           AND mer_trade_no IN ($placeholders)
         GROUP BY mer_trade_no"
    );
    mysqli_stmt_bind_param($stmt, str_repeat('s', count($nos)), ...$nos);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $refundedByOrder[$row['mer_trade_no']] = (int) $row['refunded'];
    }
    mysqli_stmt_close($stmt);
}

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

// 不分頁碼的篩選參數，給每頁筆數切換與排序表頭當底
$baseParams = array('from' => $from, 'to' => $to, 'status' => $status,
    'q' => $keyword, 'perPage' => $perPage, 'sort' => $sort);
// 含 perPage/sort、不含 page 的查詢字串，分頁上一頁/下一頁連結用這個
$qs = http_build_query($baseParams);

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
  <div style="display:flex;gap:32px;flex-wrap:wrap;align-items:baseline">
    <div>
      <div class="muted" style="font-size:12px">總筆數</div>
      <div style="font-size:20px"><?= number_format($total) ?> 筆</div>
    </div>
    <div>
      <div class="muted" style="font-size:12px">成功交易</div>
      <div style="font-size:20px;color:#2e7d32">
        <?= h(money($successAmount)) ?>
        <span class="muted" style="font-size:13px">（<?= number_format($successCount) ?> 筆）</span>
      </div>
    </div>
    <?php if ($refundedTotal > 0): ?>
    <div>
      <div class="muted" style="font-size:12px">已退款</div>
      <div style="font-size:20px;color:#c62828">－<?= h(money($refundedTotal)) ?></div>
    </div>
    <div>
      <!-- 淨額才是實際入帳的錢，對帳時看的是這個數字 -->
      <div class="muted" style="font-size:12px">淨額</div>
      <div style="font-size:20px;font-weight:bold">
        <?= h(money($successAmount - $refundedTotal)) ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <div class="muted" style="font-size:12px;margin-top:10px">
    金額為整個篩選結果的合計，不只當頁。失敗的交易不計入成功金額。
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
        <th><?php admin_sortable_header('序號', $sort, $baseParams); ?></th>
        <th>時間</th><th>訂單編號</th><th>會員</th><th>商店代號</th><th class="right">金額</th><th>狀態</th>
        <th>付款方式</th><th>上游</th><th>卡號</th><th>收單銀行</th><th>授權碼</th><th>交易序號</th><th>交易IP</th><th>訊息</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="16" class="muted">這個區間沒有交易紀錄</td></tr>
      <?php endif; ?>
      <?php $seq = $offset; ?>
      <?php foreach ($rows as $r): ?>
      <?php $seq++; ?>
      <tr>
        <td class="muted"><?= $seq ?></td>
        <td><?= h($r['created_at']) ?></td>
        <td>
          <?= h($r['mer_trade_no']) ?>
          <?php if (!empty($r['store_order_no'])): ?>
            <div class="muted" style="font-size:12px">商店單號 <?= h($r['store_order_no']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <?= h($r['merchant_name'] ?: '—') ?>
          <?php if (!empty($r['customer_code'])): ?>
            <div class="muted" style="font-size:12px"><?= h($r['customer_code']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <strong style="letter-spacing:1px"><?= h($r['store_code'] ?: '—') ?></strong>
          <?php if (!empty($r['store_name'])): ?>
            <div class="muted" style="font-size:12px"><?= h($r['store_name']) ?></div>
          <?php endif; ?>
        </td>
        <td class="right">
          <?= h(money($r['amount'])) ?>
          <?php if (isset($r['card_inst']) && (int) $r['card_inst'] > 1): ?>
            <div class="muted" style="font-size:12px">分期 <?= h((int) $r['card_inst']) ?> 期</div>
          <?php endif; ?>
        </td>
        <td>
          <?= status_badge($r['status']) ?>
          <?php
          // 已退款／已取消請款要在列表上就看得出來，不用點進明細。
          // 對帳時最常問的問題就是「這筆到底退了沒」。
          $refunded = isset($refundedByOrder[$r['mer_trade_no']])
              ? $refundedByOrder[$r['mer_trade_no']] : 0;
          if ($refunded > 0):
              $full = $refunded >= (int) $r['amount'];
          ?>
            <div style="font-size:12px;color:#c62828;margin-top:3px">
              <?= $full ? '已全額退款' : '部分退款 ' . h(money($refunded)) ?>
            </div>
          <?php endif; ?>
        </td>
        <td><?= h(payment_method_label(isset($r['payment_method']) ? $r['payment_method'] : '', isset($r['raw_response']) ? $r['raw_response'] : null)) ?></td>
        <td class="muted"><?= h(isset($r['provider']) ? $r['provider'] : 'payuni') ?></td>
        <td>
          <?php $c6 = $r['card6_no'] ?? ''; $c4 = $r['card4_no'] ?? ''; ?>
          <?= ($c6 || $c4) ? h(($c6 ?: '******') . '…' . ($c4 ?: '****')) : '—' ?>
        </td>
        <td><?= h(bank_name(isset($r['card_bank']) ? $r['card_bank'] : '')) ?></td>
        <td><?= h($r['auth_code'] ?: '—') ?></td>
        <td><?= h($r['payuni_trade_no'] ?: '—') ?></td>
        <td class="muted"><?= h($r['user_ip'] ?: '—') ?></td>
        <td><?= h($r['message'] ?: '') ?></td>
        <td><a class="btn2" href="detail.php?merTradeNo=<?= h(urlencode($r['mer_trade_no'])) ?>">明細</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <?php admin_render_pager($page, $totalPages, $qs); ?>
</div>

<?php admin_footer(); ?>
