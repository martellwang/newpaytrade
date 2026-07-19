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

/**
 * 建立 refunds 資料表（如果還不存在）。
 * 用獨立資料表而不是在 orders 加欄位，因為一筆訂單可以分多次部分退款，
 * 每一次都要留下獨立的紀錄可查。
 */
function db_create_refunds_table_if_not_exists($conn) {
    $sql = "
        CREATE TABLE IF NOT EXISTS refunds (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mer_trade_no VARCHAR(25) NOT NULL,
            payuni_trade_no VARCHAR(64) NOT NULL,
            close_type INT NOT NULL,
            amount INT NOT NULL,
            status VARCHAR(20) NOT NULL,
            message VARCHAR(255) NULL,
            raw_response TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_mer_trade_no (mer_trade_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sql)) {
        throw new Exception('建立 refunds 資料表失敗：' . mysqli_error($conn));
    }
}

/**
 * 建立 devices 資料表：登記每一台實際在用的收銀機。
 *
 * 用途：
 *   1. 多台機器營運時，可以按機器分別對帳（哪台收了多少）
 *   2. 出問題時知道是哪台機器、什麼型號、什麼系統版本
 *   3. 記錄各機型的讀卡能力（實測發現專用 POS 機都沒有標準 NFC，
 *      這件事值得留在資料裡，換機器時才不會重複踩）
 *
 * device_id 用 Android 的 ANDROID_ID（每台裝置對每個 App 固定且不需權限），
 * 不用 Build.SERIAL —— 那在新版 Android 需要特殊權限而且拿不到。
 */
function db_create_devices_table_if_not_exists($conn) {
    $sql = "
        CREATE TABLE IF NOT EXISTS devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(64) NOT NULL UNIQUE,
            brand VARCHAR(64) NULL,
            manufacturer VARCHAR(64) NULL,
            model VARCHAR(64) NULL,
            product VARCHAR(64) NULL,
            android_version VARCHAR(16) NULL,
            android_sdk INT NULL,
            app_version VARCHAR(32) NULL,
            has_nfc TINYINT(1) NULL,
            nfc_enabled TINYINT(1) NULL,
            screen VARCHAR(24) NULL,
            note VARCHAR(255) NULL,
            first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sql)) {
        throw new Exception('建立 devices 資料表失敗：' . mysqli_error($conn));
    }

    // orders 加上 device_id，讓每筆交易可追溯到是哪台機器刷的。
    // 用 SHOW COLUMNS 判斷再加，這樣重複執行 setup_db.php 不會出錯。
    $res = mysqli_query($conn, "SHOW COLUMNS FROM orders LIKE 'device_id'");
    if ($res && mysqli_num_rows($res) === 0) {
        if (!mysqli_query($conn, "ALTER TABLE orders ADD COLUMN device_id VARCHAR(64) NULL, ADD INDEX idx_device (device_id)")) {
            throw new Exception('orders 新增 device_id 欄位失敗：' . mysqli_error($conn));
        }
    }
}

/**
 * 登記或更新一台裝置。每次交易都會呼叫，所以 last_seen 會自動更新，
 * 可以看出哪些機器還在用、哪些已經沒在用了。
 */
function db_upsert_device($conn, $d) {
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO devices
            (device_id, brand, manufacturer, model, product, android_version, android_sdk,
             app_version, has_nfc, nfc_enabled, screen)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            brand=VALUES(brand), manufacturer=VALUES(manufacturer), model=VALUES(model),
            product=VALUES(product), android_version=VALUES(android_version),
            android_sdk=VALUES(android_sdk), app_version=VALUES(app_version),
            has_nfc=VALUES(has_nfc), nfc_enabled=VALUES(nfc_enabled), screen=VALUES(screen),
            last_seen=CURRENT_TIMESTAMP'
    );
    $sdk = isset($d['androidSdk']) ? (int) $d['androidSdk'] : null;
    $hasNfc = isset($d['hasNfc']) ? (int) (bool) $d['hasNfc'] : null;
    $nfcOn = isset($d['nfcEnabled']) ? (int) (bool) $d['nfcEnabled'] : null;
    // 型別字串要跟參數順序一一對應，錯位不會報錯但會靜默轉型
    // （曾把 appVersion 標成 i，"0.1-dev" 就被轉成 0）。
    //        deviceId brand manufacturer model product androidVersion sdk appVersion hasNfc nfcOn screen
    //        s        s     s            s     s       s              i   s          i      i     s
    mysqli_stmt_bind_param(
        $stmt, 'ssssssisiis',
        $d['deviceId'], $d['brand'], $d['manufacturer'], $d['model'], $d['product'],
        $d['androidVersion'], $sdk, $d['appVersion'], $hasNfc, $nfcOn, $d['screen']
    );
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('登記裝置失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/** 列出所有登記過的裝置，附帶各自的交易統計 */
function db_list_devices($conn) {
    $sql = "
        SELECT d.*,
               (SELECT COUNT(*) FROM orders o WHERE o.device_id = d.device_id) AS order_cnt,
               (SELECT COALESCE(SUM(o.amount),0) FROM orders o
                 WHERE o.device_id = d.device_id AND o.status='success') AS success_amt
        FROM devices d ORDER BY d.last_seen DESC
    ";
    $res = mysqli_query($conn, $sql);
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : array();
}

/** 交易送出前先寫一筆 pending 紀錄，拿到 PAYUNi 回應後再更新 */
function db_insert_pending_order($conn, $merTradeNo, $amount, $deviceId = null) {
    $stmt = mysqli_prepare($conn, 'INSERT INTO orders (mer_trade_no, amount, status, device_id) VALUES (?, ?, ?, ?)');
    $status = 'pending';
    mysqli_stmt_bind_param($stmt, 'siss', $merTradeNo, $amount, $status, $deviceId);
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

/**
 * 算出這筆訂單「已經成功退掉」的總金額，用來擋住退款總額超過原始金額。
 * 只計算 status='success' 的退款，pending/failed 不算。
 */
function db_sum_refunded_amount($conn, $merTradeNo) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COALESCE(SUM(amount), 0) AS total FROM refunds
         WHERE mer_trade_no = ? AND close_type = 2 AND status = 'success'"
    );
    mysqli_stmt_bind_param($stmt, 's', $merTradeNo);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return (int) $row['total'];
}

/** 退款送出前先寫一筆 pending 紀錄，回傳這筆的 id 供後續更新 */
function db_insert_pending_refund($conn, $merTradeNo, $payuniTradeNo, $closeType, $amount) {
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO refunds (mer_trade_no, payuni_trade_no, close_type, amount, status) VALUES (?, ?, ?, ?, ?)'
    );
    $status = 'pending';
    mysqli_stmt_bind_param($stmt, 'ssiis', $merTradeNo, $payuniTradeNo, $closeType, $amount, $status);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('寫入退款紀錄失敗：' . mysqli_stmt_error($stmt));
    }
    $id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

/** 收到 PAYUNi 回應後更新退款紀錄 */
function db_update_refund_result($conn, $refundId, $status, $message, $rawResponse) {
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE refunds SET status = ?, message = ?, raw_response = ? WHERE id = ?'
    );
    mysqli_stmt_bind_param($stmt, 'sssi', $status, $message, $rawResponse, $refundId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('更新退款紀錄失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/** 列出一筆訂單的所有退款紀錄 */
function db_list_refunds($conn, $merTradeNo) {
    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, close_type, amount, status, message, created_at FROM refunds WHERE mer_trade_no = ? ORDER BY id'
    );
    mysqli_stmt_bind_param($stmt, 's', $merTradeNo);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = array();
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}
