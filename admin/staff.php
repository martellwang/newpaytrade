<?php
/**
 * 店員管理（商店底下的個別人員）。
 *
 * ── 這一層跟收銀機登入不是同一件事 ────────────────────────────
 *
 * 收銀機用「客戶編號 + 帳號 + 密碼」登入，那是**機器綁商店**，一綁很久。
 * 店員是疊在上面的一層：開班時輸入工號與 PIN，只影響「這一班誰在收款」。
 *
 * 所以 PIN 弱一點是可以接受的 —— 它**不能單獨用來認證**，前提是那台機器
 * 已經用商店帳號登入過了。PIN 只是在已授權的機器上區分是哪位同事。
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/pagination.php';

admin_require_login();

$conn = db_connect();
db_create_merchants_table_if_not_exists($conn);
db_create_store_staff_table_if_not_exists($conn);
db_create_app_settings_table_if_not_exists($conn);

$storeId = isset($_GET['store']) ? (int) $_GET['store'] : 0;
$store = $storeId ? db_find_store($conn, $storeId) : null;
if (!$store) {
    admin_header('店員管理');
    echo '<div class="card">找不到這家商店。<a href="merchants.php">回客戶管理</a></div>';
    admin_footer();
    exit;
}

/*
 * 每頁筆數共用同一個系統設定值（page_size_staff），不分店各自設定 ——
 * 這是總管理者在「系統設定」調的全站預設，不是每家店各自的偏好。
 */
$allowedPerPage = array(25, 50, 100);
$perPage = admin_resolve_page_size($conn, 'page_size_staff', $allowedPerPage);
$sort = admin_resolve_sort();
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));

