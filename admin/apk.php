<?php
/**
 * App 安裝檔下載頁。
 *
 * ── 版本保留策略：每個型態各留最新 5 版 ──────────────────────
 *
 * APK 以版本化檔名存放於 web 目錄外的 private/apk/：
 *     pos_<versionCode>_<versionName>.apk       收銀機版（kiosk）
 *     phone_<versionCode>_<versionName>.apk     一般裝置版（standard）
 * 例：pos_13_0.13-dev.apk
 *
 * 這一頁掃描該目錄、依 versionCode 由新到舊排序，每個型態只保留最新 5 版，
 * 更舊的在載入時一併刪除（管理專用頁面，刪檔在此執行是合理的）。放在
 * web 根目錄之外，是因為 APK 內編譯了 BACKEND_API_KEY，反組譯就能取得。
 */

require_once __DIR__ . '/auth.php';
admin_require_login();
require_once __DIR__ . '/layout.php';

$apkDir = __DIR__ . '/../../../private/apk/';

$variants = array(
    'pos' => array(
        'title' => '收銀機版（kiosk）',
        'desc' => '給專用收銀機（如 Nexgo N5）。會設為裝置桌面，開機直接進收銀畫面，'
                . '收銀員按 HOME 鍵也回到這裡、離不開去動系統其他功能。',
        'warn' => '不要裝在個人手機上。',
    ),
    'phone' => array(
        'title' => '一般裝置版（standard）',
        'desc' => '給一般 Android 手機或平板。就是普通的 App，從桌面圖示點進去，'
                . '按 HOME 鍵可以正常離開。',
        'warn' => '',
    ),
);

const APK_KEEP = 5;

/**
 * 掃出某型態的所有版本，依 versionCode 由新到舊排序，並刪掉第 5 名以後的。
 * 回傳保留下來的清單（每項含 file / code / name / mtime / size）。
 */
function apk_versions($apkDir, $variant) {
    $list = array();
    foreach (glob($apkDir . $variant . '_*.apk') ?: array() as $path) {
        $base = basename($path);
        // pos_<code>_<name>.apk
        if (!preg_match('/^' . preg_quote($variant, '/') . '_(\d+)_(.+)\.apk$/', $base, $m)) {
            continue;
        }
        $list[] = array(
            'file' => $base,
            'code' => (int) $m[1],
            'name' => $m[2],
            'mtime' => filemtime($path),
            'size' => filesize($path),
            'path' => $path,
        );
    }
    // versionCode 由大到小
    usort($list, function ($a, $b) { return $b['code'] - $a['code']; });

    // 只保留最新 APK_KEEP 版，其餘刪除
    if (count($list) > APK_KEEP) {
        foreach (array_slice($list, APK_KEEP) as $old) {
            @unlink($old['path']);
        }
        $list = array_slice($list, 0, APK_KEEP);
    }
    return $list;
}

admin_header('安裝檔', 'apk.php');
?>

<h2>App 安裝檔</h2>
<p style="color:#666">兩個版本的功能完全相同，差別只在「要不要當裝置桌面」。每個型態保留最新 <?= APK_KEEP ?> 版。</p>

<?php foreach ($variants as $key => $b):
    $versions = apk_versions($apkDir, $key);
?>
<div style="border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:16px">
  <h3 style="margin:0 0 8px"><?= h($b['title']) ?></h3>
  <p style="margin:0 0 8px"><?= h($b['desc']) ?></p>
  <?php if ($b['warn']): ?>
    <p style="margin:0 0 10px;color:#b26a00"><strong>⚠️ <?= h($b['warn']) ?></strong></p>
  <?php endif; ?>

  <?php if (!$versions): ?>
    <p style="color:#c62828">尚未上傳任何版本（<?= h($key) ?>_*.apk）</p>
  <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:14px">
      <thead>
        <tr style="text-align:left;color:#666;border-bottom:1px solid #eee">
          <th style="padding:8px 6px">版本</th>
          <th style="padding:8px 6px">日期</th>
          <th style="padding:8px 6px">大小</th>
          <th style="padding:8px 6px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($versions as $i => $v): ?>
        <tr style="border-bottom:1px solid #f2f2f2">
          <td style="padding:8px 6px">
            <?= h($v['name']) ?> (<?= (int) $v['code'] ?>)
            <?php if ($i === 0): ?><span class="badge s-success" style="margin-left:6px">最新</span><?php endif; ?>
          </td>
          <td style="padding:8px 6px;color:#666"><?= h(date('Y-m-d H:i', $v['mtime'])) ?></td>
          <td style="padding:8px 6px;color:#666"><?= h(number_format($v['size'] / 1048576, 1)) ?> MB</td>
          <td style="padding:8px 6px">
            <a href="download_apk.php?file=<?= h(urlencode($v['file'])) ?>"
               style="display:inline-block;background:#5b3fa8;color:#fff;padding:6px 16px;
                      border-radius:6px;text-decoration:none">下載</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div style="border:1px solid #ddd;border-radius:8px;padding:16px;background:#fff8e1">
  <h3 style="margin:0 0 8px">安裝說明</h3>
  <p style="margin:0 0 6px">Android 會先問「要允許從這個來源安裝應用程式嗎」，允許即可。</p>
  <p style="margin:0;color:#666;font-size:13px">
    這個頁面擋在登入後面是有原因的：APK 裡編譯了後端 API 金鑰，
    任何人拿到安裝檔反組譯就能取得那把金鑰，所以不要把檔案轉傳給他人。
  </p>
</div>

<?php admin_footer(); ?>
