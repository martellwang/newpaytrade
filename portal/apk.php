<?php
/**
 * 客戶後台 — App 安裝檔下載。列出最新版本，下載一樣要先登入客戶後台。
 * 檔案在 web 目錄外的 private/apk/（版本化檔名見 admin/apk.php）。
 */

require_once __DIR__ . '/auth.php';
portal_require_login();
require_once __DIR__ . '/layout.php';

$apkDir = __DIR__ . '/../../../private/apk/';

$variants = array(
    'pos'   => array('title' => '收銀機版（kiosk）',
                     'desc' => '給專用收銀機（如 Nexgo N5）。會設為裝置桌面，開機直接進收銀畫面。',
                     'warn' => '不要裝在個人手機上。'),
    'phone' => array('title' => '一般裝置版（standard）',
                     'desc' => '給一般 Android 手機或平板，是普通 App，可正常離開。',
                     'warn' => ''),
);

function portal_apk_versions($apkDir, $variant, $keep = 5) {
    $list = array();
    foreach (glob($apkDir . $variant . '_*.apk') ?: array() as $path) {
        $base = basename($path);
        if (!preg_match('/^' . preg_quote($variant, '/') . '_(\d+)_(.+)\.apk$/', $base, $m)) continue;
        $list[] = array('file' => $base, 'code' => (int) $m[1], 'name' => $m[2],
                        'mtime' => filemtime($path), 'size' => filesize($path));
    }
    usort($list, function ($a, $b) { return $b['code'] - $a['code']; });
    return array_slice($list, 0, $keep);
}

portal_header('安裝檔', 'apk.php');
?>

<div class="card">
  <div class="muted">兩個版本功能相同，差別只在「要不要當裝置桌面」。安裝時 Android
    會問「是否允許從這個來源安裝」，允許即可。</div>
</div>

<?php foreach ($variants as $key => $b): $versions = portal_apk_versions($apkDir, $key); ?>
<div class="card">
  <h3 style="margin:0 0 6px;font-size:16px"><?= h($b['title']) ?></h3>
  <p style="margin:0 0 8px"><?= h($b['desc']) ?></p>
  <?php if ($b['warn']): ?><p style="margin:0 0 10px;color:#b26a00"><strong>⚠️ <?= h($b['warn']) ?></strong></p><?php endif; ?>
  <?php if (!$versions): ?>
    <p class="muted">尚未提供安裝檔。</p>
  <?php else: ?>
    <table>
      <thead><tr><th>版本</th><th>日期</th><th>大小</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($versions as $i => $v): ?>
        <tr>
          <td><?= h($v['name']) ?> (<?= (int)$v['code'] ?>)
              <?php if ($i === 0): ?><span class="badge s-success" style="margin-left:6px">最新</span><?php endif; ?></td>
          <td class="muted"><?= h(date('Y-m-d H:i', $v['mtime'])) ?></td>
          <td class="muted"><?= h(number_format($v['size']/1048576, 1)) ?> MB</td>
          <td><a class="btn2" href="download_apk.php?file=<?= h(urlencode($v['file'])) ?>">下載</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<?php portal_footer(); ?>
