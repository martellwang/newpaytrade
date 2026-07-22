<?php
/**
 * 收銀機管理：登記過的機器、能力、交易統計，可編輯機台編號與備註，
 * 也可手動登記裝不了 App 的機器（例如 Ingenico APOS A8）。
 *
 * 「有無標準 NFC」欄位特別重要——實測發現專用 POS 機（Ingenico APOS A8、
 * Urovo i9100）都沒有，讀卡機是廠商專屬硬體要用各自 SDK。把結果留在
 * 資料庫，日後評估或採購機型時不用重測。
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/pagination.php';
admin_require_login();

$conn = db_connect();
db_create_app_settings_table_if_not_exists($conn);
db_create_device_model_tables_if_not_exists($conn);
$msg = null;
$err = null;

// 設備型號能力檢查清單的項目與狀態（由開發定義；資料庫只存各型號在各項的狀態）。
$DEVICE_CAP_ITEMS = array(
    'contactless' => '感應讀卡',
    'print'       => '熱感列印',
    'torch'       => '掃碼補光燈',
    'ota'         => 'OTA 自我更新',
    'stdnfc'      => '標準 NFC',
);
$DEVICE_CAP_STATES = array(
    'untested' => '未測', 'pass' => '通過', 'fail' => '失敗',
    'blocked'  => '受限', 'na' => '不適用',
);
$DEVICE_MODEL_STATUSES = array(
    'verified' => '已全驗證', 'partial' => '部分驗證', 'blocked' => '受限', 'pending' => '尚未測試',
);
$DEVICE_FILE_KINDS = array(
    'firmware' => '韌體', 'sdk' => 'SDK', 'doc' => '文件', 'other' => '其他',
);

$allowedPerPage = array(25, 50, 100);
$perPage = admin_resolve_page_size($conn, 'page_size_devices', $allowedPerPage);
$sort = admin_resolve_sort();
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $err = '表單已逾時，請重新送出';
    } else {
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        try {
            if ($action === 'update') {
                db_update_device_meta(
                    $conn,
                    $_POST['device_id'],
                    trim($_POST['terminal_uid']),
                    trim($_POST['name']),
                    trim($_POST['note'])
                );
                $msg = '已更新';
            } elseif ($action === 'add') {
                $deviceId = trim($_POST['device_id']);
                if ($deviceId === '') {
                    $err = '識別碼不可空白';
                } else {
                    db_manual_add_device($conn, array(
                        'deviceId' => $deviceId,
                        'serialNo' => trim($_POST['serial_no']),
                        'terminalUid' => trim($_POST['terminal_uid']),
                        'name' => trim($_POST['name']),
                        'brand' => trim($_POST['brand']),
                        'manufacturer' => trim($_POST['manufacturer']),
                        'model' => trim($_POST['model']),
                        'androidVersion' => trim($_POST['android_version']),
                        'hasNfc' => $_POST['has_nfc'],
                        'note' => trim($_POST['note']),
                    ));
                    $msg = '已新增機器';
                }
            } elseif ($action === 'delete') {
                db_delete_device($conn, $_POST['device_id']);
                $msg = '已刪除登記（歷史交易紀錄不受影響）';
            } elseif ($action === 'add_enroll_card') {
                db_add_enroll_card($conn, trim($_POST['card_uid']), trim($_POST['card_note']));
                $msg = '已新增登錄授權卡';
            } elseif ($action === 'delete_enroll_card') {
                db_delete_enroll_card($conn, (int) $_POST['card_id']);
                $msg = '已刪除登錄授權卡';
            } elseif ($action === 'dispatch') {
                // 派給客戶或經銷商（互斥）
                $target = isset($_POST['dispatch_target']) ? $_POST['dispatch_target'] : '';
                if ($target === 'merchant' && (int) $_POST['merchant_id'] > 0) {
                    db_dispatch_device_to_merchant($conn, $_POST['device_id'], (int) $_POST['merchant_id']);
                    $msg = '已派工給客戶';
                } elseif ($target === 'dealer' && (int) $_POST['dealer_id'] > 0) {
                    db_dispatch_device_to_dealer($conn, $_POST['device_id'], (int) $_POST['dealer_id']);
                    $msg = '已派工給經銷商';
                } else {
                    $err = '請選擇派工對象';
                }
            } elseif ($action === 'recall') {
                db_recall_device($conn, $_POST['device_id']);
                $msg = '已收回派工';
            } elseif ($action === 'add_model') {
                $brand = trim($_POST['brand']);
                $model = trim($_POST['model']);
                if ($brand === '' || $model === '') {
                    $err = '廠牌與型號都要填';
                } else {
                    db_add_device_model($conn, $brand, $model, (int) (isset($_POST['sort']) ? $_POST['sort'] : 0));
                    $msg = '已新增型號';
                }
            } elseif ($action === 'update_model') {
                // 一次更新一個型號的整體狀態、說明、以及各能力項狀態
                $mid = (int) $_POST['model_id'];
                $status = isset($_POST['status']) ? $_POST['status'] : 'pending';
                if (!isset($DEVICE_MODEL_STATUSES[$status])) $status = 'pending';
                db_update_device_model_status($conn, $mid, $status, trim($_POST['notes']));
                foreach ($DEVICE_CAP_ITEMS as $capKey => $capLabel) {
                    $state = isset($_POST['cap_' . $capKey]) ? $_POST['cap_' . $capKey] : 'untested';
                    if (!isset($DEVICE_CAP_STATES[$state])) $state = 'untested';
                    $detail = trim(isset($_POST['detail_' . $capKey]) ? $_POST['detail_' . $capKey] : '');
                    db_set_device_model_cap($conn, $mid, $capKey, $state, $detail);
                }
                $msg = '已更新型號能力狀態';
            } elseif ($action === 'add_model_log') {
                $mid = (int) $_POST['model_id'];
                $note = trim($_POST['note']);
                if ($note === '') { $err = '請輸入狀態紀錄內容'; }
                else { db_add_device_model_log($conn, $mid, $note); $msg = '已新增狀態紀錄'; }
            } elseif ($action === 'delete_model_file') {
                $fid = (int) $_POST['file_id'];
                $f = db_get_device_model_file($conn, $fid);
                if ($f) {
                    $path = device_model_files_dir() . '/' . (int) $f['model_id'] . '/' . basename($f['stored_name']);
                    if (is_file($path)) @unlink($path);
                    db_delete_device_model_file($conn, $fid);
                    $msg = '已刪除檔案';
                }
            } elseif ($action === 'upload_model_file') {
                $mid = (int) $_POST['model_id'];
                $kind = isset($_POST['kind']) && isset($DEVICE_FILE_KINDS[$_POST['kind']]) ? $_POST['kind'] : 'other';
                if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
                    $err = '請選擇要上傳的檔案';
                } elseif ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    // 常見是超過 upload_max_filesize（.user.ini 已拉到 128M）
                    $err = '上傳失敗（錯誤碼 ' . $_FILES['file']['error'] . '，可能是檔案過大）';
                } else {
                    $orig = $_FILES['file']['name'];
                    $ext = pathinfo($orig, PATHINFO_EXTENSION);
                    $safeExt = preg_replace('/[^A-Za-z0-9]/', '', $ext);
                    // 自產安全存檔名，避免原檔名帶奇怪字元或穿越
                    $stored = $kind . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . ($safeExt ? '.' . $safeExt : '');
                    $dir = device_model_files_dir() . '/' . $mid;
                    if (!is_dir($dir)) @mkdir($dir, 0770, true);
                    if (move_uploaded_file($_FILES['file']['tmp_name'], $dir . '/' . $stored)) {
                        db_add_device_model_file($conn, $mid, $kind, $orig, $stored, (int) $_FILES['file']['size']);
                        $msg = '已上傳檔案';
                    } else {
                        $err = '存檔失敗，請稍後再試';
                    }
                }
            }
        } catch (Exception $e) {
            $err = '操作失敗：' . $e->getMessage();
        }
    }
}

// 左右分欄的目前分頁
// 預設進「總覽」（上帝視角：機隊統計），而不是一長串機器清單。
$section = 'overview';
if (isset($_REQUEST['section']) &&
    in_array($_REQUEST['section'], array('overview', 'pos', 'device_models', 'enroll_cards'), true)) {
    $section = $_REQUEST['section'];
}

$totalDevices = db_count_devices($conn);
$totalPages = max(1, (int) ceil($totalDevices / $perPage));
$page = min($page, $totalPages);
$devices = db_list_devices($conn, $perPage, ($page - 1) * $perPage, $sort);

$baseParams = array('perPage' => $perPage, 'sort' => $sort);
$qs = http_build_query($baseParams);
// 編輯連結要帶著目前的分頁狀態，不然點「編輯」會跳回第 1 頁
$pageQs = $qs . '&page=' . $page;

$csrf = admin_csrf_token();
$editId = isset($_GET['edit']) ? $_GET['edit'] : null;
// 派工下拉用
$dispatchMerchants = db_list_merchants($conn);
$dispatchDealers = db_list_dealers($conn);

// 左側功能選單：目前只有「POS機管理」一個第一層項目，日後要加別種設備
// （例如標籤機、錢櫃）就在這裡多一個 node、右側多一段 render。
$navNodes = array(
    array('label' => '總覽', 'key' => 'overview'),
    array('label' => 'POS機管理', 'key' => 'pos'),
    array('label' => '設備型號', 'key' => 'device_models'),
    array('label' => '登錄授權卡', 'key' => 'enroll_cards'),
);
$enrollCards = ($section === 'enroll_cards') ? db_list_enroll_cards($conn) : array();

admin_header('設備管理', 'devices.php');
?>

<?php if ($msg): ?><div class="card" style="background:#e8f5e9"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="card" style="background:#ffebee"><?= h($err) ?></div><?php endif; ?>

<div class="split">
  <?php admin_render_split_nav($navNodes, $section, 'devices.php'); ?>
  <div class="split-body">

<?php if ($section === 'overview'): ?>

<?php
// ── 上帝視角：機隊統計 ──
$fleet = db_device_fleet_stats($conn);
$sumDev = 0; $sumEnrolled = 0; $sumOnline = 0; $sumTx = 0; $sumAmt = 0;
foreach ($fleet as $fr) {
    $sumDev += $fr['device_count']; $sumEnrolled += $fr['enrolled_count'];
    $sumOnline += $fr['online_cnt']; $sumTx += $fr['tx_count']; $sumAmt += $fr['success_amt'];
}
?>
<div class="card">
  <h3 style="margin-top:0;font-size:16px">機隊總覽</h3>
  <div class="kpi" style="margin-top:10px">
    <div><div class="lbl">登記機台</div><div class="val"><?= number_format($sumDev) ?></div></div>
    <div><div class="lbl">已入倉</div><div class="val"><?= number_format($sumEnrolled) ?></div></div>
    <div><div class="lbl">近 10 分鐘上線</div><div class="val" style="color:#2e7d32"><?= number_format($sumOnline) ?></div></div>
    <div><div class="lbl">機台總交易</div><div class="val"><?= number_format($sumTx) ?></div></div>
  </div>
  <div class="muted" style="font-size:12px;margin-top:10px">
    「上線」以 last_seen 判斷。⚠️ last_seen 目前只在<strong>交易</strong>時更新，所以這反映
    「最近有活動」，不完全等於「開機中」——要真正即時的開機存活需另加心跳（app 定時 ping），已排待開發。
    個別機台清單、派工與手動新增在「POS機管理」。
  </div>
</div>

<div class="card wrap">
  <h3 style="margin-top:0;font-size:16px">依廠牌型號</h3>
  <table>
    <thead>
      <tr>
        <th>廠牌 / 型號</th>
        <th class="right">登記</th>
        <th class="right">入倉</th>
        <th>存活狀態</th>
        <th class="right">交易筆數</th>
        <th class="right">成功金額</th>
        <th>交易量占比</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$fleet): ?>
        <tr><td colspan="7" class="muted">尚無登記機台。</td></tr>
      <?php endif; ?>
      <?php foreach ($fleet as $fr): ?>
      <?php $pct = $sumTx > 0 ? round($fr['tx_count'] * 100 / $sumTx) : 0; ?>
      <tr>
        <td><strong><?= h($fr['brand']) ?></strong> <?= h($fr['model']) ?></td>
        <td class="right"><?= number_format($fr['device_count']) ?></td>
        <td class="right"><?= number_format($fr['enrolled_count']) ?></td>
        <td>
          <?php if ($fr['online_cnt'] > 0): ?><span class="badge s-success">上線 <?= $fr['online_cnt'] ?></span> <?php endif; ?>
          <?php if ($fr['idle_cnt'] > 0): ?><span class="badge s-pending">閒置 <?= $fr['idle_cnt'] ?></span> <?php endif; ?>
          <?php if ($fr['offline_cnt'] > 0): ?><span class="badge s-failed">離線 <?= $fr['offline_cnt'] ?></span> <?php endif; ?>
          <?php if ($fr['online_cnt'] + $fr['idle_cnt'] + $fr['offline_cnt'] === 0): ?><span class="muted">—</span><?php endif; ?>
        </td>
        <td class="right"><?= number_format($fr['tx_count']) ?></td>
        <td class="right"><?= h(money($fr['success_amt'])) ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:6px">
            <div style="flex:1;background:#eee;border-radius:6px;height:10px;min-width:60px">
              <div style="width:<?= $pct ?>%;background:#5a3d99;height:10px;border-radius:6px"></div>
            </div>
            <span class="muted" style="font-size:12px"><?= $pct ?>%</span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php elseif ($section === 'enroll_cards'): ?>
<div class="card">
  <h3 style="margin-top:0;font-size:16px">進倉登錄授權卡</h3>
  <div class="muted">
    只有這裡列出的 NFC 卡，才能在收銀機 App 的隱藏入口（連點版本號 7 下）之後
    真正登錄設備。連點只是找入口，這張卡才是授權。<br>
    不知道卡片 UID？在收銀機上進到登錄畫面感應那張卡，畫面會顯示它的 UID，
    再回來這裡加入。
  </div>
</div>

<div class="card wrap">
  <table>
    <thead><tr><th>卡片 UID</th><th>備註</th><th>加入時間</th><th></th></tr></thead>
    <tbody>
      <?php if (!$enrollCards): ?>
        <tr><td colspan="4" class="muted">尚未設定任何授權卡。沒有授權卡就無法登錄設備。</td></tr>
      <?php endif; ?>
      <?php foreach ($enrollCards as $ec): ?>
      <tr>
        <td><strong style="letter-spacing:1px"><?= h($ec['card_uid']) ?></strong></td>
        <td class="muted"><?= h($ec['note'] ?: '') ?></td>
        <td class="muted"><?= h($ec['created_at']) ?></td>
        <td>
          <form method="post" style="display:inline"
                onsubmit="return confirm('確定刪除這張授權卡？');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="section" value="enroll_cards">
            <input type="hidden" name="action" value="delete_enroll_card">
            <input type="hidden" name="card_id" value="<?= (int) $ec['id'] ?>">
            <button type="submit" style="background:#fff;color:#c62828;border:1px solid #c62828;
                                         padding:4px 12px;border-radius:6px;cursor:pointer">刪除</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3 style="margin-top:0;font-size:15px">新增授權卡</h3>
  <form method="post" class="filters">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="section" value="enroll_cards">
    <input type="hidden" name="action" value="add_enroll_card">
    <div><label>卡片 UID（8～32 位 16 進位）</label>
      <input type="text" name="card_uid" required placeholder="例如 30D4810F" style="text-transform:uppercase"></div>
    <div style="flex:1"><label>備註</label>
      <input type="text" name="card_note" style="width:100%" placeholder="例如 進倉登錄專用卡"></div>
    <div><button type="submit">新增</button></div>
  </form>
</div>

<?php elseif ($section === 'device_models'): ?>

<?php
// 能力狀態 → 徽章樣式
$capStateBadge = function ($state) use ($DEVICE_CAP_STATES) {
    $label = isset($DEVICE_CAP_STATES[$state]) ? $DEVICE_CAP_STATES[$state] : $state;
    $cls = 's-pending'; $extra = '';
    if ($state === 'pass') $cls = 's-success';
    elseif ($state === 'fail' || $state === 'blocked') $cls = 's-failed';
    elseif ($state === 'na') { $cls = ''; $extra = ' style="background:#eee;color:#888"'; }
    return '<span class="badge ' . $cls . '"' . $extra . '>' . h($label) . '</span>';
};
$modelStatusBadge = function ($status) use ($DEVICE_MODEL_STATUSES) {
    $label = isset($DEVICE_MODEL_STATUSES[$status]) ? $DEVICE_MODEL_STATUSES[$status] : $status;
    $cls = 's-pending';
    if ($status === 'verified') $cls = 's-success';
    elseif ($status === 'blocked') $cls = 's-failed';
    return '<span class="badge ' . $cls . '">' . h($label) . '</span>';
};
$fmtSize = function ($b) {
    $b = (int) $b;
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024) return round($b / 1024) . ' KB';
    return $b . ' B';
};
$models = db_list_device_models($conn);
?>
<div class="card">
  <h3 style="margin-top:0;font-size:16px">設備型號 — 開發追蹤台</h3>
  <div class="muted">
    各廠牌型號的能力測試進度、狀態更新歷程，以及韌體／SDK／開發文件保存區。
    韌體更版或拿到 SDK，把對應能力改成「通過」、加一筆狀態紀錄即可洗刷冤名。
    個別機台的派工、使用狀況請看「POS機管理」。上傳上限 <?= h(ini_get('upload_max_filesize')) ?>。
  </div>
</div>

<?php foreach ($models as $mdl): ?>
<?php
  $mid = (int) $mdl['id'];
  $caps = db_get_device_model_caps($conn, $mid);
  $logs = db_list_device_model_log($conn, $mid, 8);
  $files = db_list_device_model_files($conn, $mid);
?>
<div class="card">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <h3 style="margin:0;font-size:16px"><?= h($mdl['brand']) ?> <?= h($mdl['model']) ?></h3>
    <?= $modelStatusBadge($mdl['status']) ?>
    <span class="muted" style="font-size:12px">更新於 <?= h($mdl['updated_at']) ?></span>
  </div>

  <!-- 能力檢查清單 + 整體狀態/說明：一張表單一起送 -->
  <form method="post" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="update_model">
    <input type="hidden" name="model_id" value="<?= $mid ?>">
    <div class="wrap">
      <table>
        <thead><tr><th>能力項目</th><th>狀態</th><th>方式 / 說明</th></tr></thead>
        <tbody>
          <?php foreach ($DEVICE_CAP_ITEMS as $capKey => $capLabel): ?>
          <?php $cs = isset($caps[$capKey]) ? $caps[$capKey] : array('state' => 'untested', 'detail' => ''); ?>
          <tr>
            <td><?= h($capLabel) ?></td>
            <td>
              <?= $capStateBadge($cs['state']) ?>
              <select name="cap_<?= h($capKey) ?>" style="margin-left:6px">
                <?php foreach ($DEVICE_CAP_STATES as $sk => $sl): ?>
                  <option value="<?= h($sk) ?>" <?= $cs['state'] === $sk ? 'selected' : '' ?>><?= h($sl) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="text" name="detail_<?= h($capKey) ?>" value="<?= h($cs['detail']) ?>"
                       style="width:100%" placeholder="例如 PiccManager／鎖定 ROM／需 SDK"></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-top:10px">
      <div><label>整體狀態</label>
        <select name="status">
          <?php foreach ($DEVICE_MODEL_STATUSES as $sk => $sl): ?>
            <option value="<?= h($sk) ?>" <?= $mdl['status'] === $sk ? 'selected' : '' ?>><?= h($sl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1;min-width:240px"><label>說明 / 已知問題</label>
        <input type="text" name="notes" value="<?= h($mdl['notes']) ?>" style="width:100%"></div>
      <div><button type="submit">儲存能力狀態</button></div>
    </div>
  </form>

  <!-- 狀態更新紀錄 -->
  <div style="margin-top:14px;padding-top:12px;border-top:1px solid #eee">
    <strong style="font-size:14px">狀態更新紀錄</strong>
    <form method="post" class="filters" style="margin:8px 0">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="add_model_log">
      <input type="hidden" name="model_id" value="<?= $mid ?>">
      <div style="flex:1"><input type="text" name="note" style="width:100%"
           placeholder="例如：2026-08 取得原廠 SDK，熱感列印重測通過"></div>
      <div><button type="submit">新增紀錄</button></div>
    </form>
    <?php if ($logs): ?>
      <div class="muted" style="font-size:13px">
        <?php foreach ($logs as $lg): ?>
          <div style="padding:3px 0;border-bottom:1px dashed #eee">
            <span style="color:#5a3d99"><?= h($lg['created_at']) ?></span>　<?= h($lg['note']) ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="muted" style="font-size:12px">尚無紀錄。</div>
    <?php endif; ?>
  </div>

  <!-- 開發元件檔案 -->
  <div style="margin-top:14px;padding-top:12px;border-top:1px solid #eee">
    <strong style="font-size:14px">開發元件（韌體／SDK／文件）</strong>
    <form method="post" enctype="multipart/form-data" class="filters" style="margin:8px 0">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="upload_model_file">
      <input type="hidden" name="model_id" value="<?= $mid ?>">
      <div><label>類型</label>
        <select name="kind">
          <?php foreach ($DEVICE_FILE_KINDS as $kk => $kl): ?>
            <option value="<?= h($kk) ?>"><?= h($kl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1"><label>檔案</label><input type="file" name="file" style="width:100%"></div>
      <div><button type="submit">上傳</button></div>
    </form>
    <?php if ($files): ?>
      <div class="wrap">
      <table>
        <thead><tr><th>類型</th><th>檔名</th><th class="right">大小</th><th>上傳時間</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($files as $f): ?>
          <tr>
            <td><span class="badge s-pending"><?= h(isset($DEVICE_FILE_KINDS[$f['kind']]) ? $DEVICE_FILE_KINDS[$f['kind']] : $f['kind']) ?></span></td>
            <td><?= h($f['orig_name']) ?></td>
            <td class="right"><?= h($fmtSize($f['size_bytes'])) ?></td>
            <td class="muted"><?= h($f['uploaded_at']) ?></td>
            <td>
              <a class="btn2" href="download_model_file.php?id=<?= (int) $f['id'] ?>">下載</a>
              <form method="post" style="display:inline" onsubmit="return confirm('確定刪除這個檔案？');">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="delete_model_file">
                <input type="hidden" name="file_id" value="<?= (int) $f['id'] ?>">
                <button type="submit" class="btn2" style="color:#c62828;border-color:#c62828">刪除</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php else: ?>
      <div class="muted" style="font-size:12px">尚無檔案。</div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<div class="card">
  <h3 style="margin-top:0;font-size:16px">新增型號</h3>
  <form method="post" class="filters">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="add_model">
    <div><label>廠牌 *</label><input type="text" name="brand" required placeholder="例如 PAX"></div>
    <div><label>型號 *</label><input type="text" name="model" required placeholder="例如 A920"></div>
    <div><label>排序</label><input type="number" name="sort" value="40" style="width:80px"></div>
    <div><button type="submit">新增</button></div>
  </form>
</div>

<?php else: ?>

<div class="card">
  <div class="muted">
    個別機台清單。總覽統計請看左側「總覽」。App 每次交易會自動更新機器資料；
    機台編號、名稱、備註是人工設定的，不會被自動覆蓋。
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
      <tr>
        <th><?php admin_sortable_header('序號', $sort, $baseParams); ?></th>
        <th>機台編號 / 名稱</th>
        <th>廠牌 / 型號</th>
        <th>序號 (UID)</th>
        <th>Android</th>
        <th>標準 NFC</th>
        <th>派工對象</th>
        <th class="right">交易筆數</th>
        <th class="right">成功金額</th>
        <th>最後使用</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$devices): ?>
        <tr><td colspan="11" class="muted">尚無登記的機器。App 完成第一筆交易後會自動出現。</td></tr>
      <?php endif; ?>
      <?php $seq = ($page - 1) * $perPage; ?>
      <?php foreach ($devices as $d): ?>
      <?php $seq++; ?>
      <tr>
        <td class="muted"><?= $seq ?></td>
        <td>
          <strong><?= h($d['terminal_uid'] ?: '—') ?></strong>
          <?php if ($d['name']): ?><div class="muted"><?= h($d['name']) ?></div><?php endif; ?>
        </td>
        <td>
          <strong><?= h($d['brand'] ?: $d['manufacturer'] ?: '—') ?></strong> <?= h($d['model'] ?: '') ?>
          <?php if ($d['product'] && $d['product'] !== $d['model']): ?>
            <div class="muted"><?= h($d['product']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <?= h($d['serial_no'] ?: '—') ?>
          <?php if (!empty($d['enrolled_at'])): ?>
            <span class="badge s-success" style="margin-left:4px" title="進倉登錄 <?= h($d['enrolled_at']) ?>">已進倉</span>
          <?php endif; ?>
          <div class="muted" style="font-size:11px"><?= h($d['device_id']) ?></div>
        </td>
        <td><?= h($d['android_version'] ?: '—') ?>
            <?= $d['android_sdk'] ? '<span class="muted">(SDK ' . h($d['android_sdk']) . ')</span>' : '' ?></td>
        <td>
          <?php if ($d['has_nfc'] === null): ?>
            <span class="muted">未知</span>
          <?php elseif ((int) $d['has_nfc'] === 1): ?>
            <span class="badge s-success">支援<?= ((int) $d['nfc_enabled'] === 1) ? '' : '（未開啟）' ?></span>
          <?php else: ?>
            <span class="badge s-failed">不支援</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!empty($d['dispatched_merchant_name'])): ?>
            <span class="badge s-success">客戶</span> <?= h($d['dispatched_merchant_name']) ?>
          <?php elseif (!empty($d['dispatched_dealer_name'])): ?>
            <span class="badge s-pending">經銷商</span> <?= h($d['dispatched_dealer_name']) ?>
          <?php else: ?>
            <span class="muted">未派工</span>
          <?php endif; ?>
        </td>
        <td class="right"><?= number_format((int) $d['order_cnt']) ?></td>
        <td class="right"><?= h(money($d['success_amt'])) ?></td>
        <td><?= h($d['last_seen']) ?></td>
        <td>
          <a class="btn2" href="?<?= h($pageQs) ?>&edit=<?= h(urlencode($d['device_id'])) ?>">編輯</a>
          <a class="btn2" href="index.php?q=<?= h(urlencode($d['device_id'])) ?>">交易</a>
        </td>
      </tr>
      <?php if ($editId === $d['device_id']): ?>
      <tr><td colspan="11" style="background:#faf8ff">
        <form method="post" class="filters">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="device_id" value="<?= h($d['device_id']) ?>">
          <div><label>機台編號</label><input type="text" name="terminal_uid" value="<?= h($d['terminal_uid']) ?>" placeholder="例如 POS-01"></div>
          <div><label>名稱</label><input type="text" name="name" value="<?= h($d['name']) ?>" placeholder="例如 一號櫃檯"></div>
          <div style="flex:1"><label>備註</label><input type="text" name="note" value="<?= h($d['note']) ?>" style="width:100%" placeholder="例如 讀卡需 Urovo SDK"></div>
          <div><button type="submit">儲存</button></div>
          <div><a class="btn2" href="?<?= h($qs) ?>&page=<?= $page ?>">取消</a></div>
        </form>

        <!-- 派工 -->
        <div style="margin-top:14px;padding-top:12px;border-top:1px solid #eee">
          <strong style="font-size:14px">派工</strong>
          <?php if (!empty($d['dispatched_merchant_name'])): ?>
            <span class="muted">　目前派給客戶：<?= h($d['dispatched_merchant_name']) ?>
              <?= $d['dispatched_at'] ? '（' . h($d['dispatched_at']) . '）' : '' ?></span>
          <?php elseif (!empty($d['dispatched_dealer_name'])): ?>
            <span class="muted">　目前派給經銷商：<?= h($d['dispatched_dealer_name']) ?></span>
          <?php else: ?>
            <span class="muted">　目前未派工</span>
          <?php endif; ?>
          <div class="muted" style="font-size:12px;margin:4px 0 8px">
            派給客戶 → 客戶自己決定用在哪家店；派給經銷商 → 經銷商自己決定派給旗下哪個客戶。
          </div>
          <form method="post" class="filters">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="dispatch">
            <input type="hidden" name="device_id" value="<?= h($d['device_id']) ?>">
            <div><label>派給客戶</label>
              <select name="merchant_id" onchange="if(this.value)this.form.dispatch_target.value='merchant'">
                <option value="">—</option>
                <?php foreach ($dispatchMerchants as $m): ?>
                  <option value="<?= (int) $m['id'] ?>"><?= h($m['customer_code'] . ' ' . $m['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div><label>或派給經銷商</label>
              <select name="dealer_id" onchange="if(this.value)this.form.dispatch_target.value='dealer'">
                <option value="">—</option>
                <?php foreach ($dispatchDealers as $dl): ?>
                  <option value="<?= (int) $dl['id'] ?>"><?= h($dl['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <input type="hidden" name="dispatch_target" value="">
            <div><button type="submit">派工</button></div>
          </form>
          <?php if (!empty($d['dispatched_merchant_name']) || !empty($d['dispatched_dealer_name'])): ?>
          <form method="post" style="margin-top:8px">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="recall">
            <input type="hidden" name="device_id" value="<?= h($d['device_id']) ?>">
            <button type="submit" class="btn2">收回派工</button>
          </form>
          <?php endif; ?>
        </div>

        <form method="post" style="margin-top:14px;padding-top:12px;border-top:1px solid #eee"
              onsubmit="return confirm('確定刪除這台機器的登記？歷史交易紀錄不受影響。');">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="device_id" value="<?= h($d['device_id']) ?>">
          <button type="submit" style="background:#c62828">刪除此機器登記</button>
        </form>
      </td></tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <?php admin_render_pager($page, $totalPages, $qs); ?>
</div>

<div class="card">
  <h3 style="margin-top:0;font-size:16px">手動新增機器</h3>
  <div class="muted" style="margin-bottom:12px">
    用於<strong>裝不了 App</strong> 的機器（例如 Ingenico APOS A8 需要原廠簽章授權），
    或想先把評估中的機型記錄下來。識別碼請填一個不會重複的值，例如機器序號。
  </div>
  <form method="post" class="filters">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="add">
    <div><label>識別碼 *</label><input type="text" name="device_id" required placeholder="序號或自訂"></div>
    <div><label>序號 (UID)</label><input type="text" name="serial_no"></div>
    <div><label>機台編號</label><input type="text" name="terminal_uid" placeholder="POS-02"></div>
    <div><label>名稱</label><input type="text" name="name"></div>
    <div><label>廠牌</label><input type="text" name="brand" placeholder="Ingenico"></div>
    <div><label>製造商</label><input type="text" name="manufacturer"></div>
    <div><label>型號</label><input type="text" name="model" placeholder="APOS A8"></div>
    <div><label>Android 版本</label><input type="text" name="android_version"></div>
    <div>
      <label>標準 NFC</label>
      <select name="has_nfc">
        <option value="">未知</option>
        <option value="0">不支援</option>
        <option value="1">支援</option>
      </select>
    </div>
    <div style="flex:1"><label>備註</label><input type="text" name="note" style="width:100%"></div>
    <div><button type="submit">新增</button></div>
  </form>
</div>

<div class="card muted">
  <strong>關於「標準 NFC」欄位</strong><br>
  這欄只表示機器有沒有<strong>標準 Android NFC</strong>（<code>NfcAdapter</code>），
  <strong>不等於「能不能感應讀卡」</strong>。實測：專用支付終端機（Nexgo N5、
  Ingenico APOS A8、Urovo i9100）標準 NFC 幾乎都是「不支援」，因為它們的讀卡機是
  <strong>廠商專屬硬體</strong>，要透過各自的 SDK 存取（我們的 App 已透過 Nexgo SDK
  在 N5 上讀悠遊卡／一卡通／店員卡）。所以「標準 NFC 不支援」的機器，多數仍可用
  廠商讀卡機感應，並非只能手動輸入卡號。
</div>

<?php endif; /* section */ ?>

  </div><!-- .split-body -->
</div><!-- .split -->

<?php admin_footer(); ?>
