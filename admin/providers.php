<?php
/**
 * 上游金流機構管理。
 *
 * 這套系統是 TMS，會接不只一家上游（其他金流商、收單銀行、電子支付機構）。
 * 這頁讓上游可以線上新增與維護，不必每次都去改主機上的 config.php。
 *
 * === 金鑰的處理原則（不要改動）===
 * 1. 存進資料庫時**加密**（AES-256-GCM，見 providers.php）
 * 2. **絕對不把金鑰讀回瀏覽器** —— 編輯畫面一律顯示空白，留空代表不變更。
 *    把密鑰塞進 HTML 等於讓它出現在瀏覽器記憶體、快取、甚至截圖裡。
 * 3. 加密金鑰放 config.php 不放資料庫 —— 鑰匙跟鎖放同一個抽屜等於沒鎖。
 */

require_once __DIR__ . '/auth.php';
admin_require_login();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../providers.php';
require_once __DIR__ . '/layout.php';

$conn = db_connect();
db_create_providers_table_if_not_exists($conn);

$flash = null;
$flashOk = false;

/** 各驅動需要哪些憑證欄位。新增驅動時在這裡補一筆。 */
$driverFields = array(
    'payuni' => array(
        'mer_id' => '商店代號 MerID',
        'hash_key' => 'Hash Key（32 碼）',
        'hash_iv' => 'Hash IV（16 碼）',
        'agent_id' => '代理商代號 AgentID（4 碼大寫，查詢商店狀態用）',
    ),
);
$driverEndpoints = array(
    'payuni' => array(
        'authorize' => '幕後授權',
        'close' => '請退款',
        'query' => '交易查詢',
        'merchant_status' => '商店狀態查詢',
    ),
);

// ── 動作處理 ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $flash = '安全驗證失敗，請重新整理再試';
    } else {
        $action = isset($_POST['action']) ? $_POST['action'] : '';

        if ($action === 'save') {
            $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
            $driver = trim(isset($_POST['driver']) ? $_POST['driver'] : '');
            try {
                // 代號會被拿去組驅動檔的路徑，只允許小寫英數與底線
                if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
                    throw new Exception('代號只能是小寫英文、數字或底線（2～32 字）');
                }
                if (!isset($driverFields[$driver])) {
                    throw new Exception('不支援的驅動：' . $driver);
                }

                $endpoints = array();
                foreach ($driverEndpoints[$driver] as $key => $label) {
                    $v = trim(isset($_POST['ep_' . $key]) ? $_POST['ep_' . $key] : '');
                    if ($v !== '') {
                        $endpoints[$key] = $v;
                    }
                }

                // 金鑰：全部留空代表「不變更」。任一有填就整組重寫 ——
                // 混合舊值與新值容易產生半新半舊的組合，那種狀態最難查。
                $creds = array();
                $anyFilled = false;
                foreach ($driverFields[$driver] as $key => $label) {
                    $v = trim(isset($_POST['cr_' . $key]) ? $_POST['cr_' . $key] : '');
                    if ($v !== '') {
                        $anyFilled = true;
                    }
                    $creds[$key] = $v;
                }
                $credEnc = null;
                if ($anyFilled) {
                    if (!provider_secret_key_ready()) {
                        throw new Exception('尚未設定 PROVIDER_SECRET_KEY，無法儲存金鑰');
                    }
                    $credEnc = provider_secret_encrypt(json_encode($creds, JSON_UNESCAPED_UNICODE));
                }

                db_save_provider(
                    $conn,
                    $name,
                    trim(isset($_POST['label']) ? $_POST['label'] : $name),
                    $driver,
                    isset($_POST['enabled']) ? 1 : 0,
                    json_encode($endpoints, JSON_UNESCAPED_UNICODE),
                    $credEnc,
                    trim(isset($_POST['note']) ? $_POST['note'] : '')
                );
                $flashOk = true;
                $flash = "已儲存上游「{$name}」" . ($credEnc === null ? '（金鑰未變更）' : '（金鑰已更新）');
            } catch (Exception $e) {
                $flash = $e->getMessage();
            }
        } elseif ($action === 'delete') {
            $name = isset($_POST['name']) ? $_POST['name'] : '';
            $used = db_count_orders_by_provider($conn, $name);
            if ($used > 0) {
                // 刪掉的話那些交易會失去上游歸屬，對帳與爭議處理都會出問題
                $flash = "無法刪除：已有 {$used} 筆交易使用這家上游。請改為停用。";
            } else {
                db_delete_provider($conn, $name);
                $flashOk = true;
                $flash = "已刪除上游「{$name}」";
            }
        }
    }
    $_SESSION['providers_flash'] = array('ok' => $flashOk, 'msg' => $flash);
    header('Location: providers.php');
    exit;
}

