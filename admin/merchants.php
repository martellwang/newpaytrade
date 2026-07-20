<?php
/**
 * 客戶管理，以及客戶底下的商店與收銀機。
 *
 * 層級：上游 → 經銷商 → **客戶** → 商店。
 *
 * 客戶 = 一組收銀機登入身分（客戶編號 + 帳號 + 密碼）。
 * 商店 = 一個商店代號 MerID，一個客戶可以有很多家。
 *
 * 登入用系統配發的純數字客戶編號，不用統編／身分證字號 ——
 * 後者去掉開頭字母後不保證唯一（A/M、C/I、K/L 的檢查碼貢獻相同），
 * 而且屬於敏感個資，不該進登入流程。統編仍存在客戶資料裡供開發票用。
 */

require_once __DIR__ . '/auth.php';
admin_require_login();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../providers.php';
require_once __DIR__ . '/layout.php';

$conn = db_connect();
db_create_merchants_table_if_not_exists($conn);
db_create_pos_locks_table_if_not_exists($conn);

$flash = null;
$flashOk = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $flash = '安全驗證失敗，請重新整理再試';
    } else {
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        $redirect = 'merchants.php';
        try {
            if ($action === 'save_merchant') {
                $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : 0;
                $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
                $account = trim(isset($_POST['login_account']) ? $_POST['login_account'] : '');
                $dealerId = (int) (isset($_POST['dealer_id']) ? $_POST['dealer_id'] : 0);
                $password = isset($_POST['password']) ? $_POST['password'] : '';
                $enabled = isset($_POST['enabled']) ? 1 : 0;

                if ($name === '') { throw new Exception('請填寫客戶名稱'); }
                if (!$dealerId) { throw new Exception('請選擇所屬經銷商'); }
                if (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $account)) {
                    throw new Exception('登入帳號只能是英數與 _ . @ - （3～64 字）');
                }

                $hash = null;
                if ($password !== '') {
                    if (strlen($password) < 6) { throw new Exception('密碼至少 6 個字元'); }
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                } elseif (!$id) {
                    throw new Exception('新增客戶時必須設定密碼');
                }

                // 編號只在新增時配發；建立後不可修改 —— 店家已經把它記在
                // 收銀機旁邊了，改掉等於讓所有分店突然登不進去
                $code = $id ? null : db_next_customer_code($conn);

                $newId = db_save_merchant(
                    $conn, $id, $dealerId, $code,
                    trim(isset($_POST['tax_id']) ? $_POST['tax_id'] : ''),
                    $name, $account, $hash, $enabled,
                    trim(isset($_POST['note']) ? $_POST['note'] : '')
                );

                // 改密碼或停用時撤銷所有登入，否則已登入的收銀機會繼續能用
                // 舊憑證交易，等於改了沒效
                if ($id && ($hash !== null || $enabled === 0)) {
                    db_revoke_merchant_sessions($conn, $id);
                }

                $flashOk = true;
                $flash = "已儲存客戶「{$name}」"
                    . ($code ? "，客戶編號 {$code}" : '')
                    . ($hash !== null && $id ? '（密碼已更新，所有收銀機需重新登入）' : '')
                    . ($enabled === 0 ? '（已停用）' : '');
                $redirect = 'merchants.php?edit=' . $newId;

            } elseif ($action === 'save_store') {
                $merchantId = (int) $_POST['merchant_id'];
                $storeId = isset($_POST['store_id']) && $_POST['store_id'] !== '' ? (int) $_POST['store_id'] : 0;
                $sName = trim(isset($_POST['store_name']) ? $_POST['store_name'] : '');
                $merId = trim(isset($_POST['mer_id']) ? $_POST['mer_id'] : '');
                if ($sName === '') { throw new Exception('請填寫商店名稱'); }
                if ($merId === '') { throw new Exception('請填寫商店代號 MerID'); }

                db_save_store($conn, $storeId, $merchantId, $sName, $merId,
                    trim(isset($_POST['provider']) ? $_POST['provider'] : 'payuni'),
                    isset($_POST['store_enabled']) ? 1 : 0,
                    trim(isset($_POST['store_note']) ? $_POST['store_note'] : ''));

                $flashOk = true;
                $flash = "已儲存商店「{$sName}」";
                $redirect = 'merchants.php?edit=' . $merchantId;

            } elseif ($action === 'delete_store') {
                $merchantId = (int) $_POST['merchant_id'];
                $storeId = (int) $_POST['store_id'];
                $used = db_count_orders_by_store($conn, $storeId);
                if ($used > 0) {
                    throw new Exception("無法刪除：已有 {$used} 筆交易使用這家商店。請改為停用。");
                }
                db_delete_store($conn, $storeId, $merchantId);
                $flashOk = true;
                $flash = '已刪除商店';
                $redirect = 'merchants.php?edit=' . $merchantId;

            } elseif ($action === 'unlock_device') {
                db_unlock_pos_device($conn, $_POST['device_id']);
                $flashOk = true;
                $flash = '已解鎖收銀機，店員可以重新登入';
                $redirect = 'merchants.php?edit=' . (int) $_POST['merchant_id'];

            } elseif ($action === 'logout_all') {
                $merchantId = (int) $_POST['merchant_id'];
                db_revoke_merchant_sessions($conn, $merchantId);
                $flashOk = true;
                $flash = '已登出這個客戶的所有收銀機';
                $redirect = 'merchants.php?edit=' . $merchantId;
            }
        } catch (Exception $e) {
            $flash = $e->getMessage();
        }
    }
    $_SESSION['merchants_flash'] = array('ok' => $flashOk, 'msg' => $flash);
    header('Location: ' . $redirect);
    exit;
}

