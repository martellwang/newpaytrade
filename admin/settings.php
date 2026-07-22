<?php
/**
 * 系統設定：左右分欄。左側是功能選單，右側呈現選中功能的設定。
 *
 * 目前兩組功能：
 *   顯示設定 → 清單預設每頁筆數（各清單頁首次開啟時的每頁筆數）
 *   校時設定 → 時間伺服器（收銀機每小時對這個 Time Server 校時，見 pos-config.php）
 *
 * 之後要加全站參數，在 $navNodes 的左側選單再加一個 section，右側多一段
 * render 即可，不必再開新頁面骨架。
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/layout.php';

admin_require_login();

$conn = db_connect();
db_create_app_settings_table_if_not_exists($conn);

/*
 * 一次性搬遷：頁面筆數設定原本只有「交易紀錄」一項，key 叫 default_page_size。
 * 這次加了其他清單，改用範圍更明確的 page_size_orders。只在新 key 還沒值時搬一次。
 */
$oldValue = db_get_setting($conn, 'default_page_size', null);
if ($oldValue !== null && db_get_setting($conn, 'page_size_orders', null) === null) {
    db_set_setting($conn, 'page_size_orders', $oldValue);
}

// 各清單各自的設定 key 與說明。要新增可調整項目，這裡加一筆就好。
$lists = array(
    'page_size_orders'    => array('label' => '交易紀錄', 'desc' => '「交易紀錄」清單首次開啟時的每頁筆數'),
    'page_size_dealers'   => array('label' => '經銷商', 'desc' => '「經銷商」清單首次開啟時的每頁筆數'),
    'page_size_merchants' => array('label' => '客戶管理', 'desc' => '「客戶管理」清單首次開啟時的每頁筆數'),
    'page_size_devices'   => array('label' => '設備管理', 'desc' => '「設備管理 → POS機管理」清單首次開啟時的每頁筆數'),
    'page_size_staff'     => array('label' => '店員', 'desc' => '各商店的「店員」清單共用這一個預設值'),
    'page_size_report'    => array('label' => '對帳報表', 'desc' => '「對帳報表」逐日明細首次開啟時的每頁筆數'),
);
$pageSizeOptions = array(25, 50, 100);

// 左側功能選單（第一層標題 + 第二層可點項目）
$navNodes = array(
    array('label' => '顯示設定', 'children' => array(
        'page_size' => '清單預設每頁筆數',
    )),
    array('label' => '校時設定', 'children' => array(
        'time_server' => '時間伺服器',
    )),
);

$validSections = array('page_size', 'time_server');
$section = isset($_REQUEST['section']) && in_array($_REQUEST['section'], $validSections, true)
    ? $_REQUEST['section'] : 'page_size';

$flash = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!admin_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
            throw new Exception('表單已過期，請重新操作');
        }

        if ($section === 'page_size') {
            // 一次送出全部設定值，逐一驗證 —— 有任何一項不合法就整批不寫入
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

        } elseif ($section === 'time_server') {
            $host = trim(isset($_POST['time_server']) ? $_POST['time_server'] : '');
            // 允許主機名稱或 IP，可含 port（例如 time.google.com 或 192.168.1.1）。
            // 留空 = 停用校時。字元白名單擋掉奇怪輸入，不接受空白與協定前綴。
            if ($host !== '' && !preg_match('/^[A-Za-z0-9.\-:]{1,100}$/', $host)) {
                throw new Exception('時間伺服器只能是主機名稱或 IP（可含 port），不要加 http:// 之類的前綴');
            }
            db_set_setting($conn, 'time_server', $host);
            $flash = $host === '' ? '已停用校時（時間伺服器留空）' : '時間伺服器已儲存';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 讀出目前值供表單顯示
$currentPageSizes = array();
foreach ($lists as $key => $info) {
    $v = (int) db_get_setting($conn, $key, $pageSizeOptions[0]);
    $currentPageSizes[$key] = in_array($v, $pageSizeOptions, true) ? $v : $pageSizeOptions[0];
}
$timeServer = db_get_setting($conn, 'time_server', '');

admin_header('系統設定', 'settings.php', true);
?>

<?php if ($flash): ?>
  <div class="card" style="border-left:4px solid #2e7d32;margin-bottom:16px"><?= h($flash) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="card" style="border-left:4px solid #c62828;margin-bottom:16px"><?= h($error) ?></div>
<?php endif; ?>

<div class="split">
  <?php admin_render_split_nav($navNodes, $section, 'settings.php'); ?>

  <div class="split-body">
    <?php if ($section === 'page_size'): ?>
      <div class="card">
        <h2 style="margin:0 0 8px;font-size:18px">清單預設每頁筆數</h2>
        <div class="muted" style="margin-bottom:16px">
          每個清單頁首次開啟時使用這裡設定的筆數；管理者仍可在該頁面上臨時切換
          每頁筆數，這裡調整的只是預設值，各清單互不影響。
        </div>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
          <input type="hidden" name="section" value="page_size">

          <?php foreach ($lists as $key => $info): ?>
          <div style="margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid #eee">
            <div style="font-weight:600;margin-bottom:4px"><?= h($info['label']) ?></div>
            <div class="muted" style="font-size:12px;margin-bottom:10px"><?= h($info['desc']) ?></div>
            <div style="display:flex;gap:10px">
              <?php foreach ($pageSizeOptions as $opt): ?>
                <label style="display:flex;align-items:center;gap:6px;padding:10px 16px;
                              border:1px solid <?= $opt === $currentPageSizes[$key] ? '#5a3d99' : '#ccc' ?>;
                              border-radius:8px;cursor:pointer">
                  <input type="radio" name="<?= h($key) ?>" value="<?= $opt ?>"
                         <?= $opt === $currentPageSizes[$key] ? 'checked' : '' ?>>
                  <?= $opt ?> 筆
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>

          <button type="submit">儲存全部設定</button>
        </form>
      </div>

    <?php elseif ($section === 'time_server'): ?>
      <div class="card">
        <h2 style="margin:0 0 8px;font-size:18px">時間伺服器（校時）</h2>
        <div class="muted" style="margin-bottom:16px">
          收銀機每小時會對這個時間伺服器校時一次（NTP）。填主機名稱或 IP，
          例如 <code>time.google.com</code>、<code>tw.pool.ntp.org</code>，或內網的
          <code>192.168.1.1</code>。<strong>留空代表停用校時</strong>。
        </div>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
          <input type="hidden" name="section" value="time_server">
          <div style="margin-bottom:14px">
            <label style="display:block;font-size:13px;color:#555;margin-bottom:4px">時間伺服器</label>
            <input type="text" name="time_server" value="<?= h($timeServer) ?>"
                   placeholder="time.google.com" style="width:100%;max-width:360px">
          </div>
          <button type="submit">儲存</button>
        </form>

        <div class="muted" style="margin-top:16px;font-size:12px;line-height:1.6">
          注意：能不能真的改動收銀機的系統時鐘，取決於該機型是否授予 App 設定時間的權限。
          一般安裝的 App 沒有這個權限時，收銀機會改用「與伺服器的時間差」來校正自己顯示與紀錄用的時間，
          不會強改系統時鐘。校時結果可在收銀機的「診斷」畫面查看。
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php admin_footer(); ?>
