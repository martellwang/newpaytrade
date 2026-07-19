<?php
/**
 * MySQL 存取工具（傳統程序式寫法，用 mysqli，不用 class）
 */

require_once __DIR__ . '/config.php';

/** 開一條資料庫連線，失敗直接拋例外 */
function db_connect() {
    mysqli_report(MYSQLI_REPORT_OFF); // 自己處理錯誤，不用 mysqli 的例外機制
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if (!$conn) {
        throw new Exception('資料庫連線失敗：' . mysqli_connect_error());
    }
    mysqli_set_charset($conn, 'utf8mb4');
    return $conn;
}

/**
 * 建立 orders 資料表（如果還不存在）。部署時手動跑一次
 * （例如 php -f create_table.php），不用每個請求都檢查一次。
 */
function db_create_orders_table_if_not_exists($conn) {
    $sql = "
        CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mer_trade_no VARCHAR(25) NOT NULL UNIQUE,
            amount INT NOT NULL,
            status VARCHAR(20) NOT NULL,
            payuni_trade_no VARCHAR(64) NULL,
            auth_code VARCHAR(20) NULL,
            card4_no VARCHAR(4) NULL,
            message VARCHAR(255) NULL,
            raw_response TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sql)) {
        throw new Exception('建立 orders 資料表失敗：' . mysqli_error($conn));
    }
}

/** 交易送出前先寫一筆 pending 紀錄，拿到 PAYUNi 回應後再更新 */
function db_insert_pending_order($conn, $merTradeNo, $amount) {
    $stmt = mysqli_prepare($conn, 'INSERT INTO orders (mer_trade_no, amount, status) VALUES (?, ?, ?)');
    $status = 'pending';
    mysqli_stmt_bind_param($stmt, 'sis', $merTradeNo, $amount, $status);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('寫入訂單紀錄失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/** 收到 PAYUNi 回應後更新訂單狀態 */
function db_update_order_result($conn, $merTradeNo, $status, $payuniTradeNo, $authCode, $card4No, $message, $rawResponse) {
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE orders SET status = ?, payuni_trade_no = ?, auth_code = ?, card4_no = ?, message = ?, raw_response = ? WHERE mer_trade_no = ?'
    );
    mysqli_stmt_bind_param(
        $stmt,
        'sssssss',
        $status,
        $payuniTradeNo,
        $authCode,
        $card4No,
        $message,
        $rawResponse,
        $merTradeNo
    );
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('更新訂單紀錄失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/** 查詢單筆訂單目前狀態 */
function db_find_order($conn, $merTradeNo) {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM orders WHERE mer_trade_no = ?');
    mysqli_stmt_bind_param($stmt, 's', $merTradeNo);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return $row;
}
