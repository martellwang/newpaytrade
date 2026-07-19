<?php
/** 交易紀錄匯出 CSV，篩選條件與列表頁一致，方便丟進 Excel 對帳 */

require_once __DIR__ . '/auth.php';
admin_require_login();

$conn = db_connect();

$from    = isset($_GET['from']) ? trim($_GET['from']) : date('Y-m-d', strtotime('-7 days'));
$to      = isset($_GET['to']) ? trim($_GET['to']) : date('Y-m-d');
$status  = isset($_GET['status']) ? trim($_GET['status']) : '';
$keyword = isset($_GET['q']) ? trim($_GET['q']) : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$where = array('created_at >= ?', 'created_at < DATE_ADD(?, INTERVAL 1 DAY)');
$types = 'ss';
$args  = array($from, $to);
if (in_array($status, array('success', 'failed', 'pending'), true)) {
    $where[] = 'status = ?'; $types .= 's'; $args[] = $status;
}
if ($keyword !== '') {
    $where[] = '(mer_trade_no LIKE ? OR payuni_trade_no LIKE ? OR card4_no = ?)';
    $types .= 'sss';
    $args[] = '%' . $keyword . '%';
    $args[] = '%' . $keyword . '%';
    $args[] = $keyword;
}
$whereSql = implode(' AND ', $where);

$stmt = mysqli_prepare($conn,
    "SELECT o.created_at, o.mer_trade_no, o.amount, o.status, o.payuni_trade_no,
            o.auth_code, o.card4_no, o.message,
            COALESCE((SELECT SUM(r.amount) FROM refunds r
                      WHERE r.mer_trade_no = o.mer_trade_no
                        AND r.close_type = 2 AND r.status='success'), 0) AS refunded
     FROM orders o WHERE $whereSql ORDER BY o.id DESC");
mysqli_stmt_bind_param($stmt, $types, ...$args);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$filename = 'transactions_' . $from . '_' . $to . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
// UTF-8 BOM：沒有這個的話 Excel 開啟中文會變亂碼
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, array(
    '時間', '訂單編號', '金額', '狀態', 'PAYUNi交易序號',
    '授權碼', '卡號末四碼', '已退款金額', '淨額', '訊息',
));

$statusLabels = array('success' => '成功', 'failed' => '失敗', 'pending' => '處理中');
while ($r = mysqli_fetch_assoc($result)) {
    $net = $r['status'] === 'success' ? ((int) $r['amount'] - (int) $r['refunded']) : 0;
    fputcsv($out, array(
        $r['created_at'],
        $r['mer_trade_no'],
        (int) $r['amount'],
        isset($statusLabels[$r['status']]) ? $statusLabels[$r['status']] : $r['status'],
        $r['payuni_trade_no'],
        // 授權碼可能是純數字且開頭有 0，Excel 會吃掉前導零並轉成數字，
        // 加上定位字元強制當文字處理。訂單編號同理但目前都有英文開頭。
        $r['auth_code'] !== null && $r['auth_code'] !== '' ? "\t" . $r['auth_code'] : '',
        $r['card4_no'] !== null && $r['card4_no'] !== '' ? "\t" . $r['card4_no'] : '',
        (int) $r['refunded'],
        $net,
        $r['message'],
    ));
}
fclose($out);
mysqli_stmt_close($stmt);
