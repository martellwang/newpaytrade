<?php
/**
 * 客戶後台 — 設備（唯讀）。只列這個客戶登入過的收銀機。
 */

require_once __DIR__ . '/auth.php';
portal_require_login();
require_once __DIR__ . '/layout.php';

$conn = db_connect();
$merchantId = portal_merchant_id();

$devices = db_list_pos_devices_by_merchant($conn, $merchantId);

portal_header('設備', 'devices.php');
?>

<div class="card">
  <div class="muted">貴店登入過的收銀機。連續登入失敗被鎖定時，請洽系統服務窗口解鎖。</div>
</div>

<div class="card wrap">
  <table>
    <thead><tr><th>機型</th><th>序號</th><th>狀態</th><th>最後使用</th></tr></thead>
    <tbody>
      <?php if (!$devices): ?>
        <tr><td colspan="4" class="muted">還沒有收銀機登入過。</td></tr>
      <?php endif; ?>
      <?php foreach ($devices as $dv): ?>
      <tr>
        <td>
          <strong><?= h(trim(($dv['brand'] ?: '') . ' ' . ($dv['model'] ?: '')) ?: '未知機型') ?></strong>
          <?php if (!empty($dv['name'])): ?><span class="muted">（<?= h($dv['name']) ?>）</span><?php endif; ?>
        </td>
        <td class="muted"><?= h($dv['serial_no'] ?: $dv['device_id']) ?></td>
        <td><?= (int) $dv['locked'] === 1
              ? '<span class="badge s-failed">已鎖定</span>'
              : '<span class="badge s-success">正常</span>' ?></td>
        <td class="muted"><?= h($dv['last_used_at'] ?: '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php portal_footer(); ?>