if (!empty($_SESSION['providers_flash'])) {
    $flashOk = $_SESSION['providers_flash']['ok'];
    $flash = $_SESSION['providers_flash']['msg'];
    unset($_SESSION['providers_flash']);
}

$dbProviders = db_list_providers($conn);
$effective = provider_all();          // 實際生效的（含 config.php 後備）
$defaultName = provider_default_name();
$editing = isset($_GET['edit']) ? $_GET['edit'] : null;
$editRow = $editing ? db_find_provider($conn, $editing) : null;
$isNew = isset($_GET['new']);

admin_header('上游管理', 'providers.php');
?>

<?php if ($flash): ?>
<div class="card" style="background:<?= $flashOk ? '#e8f5e9' : '#ffebee' ?>">
  <strong><?= h($flash) ?></strong>
</div>
<?php endif; ?>

<?php if (!provider_secret_key_ready()): ?>
<div class="card" style="background:#fff3e0;border:1px solid #ffcc80">
  <h3 style="margin-top:0;font-size:16px">還不能儲存金鑰</h3>
  <p style="margin:0 0 8px">
    要把上游金鑰存進資料庫，必須先設定一把加密金鑰。
    <strong>它不能存在資料庫裡</strong> —— 鑰匙跟鎖放同一個抽屜等於沒鎖。
  </p>
  <p style="margin:0 0 6px">在主機上產生一把：</p>
  <pre style="background:#fff;padding:10px;border-radius:6px;font-size:12px">php -r "echo bin2hex(random_bytes(32));"</pre>
  <p style="margin:6px 0 6px">把結果填進 <code>config.php</code>：</p>
  <pre style="background:#fff;padding:10px;border-radius:6px;font-size:12px">define('PROVIDER_SECRET_KEY', '剛才那 64 個字元');</pre>
  <p style="margin:0;color:#b26a00">
    ⚠️ 這把金鑰之後不要更換 —— 換掉的話資料庫裡已加密的設定就解不開，要全部重新輸入。
  </p>
</div>
<?php endif; ?>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h2 style="margin:0;font-size:18px">上游金流機構</h2>
    <a class="btn2" href="?new=1">＋ 新增上游</a>
  </div>
  <div class="muted" style="margin-top:8px;line-height:1.7">
    每家上游的加密方式與欄位名稱都不同，那些寫在
    <code>providers/&lt;驅動&gt;.php</code>。這頁只管設定，不含各家的規則。<br>
    要接一家全新的上游，需要先有對應的驅動程式 —— 目前只有 <code>payuni</code>。
  </div>
</div>

<div class="card wrap">
  <table>
    <thead>
      <tr><th>代號</th><th>名稱</th><th>驅動</th><th>狀態</th><th>來源</th><th>交易筆數</th><th>備註</th><th></th></tr>
    </thead>
    <tbody>
      <?php if (!$effective): ?>
        <tr><td colspan="8" class="muted">尚未設定任何上游</td></tr>
      <?php endif; ?>
      <?php foreach ($effective as $name => $p): ?>
      <?php $inDb = !empty($p['from_db']); ?>
      <tr>
        <td>
          <strong><?= h($name) ?></strong>
          <?php if ($name === $defaultName): ?>
            <span class="badge s-success" style="margin-left:4px">預設</span>
          <?php endif; ?>
        </td>
        <td><?= h(isset($p['label']) ? $p['label'] : '—') ?></td>
        <td><code><?= h(isset($p['driver']) ? $p['driver'] : '—') ?></code></td>
        <td>
          <?php if (!empty($p['enabled'])): ?>
            <span class="badge s-success">啟用</span>
          <?php else: ?>
            <span class="badge s-failed">停用</span>
          <?php endif; ?>
        </td>
        <td class="muted"><?= $inDb ? '資料庫' : 'config.php' ?></td>
        <td><?= number_format(db_count_orders_by_provider($conn, $name)) ?></td>
        <td class="muted"><?php
          $row = null;
          foreach ($dbProviders as $d) { if ($d['name'] === $name) { $row = $d; break; } }
          echo h($row && $row['note'] ? $row['note'] : '');
        ?></td>
        <td>
          <?php if ($inDb): ?>
            <a class="btn2" href="?edit=<?= h(urlencode($name)) ?>">編輯</a>
          <?php else: ?>
            <a class="btn2" href="?new=1&amp;prefill=<?= h(urlencode($name)) ?>">改存資料庫</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="muted" style="font-size:12px;margin-top:10px">
    來源是 config.php 的上游無法在這裡編輯 —— 它們定義在主機檔案上。
    按「改存資料庫」可以建立一份可線上維護的設定，之後就以資料庫為準。
  </div>
</div>

