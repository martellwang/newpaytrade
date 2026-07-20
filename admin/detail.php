<?php
/** 單筆交易明細，含退款紀錄。也提供「向 PAYUNi 查詢最新狀態」的動作。 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
admin_require_login();

$conn = db_connect();
$merTradeNo = isset($_GET['merTradeNo']) ? trim($_GET['merTradeNo']) : '';

/*
 * 退款／取消請款的執行。
 *
 * 這個動作**會動到真錢**，所以幾個保護一個都不能少：
 *   - 只接受 POST（避免被連結或圖片標籤觸發）
 *   - 驗 CSRF token
 *   - 打自己的 refund.php 而不是直接呼叫 PAYUNi —— 那支已經包含金額上限、
 *     重複退款、分期只能全額退等所有業務規則，複製一份到這裡遲早會長歪
 *   - 執行後用 PRG（Post/Redirect/Get）導回，避免重整頁面又送一次
 */
$actionMessage = null;
$actionOk = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refund') {
    if (!admin_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $actionMessage = '安全驗證失敗，請重新整理頁面再試';
    } else {
        $postNo = isset($_POST['merTradeNo']) ? trim($_POST['merTradeNo']) : '';
        $body = array('merTradeNo' => $postNo);
        // 金額留空代表全額。分期交易後端會擋下部分退款，這裡不重複判斷。
        if (isset($_POST['amount']) && $_POST['amount'] !== '') {
            $body['amount'] = (int) $_POST['amount'];
        }

        $ch = curl_init(PUBLIC_BASE_URL . '/refund.php');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'X-API-Key: ' . BACKEND_API_KEY,
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 40,
        ));
        $resp = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($resp, true);

        if (is_array($result) && isset($result['status']) && $result['status'] === 'success') {
            $actionOk = true;
            // 講清楚系統實際做了哪個動作 —— 退款和取消請款對持卡人帳單的
            // 呈現不同，操作者需要能對客人說明
            $act = isset($result['action']) ? $result['action'] : '處理';
            $actionMessage = "{$act}成功，金額 " . (isset($result['amount']) ? $result['amount'] : '') . ' 元';
        } else {
            $actionMessage = is_array($result) && isset($result['message'])
                ? $result['message'] : '處理失敗，請稍後再試';
        }
        // PRG：把結果放進 session 再導回，重整就不會重送
        $_SESSION['detail_flash'] = array('ok' => $actionOk, 'msg' => $actionMessage);
        header('Location: detail.php?merTradeNo=' . urlencode($postNo));
        exit;
    }
}

if (!empty($_SESSION['detail_flash'])) {
    $actionOk = $_SESSION['detail_flash']['ok'];
    $actionMessage = $_SESSION['detail_flash']['msg'];
    unset($_SESSION['detail_flash']);
}

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

<?php if ($actionMessage): ?>
<div class="card" style="background:<?= $actionOk ? '#e8f5e9' : '#ffebee' ?>">
  <strong><?= h($actionMessage) ?></strong>
</div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;font-size:18px">
    <?= h($order['mer_trade_no']) ?> <?= status_badge($order['status']) ?>
  </h2>
  <div class="wrap">
    <table>
      <tr><th style="width:180px">金額</th><td><?= h(money($order['amount'])) ?></td></tr>
      <tr><th>付款方式</th><td>
        <?php $inst = isset($order['card_inst']) ? (int) $order['card_inst'] : 1; ?>
        <?php if ($inst > 1): ?>
          <strong>分期 <?= h($inst) ?> 期</strong>
          <span class="muted">（分期交易只能全額退款）</span>
        <?php else: ?>
          一次付清
        <?php endif; ?>
      </td></tr>
      <tr><th>上游</th><td><?= h(isset($order['provider']) ? $order['provider'] : 'payuni') ?></td></tr>
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
      <tr><th>刷卡機</th><td>
        <?php
        $dev = !empty($order['device_id']) ? db_find_device($conn, $order['device_id']) : null;
        if ($dev) {
            echo h(trim(($dev['terminal_uid'] ? $dev['terminal_uid'] . ' ' : '')
                . ($dev['brand'] ?: '') . ' ' . ($dev['model'] ?: '')));
            if ($dev['name']) echo ' <span class="muted">(' . h($dev['name']) . ')</span>';
        } elseif (!empty($order['device_serial'])) {
            // 裝置登記被刪掉了，但交易當下的序號快照還在
            echo h($order['device_serial']) . ' <span class="muted">(此機器已無登記資料)</span>';
        } else {
            echo '—';
        }
        ?>
        <?php if (!empty($order['device_serial'])): ?>
          <div class="muted" style="font-size:12px">序號 <?= h($order['device_serial']) ?></div>
        <?php endif; ?>
      </td></tr>
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

<?php
/*
 * 退款／取消請款的操作區。
 *
 * 只對已成功、且還有餘額可退的交易顯示。失敗或已全額退掉的交易沒有東西
 * 可退，把按鈕露出來只會讓人按了拿到錯誤訊息。
 */
$remaining = (int) $order['amount'] - $refundedTotal;
$canRefund = $order['status'] === 'success' && $remaining > 0;
$isInstallment = isset($order['card_inst']) && (int) $order['card_inst'] > 1;
?>
<?php if ($canRefund): ?>
<div class="card" style="border:1px solid #ffcc80">
  <h3 style="margin-top:0;font-size:16px">退款 / 取消請款</h3>

  <div class="muted" style="margin-bottom:12px;line-height:1.7">
    系統會先向 PAYUNi 查詢這筆的請款狀態，再自動選擇正確的動作：<br>
    <strong>尚未請款</strong> → 取消請款。款項從頭到尾不會撥付，持卡人帳單上
    通常只會看到一筆很快消失的預授權。<br>
    <strong>已請款</strong> → 退款。持卡人帳單會出現扣款與退款兩筆紀錄。
  </div>

  <?php if ($isInstallment): ?>
    <div style="background:#fff3e0;padding:10px;border-radius:6px;margin-bottom:12px">
      這是 <strong><?= h((int) $order['card_inst']) ?> 期分期</strong>交易，
      依規定<strong>只能全額退款</strong>（<?= h(money($order['amount'])) ?>），不能部分退。
    </div>
  <?php endif; ?>

  <form method="post"
        onsubmit="return confirm('確定要執行嗎？這個動作會動到實際款項，且無法復原。');">
    <input type="hidden" name="action" value="refund">
    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
    <input type="hidden" name="merTradeNo" value="<?= h($order['mer_trade_no']) ?>">

    <?php if (!$isInstallment): ?>
      <label style="display:block;margin-bottom:8px">
        金額
        <input type="number" name="amount" min="1" max="<?= h($remaining) ?>"
               placeholder="<?= h($remaining) ?>"
               style="padding:6px;width:120px">
        <span class="muted">留空 = 全額 <?= h(money($remaining)) ?></span>
      </label>
    <?php endif; ?>

    <button type="submit"
            style="background:#c62828;color:#fff;border:0;padding:10px 20px;
                   border-radius:6px;cursor:pointer;font-size:15px">
      執行退款 / 取消請款
    </button>
  </form>
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
