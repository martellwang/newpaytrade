<?php
/**
 * 系統設定：目前是各清單頁「預設每頁筆數」，共用同一套設定機制
 * （app_settings key-value 表），但每個清單各自獨立設定、互不影響 ——
 * 交易紀錄改成 100 筆不會連帶把收銀機清單也改成 100 筆。
 *
 * 之後若有其他要讓總管理者在後台調整的全站參數，在 $lists 或另開一組
 * 陣列加一筆即可，不必再開新表、新頁面骨架。
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/layout.php';

admin_require_login();

$conn = db_connect();
db_create_app_settings_table_if_not_exists($conn);

/*
 * 一次性搬遷：這個設定原本只有「交易紀錄」一項，key 叫 default_page_size。
 * 這次加了其他清單，改用範圍更明確的 page_size_orders，避免名稱看起來
 * 像是「全站唯一的預設值」。只在新 key 還沒有值時搬一次，之後每次載入
 * 這頁都只是多一次查詢確認、不會重複寫入。
 */
$oldValue = db_get_setting($conn, 'default_page_size', null);
if ($oldValue !== null && db_get_setting($conn, 'page_size_orders', null) === null) {
    db_set_setting($conn, 'page_size_orders', $oldValue);
}

// 每個清單各自的設定 key 與說明。要新增可調整項目，這裡加一筆就好。
$lists = array(
    'page_size_orders'    => array('label' => '交易紀錄', 'desc' => '「交易紀錄」清單首次開啟時的每頁筆數'),
    'page_size_dealers'   => array('label' => '經銷商', 'desc' => '「經銷商」清單首次開啟時的每頁筆數'),
    'page_size_merchants' => array('label' => '客戶管理', 'desc' => '「客戶管理」清單首次開啟時的每頁筆數'),
    'page_size_devices'   => array('label' => '收銀機', 'desc' => '「收銀機」清單首次開啟時的每頁筆數'),
    'page_size_staff'     => array('label' => '店員', 'desc' => '各商店的「店員」清單共用這一個預設值'),
    'page_size_report'    => array('label' => '對帳報表', 'desc' => '「對帳報表」逐日明細首次開啟時的每頁筆數'),
);
$pageSizeOptions = array(25, 50, 100);

$flash = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!admin_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
            throw new Exception('表單已過期，請重新操作');
        }
        // 一次送出全部設定值，逐一驗證 —— 有任何一項不合法就整批不寫入，
        // 不要出現「存了一半」的情況讓人搞不清楚哪些真的生效了。
        $toSave = array();
        foreach ($lists as $key => $info) {
            $value = isset($_POST[$key]) ? (int) $_POST[$key] : 0;
            if (!in_array($value, $pageSizeOptions, true)) {
                throw new Exception("「{$info['label']}」的每頁筆數只能是 "
                    . implode('、', $pageSizeOptions) . ' 其中一個');
            }
            $toSave[$key] = $value;
        }
        foreach ($toSave as $key => $value) {
            db_set_setting($conn, $key, (string) $value);
        }
        $flash = '設定已儲存';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 讀出目前值供表單顯示；查不到或值不在白名單內就退回第一個選項
$currentValues = array();
foreach ($lists as $key => $info) {
    $v = (int) db_get_setting($conn, $key, $pageSizeOptions[0]);
    $currentValues[$key] = in_array($v, $pageSizeOptions, true) ? $v : $pageSizeOptions[0];
}

admin_header('系統設定', 'settings.php');
?>

<div class="card">
  <h2 style="margin:0 0 8px;font-size:18px">清單預設每頁筆數</h2>
  <div class="muted" style="margin-bottom:16px">
    每個清單頁首次開啟時使用這裡設定的筆數；管理者仍可在該頁面上臨時切換
    每頁筆數，這裡調整的只是預設值，各清單互不影響。
  </div>

  <?php if ($flash): ?>
    <div class="card" style="border-left:4px solid #2e7d32;margin-bottom:16px"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="card" style="border-left:4px solid #c62828;margin-bottom:16px"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">

    <?php foreach ($lists as $key => $info): ?>
    <div style="margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid #eee">
      <div style="font-weight:600;margin-bottom:4px"><?= h($info['label']) ?></div>
      <div class="muted" style="font-size:12px;margin-bottom:10px"><?= h($info['desc']) ?></div>
      <div style="display:flex;gap:10px">
        <?php foreach ($pageSizeOptions as $opt): ?>
          <label style="display:flex;align-items:center;gap:6px;padding:10px 16px;
                        border:1px solid <?= $opt === $currentValues[$key] ? '#5a3d99' : '#ccc' ?>;
                        border-radius:8px;cursor:pointer">
            <input type="radio" name="<?= h($key) ?>" value="<?= $opt ?>"
                   <?= $opt === $currentValues[$key] ? 'checked' : '' ?>>
            <?= $opt ?> 筆
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <button type="submit">儲存全部設定</button>
  </form>
</div>

<?php admin_footer(); ?>
