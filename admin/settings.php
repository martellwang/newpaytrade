<?php
/**
 * 系統設定：目前只有「交易紀錄預設每頁筆數」一項，之後若有其他全站參數
 * 要讓總管理者自行調整，加在這一頁即可（見 db.php 的 app_settings 說明）。
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/layout.php';

admin_require_login();

$conn = db_connect();
db_create_app_settings_table_if_not_exists($conn);

// 可選值集中定義在這裡，表單與驗證都用同一份，不會兩邊寫的值兜不起來
$pageSizeOptions = array(25, 50, 100);
$defaultPageSizeKey = 'default_page_size';

$flash = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!admin_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
            throw new Exception('表單已過期，請重新操作');
        }
        $value = isset($_POST['default_page_size']) ? (int) $_POST['default_page_size'] : 0;
        if (!in_array($value, $pageSizeOptions, true)) {
            throw new Exception('每頁筆數只能是 ' . implode('、', $pageSizeOptions) . ' 其中一個');
        }
        db_set_setting($conn, $defaultPageSizeKey, (string) $value);
        $flash = "已將交易紀錄預設每頁筆數設定為 {$value} 筆";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$currentValue = (int) db_get_setting($conn, $defaultPageSizeKey, 25);
if (!in_array($currentValue, $pageSizeOptions, true)) {
    // 資料庫裡存了一個不在選項內的舊值（例如白名單以後調整過），
    // 顯示層還是要能正常渲染，退回第一個選項當顯示用的預設
    $currentValue = $pageSizeOptions[0];
}

admin_header('系統設定', 'settings.php');
?>

<div class="card">
  <h2 style="margin:0 0 8px;font-size:18px">交易紀錄預設每頁筆數</h2>
  <div class="muted" style="margin-bottom:16px">
    「交易紀錄」頁面首次開啟時使用這個筆數；管理者仍可在該頁面上臨時切換
    每頁筆數，這裡調整的只是預設值。
  </div>

  <?php if ($flash): ?>
    <div class="card" style="border-left:4px solid #2e7d32;margin-bottom:16px"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="card" style="border-left:4px solid #c62828;margin-bottom:16px"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
    <div style="display:flex;gap:10px;margin-bottom:18px">
      <?php foreach ($pageSizeOptions as $opt): ?>
        <label style="display:flex;align-items:center;gap:6px;padding:10px 16px;
                      border:1px solid <?= $opt === $currentValue ? '#5a3d99' : '#ccc' ?>;
                      border-radius:8px;cursor:pointer">
          <input type="radio" name="default_page_size" value="<?= $opt ?>"
                 <?= $opt === $currentValue ? 'checked' : '' ?>>
          <?= $opt ?> 筆
        </label>
      <?php endforeach; ?>
    </div>
    <button type="submit">儲存設定</button>
  </form>
</div>

<?php admin_footer(); ?>