if (!empty($_SESSION['merchants_flash'])) {
    $flashOk = $_SESSION['merchants_flash']['ok'];
    $flash = $_SESSION['merchants_flash']['msg'];
    unset($_SESSION['merchants_flash']);
}

$dealers = db_list_dealers($conn);
$merchants = db_list_merchants($conn);
$providers = provider_all();
$editing = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = $editing ? db_find_merchant($conn, $editing) : null;
$isNew = isset($_GET['new']);

$dealerNames = array();
foreach ($dealers as $d) { $dealerNames[(int) $d['id']] = $d['name']; }

admin_header('客戶管理', 'merchants.php');
?>

<?php if ($flash): ?>
<div class="card" style="background:<?= $flashOk ? '#e8f5e9' : '#ffebee' ?>">
  <strong><?= h($flash) ?></strong>
</div>
<?php endif; ?>

<?php if (!$dealers): ?>
<div class="card" style="background:#fff3e0">
  <strong>請先建立經銷商</strong>
  <div class="muted" style="margin-top:6px">
    每個客戶都必須歸屬於一個經銷商。自營的客戶請建一個「直營」掛上去。
    <a href="dealers.php">前往經銷商管理</a>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h2 style="margin:0;font-size:18px">客戶</h2>
    <?php if ($dealers): ?><a class="btn2" href="?new=1">＋ 新增客戶</a><?php endif; ?>
  </div>
  <div class="muted" style="margin-top:8px">
    收銀機用「客戶編號 + 帳號 + 密碼」登入，登入後選擇要以哪家商店營業。
  </div>
</div>

