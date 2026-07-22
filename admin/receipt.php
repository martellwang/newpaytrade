<?php
/**
 * 列印簽單範本編輯器。
 *
 * 用「一行一列」的表單編輯：每行可設文字（含 {{參數}}）、字級、粗體、對齊。
 * 交易資料一律用 {{參數}} 包住，列印時由收銀機替換成該筆交易的值。
 * 原則上一行不混字級（符合實體印表機一行一種字的限制）。
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
admin_require_login();

$conn = db_connect();
db_create_app_settings_table_if_not_exists($conn);

$flash = null; $flashOk = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $flash = '表單已逾時，請重新整理再存';
    } else {
        $texts  = isset($_POST['line_text']) ? (array) $_POST['line_text'] : array();
        $sizes  = isset($_POST['line_size']) ? (array) $_POST['line_size'] : array();
        $aligns = isset($_POST['line_align']) ? (array) $_POST['line_align'] : array();
        $bolds  = isset($_POST['line_bold']) ? (array) $_POST['line_bold'] : array();
        $lines = array();
        foreach ($texts as $i => $t) {
            $lines[] = array(
                'text'  => (string) $t,
                'size'  => isset($sizes[$i]) ? $sizes[$i] : 'normal',
                'align' => isset($aligns[$i]) ? $aligns[$i] : 'left',
                'bold'  => isset($bolds[$i]),
                'keep_empty' => true,   // 空行當作間隔保留，由使用者自己刪
            );
        }
        db_save_receipt_lines($conn, $lines);
        $flashOk = true; $flash = '已儲存列印範本';
    }
}

$lines = db_get_receipt_lines($conn);
$placeholders = db_receipt_placeholders();
$csrf = admin_csrf_token();

// 預覽用的範例資料（跟 {{參數}} 對應）
$sample = array(
    'copyLabel' => '存根聯（店家存查）',
    'storeName' => '新零售行銷 第一店',
    'merchantName' => '新零售行銷股份有限公司',
    'storeCode' => 'NPAA000001',
    'time' => date('Y-m-d H:i:s'),
    'amount' => '1,280',
    'paymentMethod' => '信用卡',
    'card6No' => '480090',
    'card4No' => '2491',
    'cardBank' => '玉山銀行（808）',
    'authCode' => '295724',
    'payuniTradeNo' => '1784609978052409954',
    'storeOrderNo' => 'NPAA000001-20260722153012-042',
    'merTradeNo' => 'POS1784609939417',
    'provider' => '統一金流 PAYUNi',
);

admin_header('列印範本', 'receipt.php');
?>

<style>
.rcpt-wrap { display:flex; gap:18px; align-items:flex-start; }
.rcpt-editor { flex:1; min-width:0; }
.rcpt-side { flex:0 0 320px; }
.rline { display:flex; gap:8px; align-items:center; padding:8px; border:1px solid #eee;
         border-radius:8px; margin-bottom:8px; background:#fafafa; }
.rline .txt { flex:1; min-width:0; }
.rline input[type=text] { width:100%; }
.rline select, .rline label.chk { font-size:13px; }
.rline .drag { color:#bbb; cursor:default; user-select:none; }
.rline .del { background:#fff; color:#c62828; border:1px solid #c62828; border-radius:6px;
              padding:6px 10px; cursor:pointer; font-size:13px; }
.ph-list { font-size:13px; line-height:1.9; }
.ph-list code { background:#f3f0fb; color:#5a3d99; padding:1px 6px; border-radius:5px; cursor:pointer; }
.ph-list .d { color:#888; }
/* 預覽：模擬 58mm 熱感紙 */
.paper { width:320px; margin:0 auto; background:#fff; border:1px solid #ddd; border-radius:6px;
         padding:14px 12px; font-family:"Noto Sans TC",monospace; color:#111; }
.paper .pl { white-space:pre-wrap; word-break:break-all; line-height:1.5; }
.sz-small  { font-size:12px; } .sz-normal { font-size:15px; }
.sz-large  { font-size:20px; } .sz-xlarge { font-size:26px; }
.al-left { text-align:left; } .al-center { text-align:center; } .al-right { text-align:right; }
.paper .logo { text-align:center; margin-bottom:8px; }
.paper .logo img { max-width:70%; max-height:80px; }
</style>

<?php if ($flash): ?>
  <div class="card" style="border-left:4px solid <?= $flashOk ? '#2e7d32' : '#c62828' ?>"><?= h($flash) ?></div>
<?php endif; ?>

<div class="card">
  <strong style="font-size:16px">列印簽單範本</strong>
  <div class="muted" style="margin-top:4px">
    一行一列編輯。文字裡用 <code>{{參數}}</code> 包住交易資料，列印時自動替換。
    每行可獨立設字級與粗體；原則上一行不混字級。Logo 由各商店在自己的客戶後台上傳。
  </div>
</div>

<form method="post" onsubmit="return syncBeforeSubmit()">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <div class="rcpt-wrap">
    <div class="rcpt-editor">
      <div class="card">
        <div id="rows"></div>
        <button type="button" class="btn2" onclick="addRow()">＋ 新增一行</button>
        <button type="submit" style="margin-left:8px">儲存範本</button>
      </div>
    </div>

    <div class="rcpt-side">
      <div class="card">
        <h3 style="margin:0 0 8px;font-size:15px">即時預覽</h3>
        <div class="paper">
          <div class="logo"><img src="" alt="" id="pvLogo" style="display:none">
            <div class="muted" style="font-size:11px" id="pvLogoHint">（商店 logo 由客戶後台上傳）</div>
          </div>
          <div id="preview"></div>
        </div>
      </div>
      <div class="card">
        <h3 style="margin:0 0 8px;font-size:15px">可用參數（點一下複製）</h3>
        <div class="ph-list">
          <?php foreach ($placeholders as $key => $desc): ?>
            <div><code onclick="copyPh('{{<?= h($key) ?>}}')">{{<?= h($key) ?>}}</code>
                 <span class="d"><?= h($desc) ?></span></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</form>

<script>
const SAMPLE = <?= json_encode($sample, JSON_UNESCAPED_UNICODE) ?>;
const INIT_LINES = <?= json_encode($lines, JSON_UNESCAPED_UNICODE) ?>;
let seq = 0;

function esc(s){ return (s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

function addRow(line){
  line = line || {text:'', size:'normal', bold:false, align:'left'};
  const i = seq++;
  const div = document.createElement('div');
  div.className = 'rline';
  div.innerHTML = `
    <span class="drag">≡</span>
    <span class="txt"><input type="text" name="line_text[${i}]" value="${esc(line.text)}"
        oninput="renderPreview()" placeholder="文字，可含 {{參數}}"></span>
    <select name="line_size[${i}]" onchange="renderPreview()">
      <option value="small">小</option><option value="normal">中</option>
      <option value="large">大</option><option value="xlarge">特大</option>
    </select>
    <select name="line_align[${i}]" onchange="renderPreview()">
      <option value="left">靠左</option><option value="center">置中</option><option value="right">靠右</option>
    </select>
    <label class="chk"><input type="checkbox" name="line_bold[${i}]" onchange="renderPreview()"> 粗體</label>
    <button type="button" class="del" onclick="this.closest('.rline').remove(); renderPreview()">刪除</button>`;
  document.getElementById('rows').appendChild(div);
  div.querySelector(`[name="line_size[${i}]"]`).value = line.size || 'normal';
  div.querySelector(`[name="line_align[${i}]"]`).value = line.align || 'left';
  if (line.bold) div.querySelector(`[name="line_bold[${i}]"]`).checked = true;
  renderPreview();
}

function fill(text){
  return text.replace(/\{\{\s*([a-zA-Z]+)\s*\}\}/g, (m, k) => (k in SAMPLE) ? SAMPLE[k] : m);
}

function renderPreview(){
  const rows = document.querySelectorAll('#rows .rline');
  let html = '';
  rows.forEach(r => {
    const text = r.querySelector('input[type=text]').value;
    const size = r.querySelector('select[name^="line_size"]').value;
    const align = r.querySelector('select[name^="line_align"]').value;
    const bold = r.querySelector('input[type=checkbox]').checked;
    html += `<div class="pl sz-${size} al-${align}" style="font-weight:${bold?'bold':'normal'}">${esc(fill(text)) || '&nbsp;'}</div>`;
  });
  document.getElementById('preview').innerHTML = html;
}

function copyPh(t){ navigator.clipboard && navigator.clipboard.writeText(t); }
function syncBeforeSubmit(){ return true; }

INIT_LINES.forEach(addRow);
if (!INIT_LINES.length) addRow();
</script>

<?php admin_footer(); ?>
