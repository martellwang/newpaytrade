<?php
/**
 * App 安裝檔下載頁。
 *
 * 存在的原因：手機上不方便打長網址，也需要一個地方說明「兩個版本差在哪、
 * 該裝哪一個」—— 裝錯的後果不一樣（桌面版會讓手機跳出主畫面選擇框，
 * 選錯還會被鎖在收銀畫面裡）。
 */

require_once __DIR__ . '/auth.php';
admin_require_login();
require_once __DIR__ . '/layout.php';

// 顯示檔案日期讓人確認拿到的是不是最新版 —— 先前就發生過下載到舊版而
// 不自知的情況（改了半天以為沒生效）。
$privateDir = __DIR__ . '/../../../private/';
$builds = array(
    'pos' => array(
        'file' => 'newpaytrade-pos.apk',
        'title' => '收銀機版（kiosk）',
        'desc' => '給專用收銀機（如 Nexgo N5）。會設為裝置桌面，開機直接進收銀畫面，'
                . '收銀員按 HOME 鍵也回到這裡、離不開去動系統其他功能。',
        'warn' => '不要裝在個人手機上。',
    ),
    'phone' => array(
        'file' => 'newpaytrade-pos-phone.apk',
        'title' => '一般裝置版（standard）',
        'desc' => '給一般 Android 手機或平板。就是普通的 App，從桌面圖示點進去，'
                . '按 HOME 鍵可以正常離開。',
        'warn' => '',
    ),
);

admin_header('安裝檔', 'apk.php');
?>

<h2>App 安裝檔</h2>
<p style="color:#666">兩個版本的功能完全相同，差別只在「要不要當裝置桌面」。</p>

<?php foreach ($builds as $key => $b):
    $path = $privateDir . $b['file'];
    $exists = is_file($path);
?>
<div style="border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:16px">
  <h3 style="margin:0 0 8px"><?= h($b['title']) ?></h3>
  <p style="margin:0 0 8px"><?= h($b['desc']) ?></p>
  <?php if ($b['warn']): ?>
    <p style="margin:0 0 8px;color:#b26a00"><strong>⚠️ <?= h($b['warn']) ?></strong></p>
  <?php endif; ?>
  <?php if ($exists): ?>
    <p style="margin:0 0 12px;color:#666;font-size:13px">
      版本日期：<?= h(date('Y-m-d H:i', filemtime($path))) ?>
      ／檔案大小：<?= h(number_format(filesize($path) / 1048576, 1)) ?> MB
    </p>
    <a href="download_apk.php?variant=<?= h($key) ?>"
       style="display:inline-block;background:#5b3fa8;color:#fff;padding:10px 20px;
              border-radius:6px;text-decoration:none">下載安裝</a>
  <?php else: ?>
    <p style="color:#c62828">尚未上傳（<?= h($b['file']) ?>）</p>
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
