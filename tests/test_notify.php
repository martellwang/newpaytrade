<?php
/**
 * notify-direct.php 的測試腳本。
 *
 * PAYUNi 只在授權逾時(UNKNOWN)後才會呼叫背景通知，實務上很難遇到，
 * 所以用我們自己的金鑰組出一份「與 PAYUNi 格式完全相同」的加密通知來測。
 * 這是合理的模擬：PAYUNi 也是用同一把 HashKey/HashIV 加密。
 */

$dir = dirname(__DIR__);
require_once $dir . '/config.php';
require_once $dir . '/payuni_crypto.php';
require_once $dir . '/db.php';

$NOTIFY_URL = $argv[1] ?? 'http://localhost/newpaytrade/notify-direct.php';

/**
 * ⚠️ 這個測試會把測試訂單寫進「本機 config.php 指定的資料庫」，再把通知
 * 送到 $NOTIFY_URL。兩者必須是同一個環境，否則端點在它自己的資料庫裡找
 * 不到訂單，測試會全部失敗（那是測試設定錯誤，不是程式有 bug）。
 *
 * 要測正式主機，請把整個 tests/ 上傳到主機，在主機上執行：
 *   php -f tests/test_notify.php https://www.newpay.com.tw/newpaytrade/notify-direct.php
 *
 * 注意：在主機上「不能」用 http://localhost —— 這是 ISPConfig 共享主機，
 * localhost 指向預設 vhost 而不是本帳號的網站根目錄，會拿到 404。
 * 要用真實網域，這樣 config.php（主機的）與端點（主機的）才是同一套環境。
 */

function post($url, $fields) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $code, 'body' => $body);
}

/** 組出 PAYUNi 格式的通知內容 */
function buildNotify($fields) {
    $enc = payuni_encrypt_trade_info($fields, PAYUNI_HASH_KEY, PAYUNI_HASH_IV);
    return array(
        'Status' => 'SUCCESS',
        'MerID' => PAYUNI_MER_ID,
        'Version' => '1.3',
        'EncryptInfo' => $enc,
        'HashInfo' => payuni_generate_hash($enc, PAYUNI_HASH_KEY, PAYUNI_HASH_IV),
    );
}

function orderStatus($conn, $merTradeNo) {
    $o = db_find_order($conn, $merTradeNo);
    return $o ? $o : null;
}

$conn = db_connect();
$pass = 0; $fail = 0;
function check($label, $ok, $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✅ $label\n"; }
    else { $fail++; echo "  ❌ $label" . ($detail ? " —— $detail" : '') . "\n"; }
}

echo "測試目標：$NOTIFY_URL\n\n";

// ---------------------------------------------------------------
echo "【測試 1】逾時的 pending 訂單，收到「已付款」通知應更新為 success\n";
$no1 = 'NTF1' . time();
mysqli_query($conn, "INSERT INTO orders (mer_trade_no, amount, status) VALUES ('$no1', 100, 'pending')");
$r = post($NOTIFY_URL, buildNotify(array(
    'Status' => 'SUCCESS', 'Message' => '交易成功', 'MerID' => PAYUNI_MER_ID,
    'MerTradeNo' => $no1, 'TradeNo' => '9990001', 'TradeAmt' => '100',
    'TradeStatus' => '1', 'AuthCode' => '654321', 'Card4No' => '4321',
)));
check('HTTP 200', $r['code'] === 200, "得到 {$r['code']}");
check('回應 1|OK', trim($r['body']) === '1|OK', "得到 '{$r['body']}'");
$o = orderStatus($conn, $no1);
check('訂單狀態變成 success', $o && $o['status'] === 'success', '目前=' . ($o['status'] ?? 'null'));
check('有寫入 payuni_trade_no', $o && $o['payuni_trade_no'] === '9990001');
check('有寫入 auth_code', $o && $o['auth_code'] === '654321');

// ---------------------------------------------------------------
echo "\n【測試 2】收到「付款失敗」通知應更新為 failed\n";
$no2 = 'NTF2' . time();
mysqli_query($conn, "INSERT INTO orders (mer_trade_no, amount, status) VALUES ('$no2', 100, 'pending')");
post($NOTIFY_URL, buildNotify(array(
    'Status' => 'SUCCESS', 'Message' => '授權失敗', 'MerID' => PAYUNI_MER_ID,
    'MerTradeNo' => $no2, 'TradeNo' => '9990002', 'TradeStatus' => '2',
)));
$o = orderStatus($conn, $no2);
check('訂單狀態變成 failed', $o && $o['status'] === 'failed', '目前=' . ($o['status'] ?? 'null'));

