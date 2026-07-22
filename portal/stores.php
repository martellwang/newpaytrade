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
$myStoreIds = array_map('intval', array_column($stores, 'id'));

// ── 商店列印 logo 上傳／移除 ──────────────────────────────────
// 資料隔離：只能改「自己名下」的商店（store_id 必須在 $myStoreIds 內）。
$flash = null; $flashOk = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portal_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $flash = '表單已逾時，請重新操作';
    } else {
        $sid = (int) (isset($_POST['store_id']) ? $_POST['store_id'] : 0);
        if (!in_array($sid, $myStoreIds, true)) {
            $flash = '找不到這家商店';
        } elseif ((isset($_POST['action']) ? $_POST['action'] : '') === 'remove_logo') {
            db_save_store_logo($conn, $sid, null);
            $flashOk = true; $flash = '已移除列印 logo';
        } elseif ((isset($_POST['action']) ? $_POST['action'] : '') === 'set_print_options') {
            $stub = isset($_POST['print_merchant_copy']) && $_POST['print_merchant_copy'] === '1';
            $qr = isset($_POST['print_refund_qr']) && $_POST['print_refund_qr'] === '1';
            $scan = isset($_POST['print_scan_pay']) && $_POST['print_scan_pay'] === '1';
            $torch = isset($_POST['scan_torch']) && $_POST['scan_torch'] === '1';
            db_save_store_print_merchant_copy($conn, $sid, $stub);
            db_save_store_print_refund_qr($conn, $sid, $qr);
            db_save_store_print_scan_pay($conn, $sid, $scan);
            db_save_store_scan_torch($conn, $sid, $torch);
            $flashOk = true; $flash = '已更新列印設定';
        } elseif (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            $flash = '請選擇要上傳的圖檔';
        } elseif ($_FILES['logo']['size'] > 300 * 1024) {
            $flash = 'logo 圖檔請小於 300KB（列印用不需要太大）';
        } else {
            $info = @getimagesize($_FILES['logo']['tmp_name']);
            $allowed = array('image/png' => 1, 'image/jpeg' => 1, 'image/gif' => 1);
            if (!$info || !isset($allowed[$info['mime']])) {
                $flash = '只接受 PNG／JPG／GIF 圖檔';
            } else {
                $bytes = file_get_contents($_FILES['logo']['tmp_name']);
                $dataUri = 'data:' . $info['mime'] . ';base64,' . base64_encode($bytes);
                db_save_store_logo($conn, $sid, $dataUri);
                $flashOk = true; $flash = '已更新列印 logo';
            }
        }
    }
}

portal_header('商店與店員', 'stores.php');
?>

<?php if ($flash): ?>
  <div class="card" style="border-left:4px solid <?= $flashOk ? '#2e7d32' : '#c62828' ?>"><?= h($flash) ?></div>
<?php endif; ?>

<div class="card">
  <div class="muted">
    以下是貴店的商店與店員。收銀機登入用「商店代號 + 工號 + PIN」或「商店代號 + 感應卡」。
    要新增或修改商店/店員，目前請洽系統服務窗口。<br>
    每家商店可上傳一張列印 logo（印在刷卡簽單最上方），建議小於 300KB 的 PNG。
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

  <?php $logo = db_get_store_logo($conn, (int) $st['id']); ?>
  <div style="display:flex;gap:16px;align-items:center;margin-top:12px;flex-wrap:wrap;
              padding:12px;background:#fafafa;border-radius:8px">
    <div style="min-width:120px">
      <?php if ($logo): ?>
        <img src="<?= h($logo) ?>" alt="列印 logo"
             style="max-height:64px;max-width:200px;border:1px solid #eee;border-radius:6px;padding:4px;background:#fff">
      <?php else: ?>
        <span class="muted">尚未上傳列印 logo</span>
      <?php endif; ?>
    </div>
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="csrf" value="<?= h(portal_csrf_token()) ?>">
      <input type="hidden" name="store_id" value="<?= (int) $st['id'] ?>">
      <input type="file" name="logo" accept="image/png,image/jpeg,image/gif" required>
      <button type="submit">上傳列印 logo</button>
    </form>
    <?php if ($logo): ?>
    <form method="post" onsubmit="return confirm('確定移除這家店的列印 logo？')">
      <input type="hidden" name="csrf" value="<?= h(portal_csrf_token()) ?>">
      <input type="hidden" name="store_id" value="<?= (int) $st['id'] ?>">
      <button type="submit" name="action" value="remove_logo" class="btn2">移除 logo</button>
    </form>
    <?php endif; ?>
  </div>

  <?php
    $printStub = db_get_store_print_merchant_copy($conn, (int) $st['id']);
    $printQr = db_get_store_print_refund_qr($conn, (int) $st['id']);
    $printScan = db_get_store_print_scan_pay($conn, (int) $st['id']);
    $scanTorch = db_get_store_scan_torch($conn, (int) $st['id']);
  ?>
  <form method="post" style="margin-top:10px">
    <input type="hidden" name="csrf" value="<?= h(portal_csrf_token()) ?>">
    <input type="hidden" name="store_id" value="<?= (int) $st['id'] ?>">
    <input type="hidden" name="action" value="set_print_options">
    <div style="display:flex;gap:6px;align-items:center;font-size:14px">
      <label style="display:flex;gap:6px;align-items:center">
        <input type="checkbox" name="print_merchant_copy" value="1" <?= $printStub ? 'checked' : '' ?>
               onchange="this.form.submit()">
        列印<strong>存根聯</strong>（店家留存的那一聯）
      </label>
      <span class="muted" style="font-size:12px">關掉就不印存根聯；收執聯由店員現場問客人要不要。</span>
    </div>
    <div style="display:flex;gap:6px;align-items:center;font-size:14px;margin-top:6px">
      <label style="display:flex;gap:6px;align-items:center">
        <input type="checkbox" name="print_refund_qr" value="1" <?= $printQr ? 'checked' : '' ?>
               onchange="this.form.submit()">
        收執聯印<strong>掃碼退款 QR</strong>
      </label>
      <span class="muted" style="font-size:12px">不喜歡現場退款、偏好由後台退的商店可關掉。</span>
    </div>
    <div style="display:flex;gap:6px;align-items:center;font-size:14px;margin-top:6px">
      <label style="display:flex;gap:6px;align-items:center">
        <input type="checkbox" name="print_scan_pay" value="1" <?= $printScan ? 'checked' : '' ?>
               onchange="this.form.submit()">
        <strong>掃碼收款</strong>也列印簽單
      </label>
      <span class="muted" style="font-size:12px">預設關閉。開啟後 LINE Pay／行動支付收款也會依上面的存根聯／收執聯設定列印。</span>
    </div>
    <div style="display:flex;gap:6px;align-items:center;font-size:14px;margin-top:6px">
      <label style="display:flex;gap:6px;align-items:center">
        <input type="checkbox" name="scan_torch" value="1" <?= $scanTorch ? 'checked' : '' ?>
               onchange="this.form.submit()">
        掃碼時開啟<strong>照明燈</strong>
      </label>
      <span class="muted" style="font-size:12px">預設開。收銀機掃退款 QR 時打開鏡頭旁的補光燈，加快辨識；環境明亮可關。</span>
    </div>
  </form>

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
