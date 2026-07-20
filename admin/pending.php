<?php
/**
 * 進行中交易：一次列出所有 status='pending'（尚未定案）的交易，不限日期區間。
 *
 * 這些是授權逾時、還沒收到銀行最終結果的訂單——可能已扣款，是對帳差異最常見的
 * 來源。集中在一頁看，方便逐筆用 query.php 向 PAYUNi 查詢真實狀態並補正，避免
 * 遺漏。列表刻意「最舊在前」，等最久的排最上面，因為那些最該優先處理。
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
admin_require_login();

$conn = db_connect();

// pending 正常情況數量不多，全撈；設個上限避免極端狀況下把頁面撐爆。
$LIMIT = 500;
$stmt = mysqli_prepare(
    $conn,
    "SELECT id, mer_trade_no, amount, payuni_trade_no, device_id, device_serial, created_at
     FROM orders WHERE status = 'pending' ORDER BY created_at ASC, id ASC LIMIT ?"
);
mysqli_stmt_bind_param($stmt, 'i', $LIMIT);
mysqli_stmt_execute($stmt);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// 總數與金額合計（不受 LIMIT 影響，直接在 DB 聚合）
$agg = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS amt FROM orders WHERE status = 'pending'"
));
$pendingCount = (int) $agg['c'];
$pendingAmount = (int) $agg['amt'];

/** 把「已等待多久」轉成好讀的中文，凸顯等太久的訂單 */
function pending_age($createdAt) {
    $sec = time() - strtotime($createdAt);
    if ($sec < 0) $sec = 0;
    if ($sec < 3600)   return floor($sec / 60) . ' 分鐘';
    if ($sec < 86400)  return floor($sec / 3600) . ' 小時';
    return floor($sec / 86400) . ' 天';
}

admin_header('進行中交易', 'pending.php');
?>

<div class="card">
  <div class="kpi">
    <div><div class="lbl">進行中筆數</div><div class="val"><?= number_format($pendingCount) ?></div></div>
    <div><div class="lbl">進行中金額合計</div><div class="val"><?= h(money($pendingAmount)) ?></div></div>
    <div><div class="lbl">最舊一筆已等待</div>
      <div class="val"><?= $rows ? h(pending_age($rows[0]['created_at'])) : '—' ?></div></div>
  </div>
</div>

<div class="card">
  <div class="muted">
    這些交易尚未定案，可能已扣款但我們沒收到最終結果。點「明細」可看到向 PAYUNi
    查詢並自動補正狀態的指令；查詢是唯讀動作，不會影響交易。
    <?php if ($pendingCount > $LIMIT): ?>
      <br>筆數過多，僅顯示最舊的 <?= number_format($LIMIT) ?> 筆。
    <?php endif; ?>
  </div>
</div>

<div class="card wrap">
  <table>
    <thead>
      <tr>
        <th>建立時間</th><th>已等待</th><th>訂單編號</th><th class="right">金額</th>
        <th>刷卡機</th><th>PAYUNi 交易序號</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="muted">目前沒有進行中的交易 🎉</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r['created_at']) ?></td>
        <td><?= h(pending_age($r['created_at'])) ?></td>
        <td><?= h($r['mer_trade_no']) ?></td>
        <td class="right"><?= h(money($r['amount'])) ?></td>
        <td>
          <?php
          $dev = !empty($r['device_id']) ? db_find_device($conn, $r['device_id']) : null;
          if ($dev) {
              echo h(trim(($dev['terminal_uid'] ? $dev['terminal_uid'] . ' ' : '')
                  . ($dev['brand'] ?: '') . ' ' . ($dev['model'] ?: '')));
          } elseif (!empty($r['device_serial'])) {
              echo h($r['device_serial']);
          } else {
              echo '—';
          }
          ?>
        </td>
        <td><?= h($r['payuni_trade_no'] ?: '—') ?></td>
        <td><a class="btn2" href="detail.php?merTradeNo=<?= h(urlencode($r['mer_trade_no'])) ?>">明細</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php admin_footer(); ?>
