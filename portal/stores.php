<?php
/**
 * 客戶後台 — 商店與店員（唯讀）。只列自己的商店與其店員。
 *
 * 目前先做「看得到」。之後若確認客戶可自助編輯，再加新增/編輯（那時
 * 一樣要用 session merchant_id 確認商店屬於自己才放行）。
 */

require_once __DIR__ . '/auth.php';
portal_require_login();
require_once __DIR__ . '/layout.php';

$conn = db_connect();
$merchantId = portal_merchant_id();

$stores = db_list_stores($conn, $merchantId);

portal_header('商店與店員', 'stores.php');
?>

<div class="card">
  <div class="muted">
    以下是貴店的商店與店員。收銀機登入用「商店代號 + 工號 + PIN」或「商店代號 + 感應卡」。
    要新增或修改商店/店員，目前請洽系統服務窗口。
  </div>
</div>

<?php if (!$stores): ?>
<div class="card muted">尚未建立商店。</div>
<?php endif; ?>

<?php foreach ($stores as $st):
  $staff = db_list_staff($conn, (int) $st['id']);
?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <h3 style="margin:0;font-size:16px"><?= h($st['name']) ?></h3>
    <div>
      <?php if (!empty($st['store_code'])): ?>
        商店代號 <strong style="letter-spacing:1px"><?= h($st['store_code']) ?></strong>
      <?php else: ?>
        <span class="badge s-pending">商店代號未指派</span>
      <?php endif; ?>
      <?= (int) $st['enabled'] === 1
          ? '<span class="badge s-success" style="margin-left:8px">啟用</span>'
          : '<span class="badge s-failed" style="margin-left:8px">停用</span>' ?>
    </div>
  </div>

  <div class="wrap" style="margin-top:12px">
    <table>
      <thead><tr><th>店員</th><th>工號</th><th>感應卡</th><th>退款權限</th><th>建檔權限</th><th>狀態</th></tr></thead>
      <tbody>
        <?php if (!$staff): ?>
          <tr><td colspan="6" class="muted">這家店還沒有店員。可在收銀機的「交班 → 登記／綁定感應卡」新增。</td></tr>
        <?php endif; ?>
        <?php foreach ($staff as $s): ?>
        <tr>
          <td><strong><?= h($s['name']) ?></strong></td>
          <td><?= h($s['staff_code']) ?></td>
          <td><?= !empty($s['card_uid']) ? '已綁定' : '<span class="muted">未綁</span>' ?></td>
          <td><?= (int) $s['can_refund'] === 1 ? '是' : '否' ?></td>
          <td><?= (int) $s['can_enroll'] === 1 ? '是' : '否' ?></td>
          <td><?= (int) $s['active'] === 1
                ? '<span class="badge s-success">啟用</span>'
                : '<span class="badge s-failed">停用</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<?php portal_footer(); ?>