<div class="card wrap">
  <table>
    <thead>
      <tr><th>客戶編號</th><th>名稱</th><th>經銷商</th><th>登入帳號</th>
          <th>商店數</th><th>狀態</th><th>備註</th><th></th></tr>
    </thead>
    <tbody>
      <?php if (!$merchants): ?>
        <tr><td colspan="8" class="muted">尚未建立任何客戶</td></tr>
      <?php endif; ?>
      <?php foreach ($merchants as $m): ?>
      <tr>
        <td><strong style="font-size:15px"><?= h($m['customer_code']) ?></strong></td>
        <td><?= h($m['name']) ?></td>
        <td class="muted"><?= h(isset($dealerNames[(int) $m['dealer_id']])
              ? $dealerNames[(int) $m['dealer_id']] : '—') ?></td>
        <td><code><?= h($m['login_account']) ?></code></td>
        <td><?= count(db_list_stores($conn, (int) $m['id'])) ?> 家</td>
        <td><?= (int) $m['enabled'] === 1
              ? '<span class="badge s-success">啟用</span>'
              : '<span class="badge s-failed">停用</span>' ?></td>
        <td class="muted"><?= h($m['note'] ?: '') ?></td>
        <td><a class="btn2" href="?edit=<?= (int) $m['id'] ?>">管理</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($isNew || $editRow): ?>
