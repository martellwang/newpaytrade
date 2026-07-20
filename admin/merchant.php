<?php
/**
 * 商店開通狀態總覽。
 *
 * 資料來自 PAYUNi 的代理商 API（merchant_status.php 會查並快取 6 小時）。
 *
 * 用途不只是「看一下」：要開新的支付方式（例如街口支付）之前，先看這裡
 * 就知道要不要先向 PAYUNi 申請 —— 不然做完才發現沒開通，等於白做。
 * 分期期數也一樣，收銀 App 就是靠這份資料把沒開通的期數在畫面上停用。
 */

require_once __DIR__ . '/auth.php';
admin_require_login();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/layout.php';

$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

/*
 * 直接呼叫自己的 merchant_status.php 而不是把查詢邏輯複製一份過來。
 * 複製的話兩邊會慢慢長歪 —— 快取策略、錯誤處理、欄位對應都得同步維護。
 */
$url = PUBLIC_BASE_URL . '/merchant_status.php' . ($forceRefresh ? '?refresh=1' : '');
$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => '',
    CURLOPT_HTTPHEADER => array('X-API-Key: ' . BACKEND_API_KEY),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
));
$raw = curl_exec($ch);
curl_close($ch);
$data = json_decode($raw, true);

$instLabels = array(
    1 => '一次付清', 3 => '3 期', 6 => '6 期', 9 => '9 期',
    12 => '12 期', 18 => '18 期', 24 => '24 期', 30 => '30 期',
);
$methodLabels = array(
    'credit' => '信用卡 一次付清（國內卡）',
    'foreignCard' => '信用卡 一次付清（國外卡）',
    'unionPay' => '銀聯卡',
    'applePay' => 'Apple Pay',
    'googlePay' => 'Google Pay',
    'samsungPay' => 'Samsung Pay',
    'jkoPay' => '街口支付',
    'cvs' => '超商代碼',
    'atm' => 'ATM 轉帳',
);

function badge($on) {
    return $on
        ? '<span style="color:#2e7d32;font-weight:bold">✅ 已開通</span>'
        : '<span style="color:#999">✕ 未開通</span>';
}

admin_header('商店狀態', 'merchant.php');
?>

<h2>商店開通狀態</h2>

<?php if (!is_array($data) || !isset($data['status'])): ?>
  <p style="color:#c62828">無法取得資料，請稍後再試。</p>

<?php elseif ($data['status'] !== 'success'): ?>
  <div style="border:1px solid #ffcc80;background:#fff8e1;border-radius:8px;padding:16px">
    <p style="margin:0 0 8px"><strong>目前查不到商店開通狀態</strong></p>
    <p style="margin:0 0 8px"><?= h(isset($data['message']) ? $data['message'] : '') ?></p>
    <p style="margin:0;color:#666;font-size:13px">
      收銀機在這種狀況下會讓所有分期期數都可選 —— 不會因為查不到狀態就無法收款，
      但收銀員可能選到沒開通的期數而在客人面前被拒。
    </p>
  </div>

<?php else: ?>
  <p style="color:#666;font-size:13px">
    商店代號 <?= h($data['merId']) ?>
    ／狀態：<strong><?= h($data['merStatusText'] ?: $data['merStatus']) ?></strong>
    <?php if (!empty($data['cached'])): ?>
      ／資料時間 <?= h(isset($data['fetchedAt']) ? $data['fetchedAt'] : '') ?>
      <?= !empty($data['stale']) ? '（查詢失敗，顯示舊資料）' : '' ?>
    <?php else: ?>
      ／剛剛更新
    <?php endif; ?>
    <a href="?refresh=1" style="margin-left:8px">重新查詢</a>
  </p>

  <?php if (isset($data['merStatus']) && $data['merStatus'] !== '1'): ?>
    <div style="border:1px solid #ef9a9a;background:#ffebee;border-radius:8px;padding:14px;margin-bottom:16px">
      <strong style="color:#c62828">這個商店代號目前不是啟用狀態，可能無法收款。</strong>
    </div>
  <?php endif; ?>

  <h3>信用卡分期</h3>
  <p style="color:#666;font-size:13px;margin-top:0">
    收銀機的期數選單會依這裡的狀態把未開通的期數變成灰色、不可點選。
  </p>
  <table style="border-collapse:collapse;margin-bottom:24px">
    <?php foreach ($instLabels as $term => $label): ?>
      <tr>
        <td style="padding:6px 24px 6px 0;border-bottom:1px solid #eee"><?= h($label) ?></td>
        <td style="padding:6px 0;border-bottom:1px solid #eee">
          <?= badge(!empty($data['available'][(string) $term])) ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <h3>其他支付工具</h3>
  <p style="color:#666;font-size:13px;margin-top:0">
    要開發新的支付方式之前先看這裡 —— 沒開通的話要先向 PAYUNi 申請，
    否則做完才發現不能用。
  </p>
  <table style="border-collapse:collapse">
    <?php foreach ($methodLabels as $key => $label): ?>
      <tr>
        <td style="padding:6px 24px 6px 0;border-bottom:1px solid #eee"><?= h($label) ?></td>
        <td style="padding:6px 0;border-bottom:1px solid #eee">
          <?= badge(!empty($data['methods'][$key])) ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<?php admin_footer(); ?>
