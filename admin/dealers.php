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

            db_save_dealer($conn, $id, $name, $account, $hash, $enabled,
                trim(isset($_POST['note']) ? $_POST['note'] : ''));

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

<?php admin_footer(); ?>