<div class="card" style="border:2px solid #5a3d99">
  <h3 style="margin-top:0;font-size:17px">
    <?= $editRow ? '客戶：' . h($editRow['name']) : '新增客戶' ?>
    <?php if ($editRow): ?>
      <span class="muted" style="font-size:14px">／編號 <?= h($editRow['customer_code']) ?></span>
    <?php endif; ?>
  </h3>

  <form method="post">
    <input type="hidden" name="action" value="save_merchant">
    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $editRow ? (int) $editRow['id'] : '' ?>">

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px">
      <label>客戶名稱<br>
        <input type="text" name="name" required style="width:100%;padding:8px"
               value="<?= h($editRow ? $editRow['name'] : '') ?>">
      </label>
      <label>所屬經銷商<br>
        <select name="dealer_id" required style="width:100%;padding:8px">
          <option value="">請選擇</option>
          <?php foreach ($dealers as $d): ?>
            <option value="<?= (int) $d['id'] ?>"
              <?= ($editRow && (int) $editRow['dealer_id'] === (int) $d['id']) ? 'selected' : '' ?>>
              <?= h($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>統一編號 / 身分證字號<br>
        <input type="text" name="tax_id" style="width:100%;padding:8px"
               value="<?= h($editRow ? $editRow['tax_id'] : '') ?>">
        <span class="muted" style="font-size:12px">開發票用，不參與登入，可留空</span>
      </label>
      <label>備註<br>
        <input type="text" name="note" style="width:100%;padding:8px"
               value="<?= h($editRow ? $editRow['note'] : '') ?>">
      </label>
    </div>

    <h4 style="margin:18px 0 8px;font-size:15px">收銀機登入</h4>
    <?php if ($editRow): ?>
      <div style="background:#f4f2f8;padding:12px;border-radius:6px;margin-bottom:12px">
        店員在收銀機上要輸入：
        <strong style="font-size:16px">客戶編號 <?= h($editRow['customer_code']) ?></strong>
        ＋ 帳號 ＋ 密碼
        <div class="muted" style="font-size:12px;margin-top:4px">
          客戶編號建立後不可修改 —— 店家已經記在收銀機旁邊了。
        </div>
      </div>
    <?php endif; ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px">
      <label>登入帳號<br>
        <input type="text" name="login_account" required style="width:100%;padding:8px"
               value="<?= h($editRow ? $editRow['login_account'] : '') ?>">
        <span class="muted" style="font-size:12px">只需在這個客戶底下唯一</span>
      </label>
      <label>密碼<br>
        <input type="password" name="password" autocomplete="new-password" style="width:100%;padding:8px"
               placeholder="<?= $editRow ? '留空 = 不變更' : '至少 6 個字元' ?>"
               <?= $editRow ? '' : 'required' ?>>
        <?php if ($editRow): ?>
          <span class="muted" style="font-size:12px">變更後所有收銀機需重新登入</span>
        <?php endif; ?>
      </label>
    </div>

    <label style="display:block;margin:14px 0">
      <input type="checkbox" name="enabled" value="1"
             <?= (!$editRow || (int) $editRow['enabled'] === 1) ? 'checked' : '' ?>>
      啟用（停用後收銀機無法登入，已登入的也會被登出）
    </label>

    <button type="submit" style="background:#5a3d99;color:#fff;border:0;padding:10px 24px;
                                 border-radius:6px;cursor:pointer;font-size:15px">儲存</button>
    <a class="btn2" href="merchants.php" style="margin-left:8px">取消</a>
  </form>
</div>

<?php if ($editRow):
  $stores = db_list_stores($conn, (int) $editRow['id']);
  $editStoreId = isset($_GET['store']) ? (int) $_GET['store'] : 0;
  $editStore = $editStoreId ? db_find_store($conn, $editStoreId) : null;
  $newStore = isset($_GET['newstore']);
?>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h3 style="margin:0;font-size:16px">旗下商店</h3>
    <a class="btn2" href="?edit=<?= (int) $editRow['id'] ?>&amp;newstore=1">＋ 新增商店</a>
  </div>
  <div class="muted" style="margin-top:8px">
    一個客戶可以有多家商店，各自有自己的商店代號。
    收銀機登入後選擇要以哪一家營業；只有一家時會自動選定。
  </div>
</div>

<div class="card wrap">
  <table>
    <thead><tr><th>商店名稱</th><th>商店代號 MerID</th><th>上游</th><th>狀態</th><th>交易筆數</th><th>備註</th><th></th></tr></thead>
    <tbody>
      <?php if (!$stores): ?>
        <tr><td colspan="7" class="muted">尚未建立商店。沒有商店的話收銀機無法登入。</td></tr>
      <?php endif; ?>
      <?php foreach ($stores as $st): ?>
      <tr>
        <td><strong><?= h($st['name']) ?></strong></td>
        <td><?= h($st['mer_id']) ?></td>
        <td class="muted"><?= h($st['provider']) ?></td>
        <td><?= (int) $st['enabled'] === 1
              ? '<span class="badge s-success">啟用</span>'
              : '<span class="badge s-failed">停用</span>' ?></td>
        <td><?= number_format(db_count_orders_by_store($conn, (int) $st['id'])) ?></td>
        <td class="muted"><?= h($st['note'] ?: '') ?></td>
        <td><a class="btn2" href="?edit=<?= (int) $editRow['id'] ?>&amp;store=<?= (int) $st['id'] ?>">編輯</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($newStore || $editStore): ?>
<div class="card" style="border:1px solid #5a3d99">
  <h4 style="margin-top:0;font-size:15px"><?= $editStore ? '編輯商店' : '新增商店' ?></h4>
  <form method="post">
    <input type="hidden" name="action" value="save_store">
    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
    <input type="hidden" name="merchant_id" value="<?= (int) $editRow['id'] ?>">
    <input type="hidden" name="store_id" value="<?= $editStore ? (int) $editStore['id'] : '' ?>">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px">
      <label>商店名稱<br>
        <input type="text" name="store_name" required style="width:100%;padding:8px"
               value="<?= h($editStore ? $editStore['name'] : '') ?>">
      </label>
      <label>商店代號 MerID<br>
        <input type="text" name="mer_id" required style="width:100%;padding:8px"
               value="<?= h($editStore ? $editStore['mer_id'] : '') ?>">
        <span class="muted" style="font-size:12px">上游核發給這家店的代號</span>
      </label>
      <label>上游<br>
        <select name="provider" style="width:100%;padding:8px">
          <?php foreach (array_keys($providers) as $pn): ?>
            <option value="<?= h($pn) ?>"
              <?= ($editStore && $editStore['provider'] === $pn) ? 'selected' : '' ?>><?= h($pn) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>備註<br>
        <input type="text" name="store_note" style="width:100%;padding:8px"
               value="<?= h($editStore ? $editStore['note'] : '') ?>">
      </label>
    </div>
    <label style="display:block;margin:14px 0">
      <input type="checkbox" name="store_enabled" value="1"
             <?= (!$editStore || (int) $editStore['enabled'] === 1) ? 'checked' : '' ?>> 啟用
    </label>
    <button type="submit" style="background:#5a3d99;color:#fff;border:0;padding:8px 20px;
                                 border-radius:6px;cursor:pointer">儲存商店</button>
    <a class="btn2" href="?edit=<?= (int) $editRow['id'] ?>" style="margin-left:8px">取消</a>
  </form>

  <?php if ($editStore): ?>
  <form method="post" style="margin-top:14px;padding-top:12px;border-top:1px solid #eee"
        onsubmit="return confirm('確定要刪除這家商店嗎？');">
    <input type="hidden" name="action" value="delete_store">
    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
    <input type="hidden" name="merchant_id" value="<?= (int) $editRow['id'] ?>">
    <input type="hidden" name="store_id" value="<?= (int) $editStore['id'] ?>">
    <button type="submit" style="background:#fff;color:#c62828;border:1px solid #c62828;
                                 padding:6px 14px;border-radius:6px;cursor:pointer">刪除商店</button>
    <span class="muted" style="margin-left:8px;font-size:12px">已有交易的商店無法刪除，請改為停用</span>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php $devices = db_list_pos_devices_by_merchant($conn, (int) $editRow['id']); ?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h3 style="margin:0;font-size:16px">收銀機</h3>
    <form method="post" onsubmit="return confirm('確定要登出所有收銀機嗎？店員需重新輸入帳密。');">
      <input type="hidden" name="action" value="logout_all">
      <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
      <input type="hidden" name="merchant_id" value="<?= (int) $editRow['id'] ?>">
      <button type="submit" style="background:#fff;color:#c62828;border:1px solid #c62828;
                                   padding:6px 14px;border-radius:6px;cursor:pointer">登出所有收銀機</button>
    </form>
  </div>
  <div class="muted" style="margin-top:8px">
    連續登入失敗 <?= POS_LOCK_THRESHOLD ?> 次的收銀機會被鎖定，需在這裡解鎖後才能再登入。
  </div>
</div>

<div class="card wrap">
  <table>
    <thead><tr><th>設備</th><th>狀態</th><th>失敗次數</th><th>最後使用</th><th>最後失敗</th><th></th></tr></thead>
    <tbody>
      <?php if (!$devices): ?>
        <tr><td colspan="6" class="muted">還沒有收銀機登入過</td></tr>
      <?php endif; ?>
      <?php foreach ($devices as $dv): ?>
      <tr>
        <td>
          <strong><?= h(trim(($dv['brand'] ?: '') . ' ' . ($dv['model'] ?: '')) ?: '未知機型') ?></strong>
          <?php if ($dv['name']): ?><span class="muted">（<?= h($dv['name']) ?>）</span><?php endif; ?>
          <div class="muted" style="font-size:12px">
            <?= h($dv['serial_no'] ? '序號 ' . $dv['serial_no'] : $dv['device_id']) ?>
          </div>
        </td>
        <td><?= (int) $dv['locked'] === 1
              ? '<span class="badge s-failed">已鎖定</span>'
              : '<span class="badge s-success">正常</span>' ?></td>
        <td><?= (int) $dv['failed_count'] ?></td>
        <td class="muted"><?= h($dv['last_used_at'] ?: '—') ?></td>
        <td class="muted"><?= h($dv['last_failed_at'] ?: '—') ?></td>
        <td>
          <?php if ((int) $dv['locked'] === 1): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="unlock_device">
            <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
            <input type="hidden" name="merchant_id" value="<?= (int) $editRow['id'] ?>">
            <input type="hidden" name="device_id" value="<?= h($dv['device_id']) ?>">
            <button type="submit" style="background:#5a3d99;color:#fff;border:0;padding:6px 14px;
                                         border-radius:6px;cursor:pointer">解鎖</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>
<?php endif; ?>

<?php admin_footer(); ?>