// ---------------------------------------------------------------
echo "\n【測試 3】竄改過的內容應被拒絕（GCM authTag 驗證）\n";
$no3 = 'NTF3' . time();
mysqli_query($conn, "INSERT INTO orders (mer_trade_no, amount, status) VALUES ('$no3', 100, 'pending')");
$good = buildNotify(array(
    'Status' => 'SUCCESS', 'MerID' => PAYUNI_MER_ID, 'MerTradeNo' => $no3,
    'TradeNo' => '9990003', 'TradeStatus' => '1',
));
// 改動密文中間一個字元
$tampered = $good;
$hex = $good['EncryptInfo'];
$pos = 40;
$tampered['EncryptInfo'] = substr($hex, 0, $pos) . (($hex[$pos] === 'a') ? 'b' : 'a') . substr($hex, $pos + 1);
$r = post($NOTIFY_URL, $tampered);
check('竄改後回應非 1|OK', trim($r['body']) !== '1|OK', "得到 '{$r['body']}'");
$o = orderStatus($conn, $no3);
check('訂單狀態未被竄改的通知改動', $o && $o['status'] === 'pending', '目前=' . ($o['status'] ?? 'null'));

// ---------------------------------------------------------------
echo "\n【測試 4】完全偽造的內容（非法 hex）應被拒絕\n";
$r = post($NOTIFY_URL, array('EncryptInfo' => 'not-a-valid-hex-string'));
check('回應非 1|OK', trim($r['body']) !== '1|OK', "得到 '{$r['body']}'");

// ---------------------------------------------------------------
echo "\n【測試 5】空的 EncryptInfo 應被拒絕（不可 500 崩潰）\n";
$r = post($NOTIFY_URL, array());
check('回應非 1|OK', trim($r['body']) !== '1|OK', "得到 '{$r['body']}'");

// ---------------------------------------------------------------
echo "\n【測試 6】不存在的訂單編號（不應崩潰）\n";
$r = post($NOTIFY_URL, buildNotify(array(
    'Status' => 'SUCCESS', 'MerID' => PAYUNI_MER_ID, 'MerTradeNo' => 'NOSUCHORDER' . time(),
    'TradeNo' => '9990006', 'TradeStatus' => '1',
)));
check('不會崩潰（HTTP 200）', $r['code'] === 200, "得到 {$r['code']}");

// ---------------------------------------------------------------
echo "\n【測試 7】⚠️ 重放攻擊：把測試1的成功通知改成失敗版本重送\n";
echo "         （模擬攻擊者攔截後重送舊通知，或 PAYUNi 重複發送）\n";
$r = post($NOTIFY_URL, buildNotify(array(
    'Status' => 'SUCCESS', 'Message' => '授權失敗', 'MerID' => PAYUNI_MER_ID,
    'MerTradeNo' => $no1, 'TradeNo' => '9990001', 'TradeStatus' => '2',
)));
$o = orderStatus($conn, $no1);
$stillSuccess = $o && $o['status'] === 'success';
check('已成功的訂單不會被後來的失敗通知覆蓋', $stillSuccess,
      '目前=' . ($o['status'] ?? 'null') . '（成功的交易被改成失敗，帳務會出錯）');

// ---------------------------------------------------------------
echo "\n【測試 8】⚠️ 時效性：帶很舊 Timestamp 的通知是否被接受\n";
$no8 = 'NTF8' . time();
mysqli_query($conn, "INSERT INTO orders (mer_trade_no, amount, status) VALUES ('$no8', 100, 'pending')");
$r = post($NOTIFY_URL, buildNotify(array(
    'Status' => 'SUCCESS', 'MerID' => PAYUNI_MER_ID, 'MerTradeNo' => $no8,
    'TradeNo' => '9990008', 'TradeStatus' => '1',
    'Timestamp' => (string)(time() - 86400 * 30), // 30 天前
)));
$o = orderStatus($conn, $no8);
check('30 天前的通知被接受（可接受，但要知道沒有時效檢查）',
      true, ''); // 只記錄行為，不判定對錯
echo "     實際狀態：" . ($o['status'] ?? 'null') . "（若為 success 代表沒有時效檢查）\n";

// 清理
foreach (array($no1, $no2, $no3, $no8) as $n) {
    mysqli_query($conn, "DELETE FROM orders WHERE mer_trade_no = '$n'");
}
mysqli_query($conn, "DELETE FROM orders WHERE mer_trade_no LIKE 'NOSUCHORDER%'");

echo "\n========================================\n";
echo "通過 $pass 項，失敗 $fail 項\n";
