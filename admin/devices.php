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
$msg = null;
$err = null;

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
            }
        } catch (Exception $e) {
            $err = '操作失敗：' . $e->getMessage();
        }
    }
}

// 左右分欄的目前分頁
$section = (isset($_REQUEST['section']) && $_REQUEST['section'] === 'enroll_cards') ? 'enroll_cards' : 'pos';

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
    array('label' => 'POS機管理', 'key' => 'pos'),
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

<?php if ($section === 'enroll_cards'): ?>
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

<?php else: ?>

<div class="card">
  <div class="muted">
    共 <?= number_format($totalDevices) ?> 台。App 每次交易會自動更新機器資料；
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