<?php
// ── 新增／編輯表單 ────────────────────────────────────────────────
if ($isNew || $editRow):
    $prefillName = isset($_GET['prefill']) ? $_GET['prefill'] : '';
    $prefill = $prefillName && isset($effective[$prefillName]) ? $effective[$prefillName] : null;

    $fName = $editRow ? $editRow['name'] : ($prefill ? $prefillName : '');
    $fLabel = $editRow ? $editRow['label'] : ($prefill ? $prefill['label'] : '');
    $fDriver = $editRow ? $editRow['driver'] : ($prefill ? $prefill['driver'] : 'payuni');
    $fEnabled = $editRow ? (int) $editRow['enabled'] : 1;
    $fNote = $editRow ? $editRow['note'] : '';
    $fEndpoints = $editRow ? json_decode($editRow['endpoints'], true) : ($prefill ? $prefill['endpoints'] : array());
    if (!is_array($fEndpoints)) { $fEndpoints = array(); }
    $hasCreds = $editRow && !empty($editRow['credentials_enc']);
?>
<div class="card" style="border:2px solid #5a3d99">
  <h3 style="margin-top:0;font-size:17px">
    <?= $editRow ? '編輯上游：' . h($editRow['name']) : '新增上游' ?>
  </h3>

  <form method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px">
      <label>代號（程式用，不可重複）<br>
        <input type="text" name="name" value="<?= h($fName) ?>"
               <?= $editRow ? 'readonly style="background:#f0f0f0;"' : '' ?>
               pattern="[a-z0-9_]{2,32}" required
               style="width:100%;padding:8px">
        <span class="muted" style="font-size:12px">小寫英數與底線，建立後不可修改</span>
      </label>
      <label>顯示名稱<br>
        <input type="text" name="label" value="<?= h($fLabel) ?>" required style="width:100%;padding:8px">
      </label>
      <label>驅動程式<br>
        <select name="driver" style="width:100%;padding:8px">
          <?php foreach (array_keys($driverFields) as $d): ?>
            <option value="<?= h($d) ?>" <?= $d === $fDriver ? 'selected' : '' ?>><?= h($d) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="muted" style="font-size:12px">對應 providers/&lt;驅動&gt;.php</span>
      </label>
      <label>備註<br>
        <input type="text" name="note" value="<?= h($fNote) ?>" style="width:100%;padding:8px">
      </label>
    </div>

    <label style="display:block;margin:14px 0">
      <input type="checkbox" name="enabled" value="1" <?= $fEnabled ? 'checked' : '' ?>>
      啟用（停用後不會被拿來處理交易，但歷史紀錄仍保留歸屬）
    </label>

    <h4 style="margin:18px 0 8px;font-size:15px">API 端點</h4>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px">
      <?php foreach ($driverEndpoints[$fDriver] as $key => $label): ?>
        <label><?= h($label) ?><br>
          <input type="text" name="ep_<?= h($key) ?>"
                 value="<?= h(isset($fEndpoints[$key]) ? $fEndpoints[$key] : '') ?>"
                 style="width:100%;padding:8px;font-size:13px">
        </label>
      <?php endforeach; ?>
    </div>

    <h4 style="margin:18px 0 8px;font-size:15px">串接金鑰</h4>
    <div class="muted" style="margin-bottom:10px;font-size:13px">
      <?php if ($hasCreds): ?>
        目前已設定金鑰。<strong>基於安全，這裡不會顯示既有內容</strong> ——
        全部留空代表不變更；要更換的話請把整組欄位都重新填寫。
      <?php else: ?>
        金鑰會加密後存進資料庫，之後不會再顯示出來。
      <?php endif; ?>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px">
      <?php foreach ($driverFields[$fDriver] as $key => $label): ?>
        <label><?= h($label) ?><br>
          <input type="password" name="cr_<?= h($key) ?>" autocomplete="new-password"
                 placeholder="<?= $hasCreds ? '留空 = 不變更' : '' ?>"
                 style="width:100%;padding:8px;font-size:13px">
        </label>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:20px;display:flex;gap:10px;align-items:center">
      <button type="submit"
              style="background:#5a3d99;color:#fff;border:0;padding:10px 24px;
                     border-radius:6px;cursor:pointer;font-size:15px">儲存</button>
      <a class="btn2" href="providers.php">取消</a>
    </div>
  </form>

  <?php if ($editRow): ?>
  <form method="post" style="margin-top:20px;padding-top:16px;border-top:1px solid #eee"
        onsubmit="return confirm('確定要刪除這家上游嗎？');">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
    <input type="hidden" name="name" value="<?= h($editRow['name']) ?>">
    <button type="submit"
            style="background:#fff;color:#c62828;border:1px solid #c62828;
                   padding:8px 18px;border-radius:6px;cursor:pointer">刪除這家上游</button>
    <span class="muted" style="margin-left:10px;font-size:13px">
      已有交易紀錄的上游無法刪除，請改為停用
    </span>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
