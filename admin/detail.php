<?php
/** 單筆交易明細，含退款紀錄。也提供「向 PAYUNi 查詢最新狀態」的動作。 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
admin_require_login();

$conn = db_connect();
$merTradeNo = isset($_GET['merTradeNo']) ? trim($_GET['merTradeNo']) : '';

$order = $merTradeNo !== '' ? db_find_order($conn, $merTradeNo) : null;
$refunds = $order ? db_list_refunds($conn, $merTradeNo) : array();
$refundedTotal = $order ? db_sum_refunded_amount($conn, $merTradeNo) : 0;

admin_header('交易明細', 'index.php');

if (!$order) {
    echo '<div class="card">找不到這筆交易。<a class="btn2" href="index.php">回列表</a></div>';
    admin_footer();
    exit;
}
?>

<div class="card">
  <a class="btn2" href="index.php">← 回列表</a>
</div>

<div class="card">
  <h2 style="margin-top:0;font-size:18px">
    <?= h($order['mer_trade_no']) ?> <?= status_badge($order['status']) ?>
  </h2>
  <div class="wrap">
    <table>
      <tr><th style="width:180px">金額</th><td><?= h(money($order['amount'])) ?></td></tr>
      <tr><th>已退款</th><td>
        <?= $refundedTotal > 0 ? h(money($refundedTotal)) : '—' ?>
        <?php if ($refundedTotal > 0): ?>
          <span class="muted">（剩餘可退 <?= h(money((int)$order['amount'] - $refundedTotal)) ?>）</span>
        <?php endif; ?>
      </td></tr>
      <tr><th>PAYUNi 交易序號</th><td><?= h($order['payuni_trade_no'] ?: '—') ?></td></tr>
      <tr><th>授權碼</th><td><?= h($order['auth_code'] ?: '—') ?></td></tr>
      <tr><th>卡號末四碼</th><td><?= h($order['card4_no'] ?: '—') ?></td></tr>
      <tr><th>訊息</th><td><?= h($order['message'] ?: '—') ?></td></tr>
      <tr><th>建立時間</th><td><?= h($order['created_at']) ?></td></tr>
      <tr><th>最後更新</th><td><?= h($order['updated_at']) ?></td></tr>
    </table>
  </div>
</div>

<?php if ($refunds): ?>
<div class="card wrap">
  <h3 style="margin-top:0;font-size:16px">退款紀錄</h3>
  <table>
    <thead><tr><th>時間</th><th>類型</th><th class="right">金額</th><th>狀態</th><th>訊息</th></tr></thead>
    <tbody>
      <?php
      $closeTypes = array(1 => '請款', 2 => '退款', -1 => '取消請款', -2 => '取消退款');
      foreach ($refunds as $r): ?>
      <tr>
        <td><?= h($r['created_at']) ?></td>
        <td><?= h(isset($closeTypes[(int)$r['close_type']]) ? $closeTypes[(int)$r['close_type']] : $r['close_type']) ?></td>
        <td class="right"><?= h(money($r['amount'])) ?></td>
        <td><?= status_badge($r['status']) ?></td>
        <td><?= h($r['message'] ?: '') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($order['status'] === 'pending'): ?>
<div class="card" style="background:#fff3e0">
  <strong>這筆交易狀態未定</strong>
  <div class="muted" style="margin-top:6px">
    可能已經扣款但我們沒收到結果。可用下方指令向 PAYUNi 查詢真實狀態並自動補正
    （查詢是唯讀動作，不會影響交易）：
  </div>
  <pre style="background:#fff;padding:10px;border-radius:6px;overflow-x:auto;font-size:12px">curl "<?= h(PUBLIC_BASE_URL) ?>/query.php?merTradeNo=<?= h(urlencode($order['mer_trade_no'])) ?>" -H "X-API-Key: 你的 BACKEND_API_KEY"</pre>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