$flash = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!admin_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
            throw new Exception('表單已過期，請重新操作');
        }
        $action = isset($_POST['action']) ? $_POST['action'] : '';

        if ($action === 'save_staff') {
            $id = isset($_POST['staff_id']) ? (int) $_POST['staff_id'] : 0;
            $code = trim(isset($_POST['staff_code']) ? $_POST['staff_code'] : '');
            $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
            $pin = trim(isset($_POST['pin']) ? $_POST['pin'] : '');
            $canRefund = isset($_POST['can_refund']) ? 1 : 0;
            $canEnroll = isset($_POST['can_enroll']) ? 1 : 0;
            $cardUid = strtoupper(trim(isset($_POST['card_uid']) ? $_POST['card_uid'] : ''));
            $active = isset($_POST['active']) ? 1 : 0;
            $note = trim(isset($_POST['note']) ? $_POST['note'] : '');

            if ($code === '') { throw new Exception('請填寫工號'); }
            if (!preg_match('/^[A-Za-z0-9_-]{1,16}$/', $code)) {
                throw new Exception('工號只能使用英數字、底線與減號，最多 16 字');
            }
            if ($name === '') { throw new Exception('請填寫姓名'); }
            // 新增時 PIN 必填；編輯時留空代表不修改
            if (!$id && $pin === '') { throw new Exception('請設定 PIN'); }
            if ($pin !== '' && !preg_match('/^\d{4,8}$/', $pin)) {
                throw new Exception('PIN 必須是 4 到 8 位數字');
            }

            if ($cardUid !== '' && !preg_match('/^[0-9A-F]{8,32}$/', $cardUid)) {
                throw new Exception('卡片 UID 只能是 8-32 個 16 進位字元');
            }
            db_save_staff($conn, $id, $storeId, $code, $name, $pin, $canRefund, $active, $note,
                $cardUid, $canEnroll);
            $flash = "已儲存店員「{$name}」";

        } elseif ($action === 'delete_staff') {
            $id = (int) $_POST['staff_id'];
            /*
             * 有交易紀錄的店員不能刪除。
             *
             * 刪掉的話那些交易的經手人就查不到了 —— 而查經手人這件事，
             * 需要的時機正是出爭議的時候。要離職請改為停用。
             */
            $used = db_count_orders_by_staff($conn, $id);
            if ($used > 0) {
                throw new Exception("無法刪除：這位店員已有 {$used} 筆交易紀錄。請改為停用。");
            }
            db_delete_staff($conn, $id);
            $flash = '已刪除店員';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$totalStaff = db_count_staff_in_store($conn, $storeId);
$totalPages = max(1, (int) ceil($totalStaff / $perPage));
$page = min($page, $totalPages);
$staff = db_list_staff($conn, $storeId, $perPage, ($page - 1) * $perPage, $sort);

$baseParams = array('store' => $storeId, 'perPage' => $perPage, 'sort' => $sort);
$qs = http_build_query($baseParams);

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = $editId ? db_find_staff($conn, $editId) : null;
// 只能編輯這家店自己的店員 —— 換個 id 就跨店編輯是不行的
if ($editRow && (int) $editRow['store_id'] !== $storeId) {
    $editRow = null;
}
$isNew = isset($_GET['new']);

admin_header('店員管理');
?>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h2 style="margin:0;font-size:18px">店員 —— <?= h($store['name']) ?></h2>
    <div>
      <a class="btn2" href="?store=<?= $storeId ?>&amp;new=1">＋ 新增店員</a>
      <a class="btn2" href="merchants.php" style="margin-left:8px">回客戶管理</a>
    </div>
  </div>
  <div class="muted" style="margin-top:8px">
    店員是疊在收銀機登入之上的一層。收銀機仍用商店帳號登入（一綁很久），
    店員開班時可以用<strong>工號 + PIN</strong>或<strong>感應卡 + PIN</strong>，兩種併行。
    用來記錄「這一班誰收了多少」與誰有退款權限。<br>
    ⚠️ 感應卡的 UID 不是密碼（任何手機都讀得到，也能複製），所以刷卡之後
    <strong>一定還要輸入 PIN</strong> —— 卡片只是加快輸入，不是替代驗證。
  </div>
</div>

<?php if ($flash): ?><div class="card" style="border-left:4px solid #2e7d32"><?= h($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="card" style="border-left:4px solid #c62828"><?= h($error) ?></div><?php endif; ?>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <?php admin_render_pager($page, $totalPages, $qs); ?>
    <?php admin_render_page_size_switcher($allowedPerPage, $perPage, $baseParams); ?>
  </div>
</div>

<div class="card">
  <table style="width:100%;border-collapse:collapse">
    <thead><tr><th><?php admin_sortable_header('序號', $sort, $baseParams); ?></th>
        <th>工號</th><th>姓名</th><th>感應卡</th><th>可退款</th><th>可建檔</th>
        <th>狀態</th><th>備註</th><th></th></tr></thead>
    <tbody>
      <?php if (!$staff): ?>
        <tr><td colspan="9" class="muted">尚未建立店員。沒有店員的話收銀機仍可收款，
            只是交易查不到經手人，也無法交班。</td></tr>
      <?php endif; ?>
      <?php $seq = ($page - 1) * $perPage; ?>
      <?php foreach ($staff as $s): ?>
      <?php $seq++; ?>
      <tr style="border-top:1px solid #eee">
        <td class="muted"><?= $seq ?></td>
        <td><?= h($s['staff_code']) ?></td>
        <td><?= h($s['name']) ?></td>
        <td><?= !empty($s['card_uid'])
              ? '<code>' . h($s['card_uid']) . '</code>'
              : '<span class="muted">—</span>' ?></td>
        <td><?= ((int) $s['can_refund'] === 1) ? '是' : '—' ?></td>
        <td><?= (isset($s['can_enroll']) && (int) $s['can_enroll'] === 1) ? '是' : '—' ?></td>
        <td><?= ((int) $s['active'] === 1) ? '啟用' : '<span class="muted">停用</span>' ?></td>
        <td class="muted"><?= h($s['note']) ?></td>
        <td><a class="btn2" href="?<?= h($qs) ?>&amp;page=<?= $page ?>&amp;edit=<?= (int) $s['id'] ?>">編輯</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <?php admin_render_pager($page, $totalPages, $qs); ?>
</div>

<?php if ($isNew || $editRow): ?>
<div class="card" style="border:1px solid #5a3d99">
  <h4 style="margin-top:0;font-size:15px"><?= $editRow ? '編輯店員' : '新增店員' ?></h4>
  <form method="post">
    <input type="hidden" name="action" value="save_staff">
    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
    <input type="hidden" name="staff_id" value="<?= $editRow ? (int) $editRow['id'] : '' ?>">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px">
      <label>工號<br>
        <input type="text" name="staff_code" required style="width:100%;padding:8px"
               value="<?= h($editRow ? $editRow['staff_code'] : '') ?>">
        <span class="muted" style="font-size:12px">開班時要輸入的。只需在這家店裡唯一，可沿用店家自己的編號</span>
      </label>
      <label>姓名<br>
        <input type="text" name="name" required style="width:100%;padding:8px"
               value="<?= h($editRow ? $editRow['name'] : '') ?>">
      </label>
      <label>PIN（4-8 位數字）<br>
        <input type="password" name="pin" style="width:100%;padding:8px" autocomplete="new-password"
               <?= $editRow ? '' : 'required' ?>>
        <span class="muted" style="font-size:12px">
          <?= $editRow ? '留空代表不修改' : '店員開班時輸入' ?>
        </span>
      </label>
      <label>感應卡 UID<br>
        <input type="text" name="card_uid" style="width:100%;padding:8px"
               value="<?= h($editRow && isset($editRow['card_uid']) ? $editRow['card_uid'] : '') ?>">
        <span class="muted" style="font-size:12px">
          16 進位，例如 717E6632。留空代表這位店員只用工號開班。
          <strong>清空即可解除綁定</strong>（卡片遺失時用）。
        </span>
      </label>
      <label>備註<br>
        <input type="text" name="note" style="width:100%;padding:8px"
               value="<?= h($editRow ? $editRow['note'] : '') ?>">
      </label>
    </div>

    <label style="display:block;margin:14px 0 6px">
      <input type="checkbox" name="can_refund" value="1"
             <?= ($editRow && (int) $editRow['can_refund'] === 1) ? 'checked' : '' ?>> 可以執行退款
    </label>
    <div class="muted" style="font-size:12px;margin-bottom:12px">
      ⚠️ 退款會把錢退還給客人，且無法在收銀機上撤回。建議只開給店長或值班主管。
    </div>

    <label style="display:block;margin:0 0 6px">
      <input type="checkbox" name="can_enroll" value="1"
             <?= ($editRow && isset($editRow['can_enroll']) && (int) $editRow['can_enroll'] === 1)
                 ? 'checked' : '' ?>> 可以在收銀機上登記新的感應卡
    </label>
    <div class="muted" style="font-size:12px;margin-bottom:12px">
      有這個權限的人刷卡開班之後，就能在收銀機上把新卡登記給其他店員，
      不必回到這個後台。等於可以新增人員，請謹慎給予。
    </div>

    <label style="display:block;margin:0 0 14px">
      <input type="checkbox" name="active" value="1"
             <?= (!$editRow || (int) $editRow['active'] === 1) ? 'checked' : '' ?>> 啟用
    </label>

    <button type="submit" style="background:#5a3d99;color:#fff;border:0;padding:8px 20px;
                                 border-radius:6px;cursor:pointer">儲存</button>
    <a class="btn2" href="?<?= h($qs) ?>&amp;page=<?= $page ?>" style="margin-left:8px">取消</a>
  </form>

  <?php if ($editRow): ?>
  <form method="post" style="margin-top:14px;padding-top:12px;border-top:1px solid #eee"
        onsubmit="return confirm('確定要刪除這位店員嗎？');">
    <input type="hidden" name="action" value="delete_staff">
    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
    <input type="hidden" name="staff_id" value="<?= (int) $editRow['id'] ?>">
    <button type="submit" style="background:#fff;color:#c62828;border:1px solid #c62828;
                                 padding:6px 14px;border-radius:6px;cursor:pointer">刪除店員</button>
    <span class="muted" style="margin-left:8px;font-size:12px">已有交易紀錄的店員無法刪除，請改為停用</span>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
