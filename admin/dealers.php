<?php
/**
 * 經銷商管理。
 *
 * TMS 的第二層：上游 → **經銷商** → 客戶 → 商店。
 * 經銷商底下掛多個客戶，未來會有自己的後台帳號可看旗下全部交易。
 *
 * 自營的客戶也要掛在某個經銷商底下（例如建一個「直營」），這樣層級才
 * 一致 —— 允許「無經銷商」會讓之後每個報表查詢都要多處理一種例外狀態。
 */

require_once __DIR__ . '/auth.php';
admin_require_login();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/pagination.php';

$conn = db_connect();
db_create_merchants_table_if_not_exists($conn);
db_create_app_settings_table_if_not_exists($conn);

$allowedPerPage = array(25, 50, 100);
$perPage = admin_resolve_page_size($conn, 'page_size_dealers', $allowedPerPage);
$sort = admin_resolve_sort();
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));

$flash = null;
$flashOk = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $flash = '安全驗證失敗，請重新整理再試';
    } else {
      $action = isset($_POST['action']) ? $_POST['action'] : 'save_dealer';

      // ── 前置碼的新增／刪除，各自帶 action ──────────────────────
      if ($action === 'add_prefix' || $action === 'delete_prefix') {
        $dealerId = (int) (isset($_POST['dealer_id']) ? $_POST['dealer_id'] : 0);
        try {
            if ($action === 'add_prefix') {
                db_add_dealer_prefix($conn, $dealerId,
                    isset($_POST['prefix']) ? $_POST['prefix'] : '',
                    trim(isset($_POST['prefix_note']) ? $_POST['prefix_note'] : ''));
                $flashOk = true;
                $flash = '已新增前置碼';
            } else {
                db_delete_dealer_prefix($conn, (int) $_POST['prefix_id']);
                $flashOk = true;
                $flash = '已刪除前置碼';
            }
        } catch (Exception $e) {
            $flash = $e->getMessage();
        }
        $_SESSION['dealers_flash'] = array('ok' => $flashOk, 'msg' => $flash);
        header('Location: dealers.php?edit=' . $dealerId);
        exit;
      }

        try {
            $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : 0;
            $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
            $account = trim(isset($_POST['login_account']) ? $_POST['login_account'] : '');
            $password = isset($_POST['password']) ? $_POST['password'] : '';
            $enabled = isset($_POST['enabled']) ? 1 : 0;

            if ($name === '') { throw new Exception('請填寫經銷商名稱'); }
            if (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $account)) {
                throw new Exception('登入帳號只能是英數與 _ . @ - （3～64 字）');
            }

            $hash = null;
            if ($password !== '') {
                if (strlen($password) < 8) {
                    // 經銷商帳號看得到旗下所有客戶的交易，密碼要求比收銀機嚴一點
                    throw new Exception('經銷商密碼至少 8 個字元');
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
            } elseif (!$id) {
                throw new Exception('新增經銷商時必須設定密碼');
            }

            $exists = db_find_dealer_by_account($conn, $account);
            if ($exists && (int) $exists['id'] !== $id) {
                throw new Exception("登入帳號「{$account}」已經被其他經銷商使用");
            }

            $newDealerId = db_save_dealer($conn, $id, $name, $account, $hash, $enabled,
                trim(isset($_POST['note']) ? $_POST['note'] : ''));

            // 由哪個客戶經營（該客戶登入 portal 就會看到經銷商介面）。空 = 無。
            db_set_dealer_owner($conn, $newDealerId,
                (int) (isset($_POST['owner_merchant_id']) ? $_POST['owner_merchant_id'] : 0));

            $flashOk = true;
            $flash = "已儲存經銷商「{$name}」" . ($hash !== null ? '（密碼已更新）' : '');
        } catch (Exception $e) {
            $flash = $e->getMessage();
        }
    }
    $_SESSION['dealers_flash'] = array('ok' => $flashOk, 'msg' => $flash);
    header('Location: dealers.php');
    exit;
}

