<?php
/**
 * 收銀機清單：登記過的每一台機器、能力、以及各自的交易統計。
 *
 * 這裡的「有無 NFC」欄位特別重要——實測發現專用 POS 機（Ingenico APOS A8、
 * Urovo i9100）都沒有標準 Android NFC，讀卡機是廠商專屬硬體要用各自 SDK。
 * 把結果留在資料庫，日後評估或採購機型時不用重測。
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
admin_require_login();

$conn = db_connect();
$devices = db_list_devices($conn);

admin_header('收銀機', 'devices.php');
?>

<div class="card">
  <div class="muted">
    共 <?= count($devices) ?> 台登記中的機器。每次交易時 App 會自動更新這裡的資料。
  </div>
</div>

<div class="card wrap">
  <table>
    <thead>
      <tr>
        <th>廠牌 / 型號</th>
        <th>Android</th>
        <th>感應讀卡</th>
        <th>螢幕</th>
        <th>App 版本</th>
        <th class="right">交易筆數</th>
        <th class="right">成功金額</th>
        <th>最後使用</th>
        <th>首次登記</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$devices): ?>
        <tr><td colspan="9" class="muted">
          尚無登記的機器。App 完成第一筆交易後就會自動出現在這裡。
        </td></tr>
      <?php endif; ?>
      <?php foreach ($devices as $d): ?>
      <tr>
        <td>
          <strong><?= h($d['brand'] ?: $d['manufacturer'] ?: '—') ?></strong>
          <?= h($d['model'] ?: '') ?>
          <?php if ($d['product'] && $d['product'] !== $d['model']): ?>
            <div class="muted"><?= h($d['product']) ?></div>
          <?php endif; ?>
        </td>
        <td><?= h($d['android_version'] ?: '—') ?>
            <?= $d['android_sdk'] ? '<span class="muted">(SDK ' . h($d['android_sdk']) . ')</span>' : '' ?></td>
        <td>
          <?php if ($d['has_nfc'] === null): ?>
            <span class="muted">未知</span>
          <?php elseif ((int) $d['has_nfc'] === 1): ?>
            <span class="badge s-success">支援<?= ((int) $d['nfc_enabled'] === 1) ? '' : '（未開啟）' ?></span>
          <?php else: ?>
            <span class="badge s-failed">不支援</span>
          <?php endif; ?>
        </td>
        <td class="muted"><?= h($d['screen'] ?: '—') ?></td>
        <td><?= h($d['app_version'] ?: '—') ?></td>
        <td class="right"><?= number_format((int) $d['order_cnt']) ?></td>
        <td class="right"><?= h(money($d['success_amt'])) ?></td>
        <td><?= h($d['last_seen']) ?></td>
        <td class="muted"><?= h($d['first_seen']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card muted">
  <strong>關於「感應讀卡」欄位</strong><br>
  顯示的是這台機器有沒有<strong>標準 Android NFC</strong>。
  實測結論：專用支付終端機（Ingenico APOS A8、Urovo i9100）都是「不支援」——
  它們的讀卡機是廠商專屬硬體，必須透過各自的 SDK 存取，標準 NfcAdapter 讀不到。
  顯示「不支援」的機器只能用手動輸入卡號。
</div>

<?php admin_footer(); ?>