if (!empty($_SESSION['dealers_flash'])) {
    $flashOk = $_SESSION['dealers_flash']['ok'];
    $flash = $_SESSION['dealers_flash']['msg'];
    unset($_SESSION['dealers_flash']);
}

$totalDealers = db_count_dealers($conn);
$totalPages = max(1, (int) ceil($totalDealers / $perPage));
$page = min($page, $totalPages);
$dealers = db_list_dealers($conn, $perPage, ($page - 1) * $perPage, $sort);

$baseParams = array('perPage' => $perPage, 'sort' => $sort);
$qs = http_build_query($baseParams);

$editing = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = $editing ? db_find_dealer($conn, $editing) : null;
$isNew = isset($_GET['new']);
$ownerMerchants = db_list_merchants($conn);   // 「由哪個客戶經營」下拉用

admin_header('經銷商', 'dealers.php');
?>

<?php if ($flash): ?>
<div class="card" style="background:<?= $flashOk ? '#e8f5e9' : '#ffebee' ?>">
  <strong><?= h($flash) ?></strong>
</div>
<?php endif; ?>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h2 style="margin:0;font-size:18px">經銷商</h2>
    <a class="btn2" href="?new=1">＋ 新增經銷商</a>
  </div>
  <div class="muted" style="margin-top:8px">
    每個客戶都必須歸屬於一個經銷商。自營的客戶請建一個「直營」之類的經銷商掛上去。
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
      <tr><th><?php admin_sortable_header('序號', $sort, $baseParams); ?></th>
          <th>名稱</th><th>登入帳號</th><th>狀態</th><th>旗下客戶</th><th>備註</th><th></th></tr>
    </thead>
    <tbody>
      <?php if (!$dealers): ?>
        <tr><td colspan="7" class="muted">尚未建立經銷商。請先建立一個，才能新增客戶。</td></tr>
      <?php endif; ?>
      <?php $seq = ($page - 1) * $perPage; ?>
      <?php foreach ($dealers as $d): ?>
      <?php $seq++; ?>
      <tr>
        <td class="muted"><?= $seq ?></td>
        <td><strong><?= h($d['name']) ?></strong></td>
        <td><code><?= h($d['login_account']) ?></code></td>
        <td><?= (int) $d['enabled'] === 1
              ? '<span class="badge s-success">啟用</span>'
              : '<span class="badge s-failed">停用</span>' ?></td>
        <td><?= number_format(db_count_merchants_by_dealer($conn, (int) $d['id'])) ?> 家</td>
        <td class="muted"><?= h($d['note'] ?: '') ?></td>
        <td><a class="btn2" href="?edit=<?= (int) $d['id'] ?>">編輯</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <?php admin_render_pager($page, $totalPages, $qs); ?>
</div>

<?php if ($isNew || $editRow): ?>
<div class="card" style="border:2px solid #5a3d99">
  <h3 style="margin-top:0;font-size:17px">
    <?= $editRow ? '編輯經銷商：' . h($editRow['name']) : '新增經銷商' ?>
  </h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $editRow ? (int) $editRow['id'] : '' ?>">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px">
      <label>經銷商名稱<br>
        <input type="text" name="name" required style="width:100%;padding:8px"
               value="<?= h($editRow ? $editRow['name'] : '') ?>">
      </label>
      <label>登入帳號<br>
        <input type="text" name="login_account" required style="width:100%;padding:8px"
               value="<?= h($editRow ? $editRow['login_account'] : '') ?>">
        <span class="muted" style="font-size:12px">經銷商後台用，全系統唯一</span>
      </label>
      <label>密碼<br>
        <input type="password" name="password" autocomplete="new-password" style="width:100%;padding:8px"
               placeholder="<?= $editRow ? '留空 = 不變更' : '至少 8 個字元' ?>"
               <?= $editRow ? '' : 'required' ?>>
      </label>
      <label>備註<br>
        <input type="text" name="note" style="width:100%;padding:8px"
               value="<?= h($editRow ? $editRow['note'] : '') ?>">
      </label>
      <label>由哪個客戶經營<br>
        <select name="owner_merchant_id" style="width:100%;padding:8px">
          <option value="">（無 — 純公司建立）</option>
          <?php foreach ($ownerMerchants as $om): ?>
            <option value="<?= (int) $om['id'] ?>"
              <?= ($editRow && (int) $editRow['owner_merchant_id'] === (int) $om['id']) ? 'selected' : '' ?>>
              <?= h($om['customer_code'] . ' ' . $om['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="muted" style="font-size:12px">這個客戶登入網站後台就會看到「經銷商介面」</span>
      </label>
    </div>
    <label style="display:block;margin:14px 0">
      <input type="checkbox" name="enabled" value="1"
             <?= (!$editRow || (int) $editRow['enabled'] === 1) ? 'checked' : '' ?>> 啟用
    </label>
    <button type="submit" style="background:#5a3d99;color:#fff;border:0;padding:10px 24px;
                                 border-radius:6px;cursor:pointer;font-size:15px">儲存</button>
    <a class="btn2" href="dealers.php" style="margin-left:8px">取消</a>
  </form>
</div>
<?php endif; ?>

<?php if ($editRow):
  $prefixes = db_list_dealer_prefixes($conn, (int) $editRow['id']);
?>
<div class="card" style="border:1px solid #5a3d99">
  <h3 style="margin-top:0;font-size:16px">商店代號前置碼</h3>
  <div class="muted" style="margin-bottom:12px">
    這個經銷商可以有<strong>多個</strong>前置碼（4 個大寫英文字母）。旗下商店的
    商店代號 = <strong>前置碼 + 流水號</strong>（例如 <code>NPAA001</code>），
    收銀機登入時輸入這個代號。
  </div>

  <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:14px">
    <thead><tr style="text-align:left;color:#666;border-bottom:1px solid #eee">
      <th style="padding:8px 6px">前置碼</th><th style="padding:8px 6px">備註</th>
      <th style="padding:8px 6px">旗下商店數</th><th style="padding:8px 6px"></th>
    </tr></thead>
    <tbody>
      <?php if (!$prefixes): ?>
        <tr><td colspan="4" class="muted" style="padding:8px 6px">尚未設定前置碼。先加一個才能給商店配代號。</td></tr>
      <?php endif; ?>
      <?php foreach ($prefixes as $p):
        $cnt = db_count_stores_by_prefix($conn, $p['prefix']);
      ?>
      <tr style="border-bottom:1px solid #f2f2f2">
        <td style="padding:8px 6px"><strong style="font-size:15px;letter-spacing:1px"><?= h($p['prefix']) ?></strong></td>
        <td style="padding:8px 6px;color:#666"><?= h($p['note'] ?: '') ?></td>
        <td style="padding:8px 6px"><?= number_format($cnt) ?> 家</td>
        <td style="padding:8px 6px">
          <?php if ($cnt === 0): ?>
          <form method="post" style="display:inline"
                onsubmit="return confirm('確定刪除前置碼 <?= h($p['prefix']) ?>？');">
            <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_prefix">
            <input type="hidden" name="dealer_id" value="<?= (int) $editRow['id'] ?>">
            <input type="hidden" name="prefix_id" value="<?= (int) $p['id'] ?>">
            <button type="submit" style="background:#fff;color:#c62828;border:1px solid #c62828;
                                         padding:4px 12px;border-radius:6px;cursor:pointer">刪除</button>
          </form>
          <?php else: ?>
            <span class="muted" style="font-size:12px">有商店在用，不可刪</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <form method="post" class="filters">
    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="add_prefix">
    <input type="hidden" name="dealer_id" value="<?= (int) $editRow['id'] ?>">
    <div><label>新前置碼（4 個英文字母）</label>
      <input type="text" name="prefix" required maxlength="4" pattern="[A-Za-z]{4}"
             style="text-transform:uppercase;letter-spacing:2px" placeholder="NPAA"></div>
    <div style="flex:1"><label>備註</label>
      <input type="text" name="prefix_note" style="width:100%" placeholder="例如 北區"></div>
    <div><button type="submit">新增前置碼</button></div>
  </form>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
