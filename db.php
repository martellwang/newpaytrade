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
            serial_no VARCHAR(64) NULL,
            terminal_uid VARCHAR(64) NULL,
            name VARCHAR(64) NULL,
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

    // 舊版資料表可能沒有後來加的欄位，逐一補上（重複執行不會出錯）
    $deviceCols = array(
        'serial_no' => "ALTER TABLE devices ADD COLUMN serial_no VARCHAR(64) NULL",
        'terminal_uid' => "ALTER TABLE devices ADD COLUMN terminal_uid VARCHAR(64) NULL",
        'name' => "ALTER TABLE devices ADD COLUMN name VARCHAR(64) NULL",
    );
    foreach ($deviceCols as $col => $ddl) {
        $res = mysqli_query($conn, "SHOW COLUMNS FROM devices LIKE '$col'");
        if ($res && mysqli_num_rows($res) === 0) {
            mysqli_query($conn, $ddl);
        }
    }

    // orders 加上 device_id 與 device_serial。
    // device_serial 存的是「交易當下」的機器序號快照 —— 就算之後裝置資料
    // 被修改或刪除，歷史交易仍查得到當時是哪台機器刷的。
    $orderCols = array(
        'device_id' => "ALTER TABLE orders ADD COLUMN device_id VARCHAR(64) NULL, ADD INDEX idx_device (device_id)",
        'device_serial' => "ALTER TABLE orders ADD COLUMN device_serial VARCHAR(64) NULL",
        // 分期期數。1 = 一次付清（PAYUNi 的 CardInst 預設值），3/6/9/12/18/24/30 = 分期。
        // 預設 1 讓既有資料自動視為一次付清，不用回頭補。
        // 退款時必須看這個欄位 —— 分期交易只能全額退款，不能部分退。
        'card_inst' => "ALTER TABLE orders ADD COLUMN card_inst INT NOT NULL DEFAULT 1",
        /*
         * 這筆交易走哪一家上游。系統未來會接不只一家（其他金流商、收單銀行、
         * 電子支付機構），對帳、退款、爭議處理都得知道當初是誰授權的。
         *
         * 從現在就記錄，是因為**這個欄位補不回來** —— 等真的接了第二家上游
         * 才加，之前的交易永遠不知道走哪家。預設 payuni 讓既有資料自動正確。
         */
        'provider' => "ALTER TABLE orders ADD COLUMN provider VARCHAR(32) NOT NULL DEFAULT 'payuni', ADD INDEX idx_provider (provider)",
        /*
         * 這筆交易屬於哪個客戶（商店）。
         *
         * mer_id 存的是「交易當下」的商店代號快照 —— 就算客戶資料之後被
         * 修改或刪除，歷史交易仍查得到當時是用哪個商店代號送出的。
         * 對帳、爭議處理、跟上游核對時都需要這個。
         */
        'merchant_id' => "ALTER TABLE orders ADD COLUMN merchant_id INT NULL, ADD INDEX idx_merchant (merchant_id)",
        'mer_id' => "ALTER TABLE orders ADD COLUMN mer_id VARCHAR(32) NULL",
        'store_id' => "ALTER TABLE orders ADD COLUMN store_id INT NULL, ADD INDEX idx_store (store_id)",
        'dealer_id' => "ALTER TABLE orders ADD COLUMN dealer_id INT NULL, ADD INDEX idx_dealer (dealer_id)",
        /*
         * 這筆交易用什麼方式收的：credit=信用卡、linepay=LINE Pay 掃碼。
         *
         * **不能只靠 provider 分辨** —— provider 是「哪一家上游」（payuni），
         * 同一家上游底下有多種支付工具，錢的流向、對帳週期、退款管道都不同。
         *
         * 尤其是退款：信用卡走 /api/trade/close（分請款/退款/取消授權），
         * LINE Pay 走「非信用卡退款轉匯」，是完全不同的 API。沒有這個欄位
         * 就會拿信用卡的退款流程去退 LINE Pay 的錢。
         *
         * 預設 credit 讓既有資料自動正確 —— 這個欄位加進來之前的交易全是刷卡。
         */
        'payment_method' => "ALTER TABLE orders ADD COLUMN payment_method VARCHAR(16) NOT NULL DEFAULT 'credit', ADD INDEX idx_payment_method (payment_method)",
    );
    foreach ($orderCols as $col => $ddl) {
        $res = mysqli_query($conn, "SHOW COLUMNS FROM orders LIKE '$col'");
        if ($res && mysqli_num_rows($res) === 0) {
            if (!mysqli_query($conn, $ddl)) {
                throw new Exception("orders 新增 $col 欄位失敗：" . mysqli_error($conn));
            }
        }
    }
}

/** 手動登記一台機器（用於裝不了 App 的機器，例如 Ingenico APOS A8）*/
function db_manual_add_device($conn, $d) {
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO devices
            (device_id, serial_no, terminal_uid, name, brand, manufacturer, model,
             android_version, has_nfc, note)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            serial_no=VALUES(serial_no), terminal_uid=VALUES(terminal_uid),
            name=VALUES(name), brand=VALUES(brand), manufacturer=VALUES(manufacturer),
            model=VALUES(model), android_version=VALUES(android_version),
            has_nfc=VALUES(has_nfc), note=VALUES(note)'
    );
    $hasNfc = ($d['hasNfc'] === '' || $d['hasNfc'] === null) ? null : (int) $d['hasNfc'];
    mysqli_stmt_bind_param(
        $stmt, 'ssssssssis',
        $d['deviceId'], $d['serialNo'], $d['terminalUid'], $d['name'], $d['brand'],
        $d['manufacturer'], $d['model'], $d['androidVersion'], $hasNfc, $d['note']
    );
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('手動登記裝置失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/** 更新可由管理者自訂的欄位（機台編號、名稱、備註）*/
function db_update_device_meta($conn, $deviceId, $terminalUid, $name, $note) {
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE devices SET terminal_uid = ?, name = ?, note = ? WHERE device_id = ?'
    );
    mysqli_stmt_bind_param($stmt, 'ssss', $terminalUid, $name, $note, $deviceId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('更新裝置資料失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/** 刪除一台裝置的登記（歷史交易的 device_serial 快照不受影響）*/
function db_delete_device($conn, $deviceId) {
    $stmt = mysqli_prepare($conn, 'DELETE FROM devices WHERE device_id = ?');
    mysqli_stmt_bind_param($stmt, 's', $deviceId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/** 取單一裝置 */
function db_find_device($conn, $deviceId) {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM devices WHERE device_id = ?');
    mysqli_stmt_bind_param($stmt, 's', $deviceId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row;
}

/**
 * 登記或更新一台裝置。每次交易都會呼叫，所以 last_seen 會自動更新，
 * 可以看出哪些機器還在用、哪些已經沒在用了。
 */
function db_upsert_device($conn, $d) {
    // 同一台機器可能已經用「硬體序號」手動登記過（App 裝不上去時我們會先
    // 手動建檔），而 App 自動登記用的是 ANDROID_ID —— 識別碼不同會變成
    // 兩筆重複紀錄。所以先用序號找找看有沒有既有紀錄，有的話就沿用它的
    // device_id 更新，不要另外新增一筆。
    if (!empty($d['serialNo'])) {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT device_id FROM devices WHERE serial_no = ? AND device_id <> ? LIMIT 1'
        );
        mysqli_stmt_bind_param($stmt, 'ss', $d['serialNo'], $d['deviceId']);
        mysqli_stmt_execute($stmt);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($existing) {
            // 沿用既有那筆（保住人工設定的機台編號與備註），但把 device_id
            // 換成 App 回報的 ANDROID_ID，之後交易才對得起來。
            $stmt = mysqli_prepare($conn, 'UPDATE devices SET device_id = ? WHERE device_id = ?');
            mysqli_stmt_bind_param($stmt, 'ss', $d['deviceId'], $existing['device_id']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // 舊 device_id 關聯的歷史交易也一併指向新的，避免斷鏈
            $stmt = mysqli_prepare($conn, 'UPDATE orders SET device_id = ? WHERE device_id = ?');
            mysqli_stmt_bind_param($stmt, 'ss', $d['deviceId'], $existing['device_id']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    // 注意：terminal_uid / name / note 是管理者自己填的，這裡「不」覆蓋，
    // 否則每次交易都會把人工設定的機台編號洗掉。
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO devices
            (device_id, serial_no, brand, manufacturer, model, product, android_version,
             android_sdk, app_version, has_nfc, nfc_enabled, screen)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            serial_no=VALUES(serial_no),
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
    //   deviceId serialNo brand manufacturer model product androidVersion sdk appVersion hasNfc nfcOn screen
    //   s        s        s     s            s     s       s              i   s          i      i     s
    mysqli_stmt_bind_param(
        $stmt, 'sssssssisiis',
        $d['deviceId'], $d['serialNo'], $d['brand'], $d['manufacturer'], $d['model'], $d['product'],
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
/**
 * 商店開通狀態的快取表。
 *
 * 為什麼要存起來：每台收銀機開機都查，App 被系統回收後重啟又會再查一次，
 * 實際呼叫次數遠超想像。而商店的開通狀態是幾天甚至幾個月才變一次的東西，
 * 沒有理由即時查。查詢失敗時也能回退到這裡的舊資料 —— 昨天的結果拿來做
 * 灰階顯示完全可以接受，總比沒有好。
 */
function db_create_merchant_status_table_if_not_exists($conn) {
    // 主鍵是「上游代號:商店代號」（例如 payuni:NEDC82798291）——
    // 不同上游的同一個商店代號是不同的東西，不能共用一列。
    $sql = "
        CREATE TABLE IF NOT EXISTS merchant_status (
            mer_id VARCHAR(96) NOT NULL PRIMARY KEY,
            payload TEXT NOT NULL,
            fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sql)) {
        throw new Exception('建立 merchant_status 資料表失敗：' . mysqli_error($conn));
    }
}

function db_get_merchant_status($conn, $merId) {
    $stmt = mysqli_prepare($conn, 'SELECT payload, fetched_at FROM merchant_status WHERE mer_id = ?');
    mysqli_stmt_bind_param($stmt, 's', $merId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function db_save_merchant_status($conn, $merId, $payload) {
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO merchant_status (mer_id, payload, fetched_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()'
    );
    mysqli_stmt_bind_param($stmt, 'ss', $merId, $payload);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('寫入商店狀態快取失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/**
 * TMS 的四層歸屬：上游 → 經銷商 → 客戶 → 商店。
 *
 *   dealers          經銷商。有後台帳號，可看旗下全部。
 *   merchants        客戶（公司或個人）。有後台帳號，也是收銀機的登入身分。
 *   merchant_stores  商店。持有商店代號 MerID，沒有自己的登入帳號。
 *
 * === 登入為什麼要帶統編／身分證字號 ===
 * 不同客戶很容易取到相同的帳號名稱（admin、pos、001…）。若帳號全域唯一，
 * 先到先得會讓後來的客戶被迫改名，很難跟店家解釋。改成「統編 + 帳號」
 * 的組合唯一，各客戶就能自由命名，彼此不干擾。
 */
function db_create_merchants_table_if_not_exists($conn) {
    $sql = "
        CREATE TABLE IF NOT EXISTS dealers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            login_account VARCHAR(64) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sql)) {
        throw new Exception('建立 dealers 資料表失敗：' . mysqli_error($conn));
    }

    /*
     * customer_code 是**系統配發的純數字客戶編號**，收銀機登入時輸入它。
     *
     * 為什麼不用統編／身分證字號當登入識別：
     *   1. 身分證字號去掉開頭字母後**不保證唯一**。字母會參與檢查碼計算，
     *      而有數組字母的貢獻值相同（A/M、C/I、K/L），所以
     *      C123456789 與 I123456789 可以同時是兩個合法的字號 ——
     *      只取後 9 碼就會撞號。
     *   2. 身分證字號屬於敏感個資。拿它當登入識別代表它會進資料庫、log、
     *      備份，店員輸入時旁邊的客人也看得到，外洩的影響遠超過「有人能
     *      登入收銀機」。
     *
     * tax_id（統編／身分證字號）仍然保留 —— 開發票、對帳需要 —— 但只是
     * 客戶資料的一個欄位，不參與登入。可留空。
     *
     * 唯一鍵是 (customer_code, login_account)：同一個客戶底下的帳號不重複，
     * 但不同客戶可以自由取名，不會互相卡位。
     */
    $sql2 = "
        CREATE TABLE IF NOT EXISTS merchants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dealer_id INT NULL,
            customer_code VARCHAR(16) NOT NULL,
            tax_id VARCHAR(32) NULL,
            name VARCHAR(100) NOT NULL,
            login_account VARCHAR(64) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_code (customer_code),
            UNIQUE KEY uk_code_account (customer_code, login_account),
            INDEX idx_dealer (dealer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sql2)) {
        throw new Exception('建立 merchants 資料表失敗：' . mysqli_error($conn));
    }

    /*
     * 商店。每個 MerID 各自屬於某一家上游 —— 同一個客戶完全可能一家分店
     * 走 A 上游、另一家走 B 上游，所以 provider 記在這一層。
     */
    $sql3 = "
        CREATE TABLE IF NOT EXISTS merchant_stores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            mer_id VARCHAR(32) NOT NULL,
            provider VARCHAR(32) NOT NULL DEFAULT 'payuni',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_merchant (merchant_id),
            INDEX idx_mer (mer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sql3)) {
        throw new Exception('建立 merchant_stores 資料表失敗：' . mysqli_error($conn));
    }

    /*
     * 收銀機登入憑證。
     *
     * 同一家店的多台收銀機共用同一組帳密是允許的 —— 各自登入拿到各自的
     * token，撤銷與追蹤都是分開的。store_id 是「這台機器目前以哪家分店
     * 營業」，登入後選擇並綁定。
     *
     * 資料庫只存 token 的雜湊，外洩時不該讓人拿了就能用。
     */
    $sql4 = "
        CREATE TABLE IF NOT EXISTS merchant_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            store_id INT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            device_id VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            INDEX idx_merchant (merchant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sql4)) {
        throw new Exception('建立 merchant_sessions 資料表失敗：' . mysqli_error($conn));
    }
}

/**
 * 收銀機登入失敗的鎖定狀態。
 *
 * === 為什麼鎖設備而不是鎖帳號 ===
 * 鎖帳號的話，偷到一台收銀機的人只要連續試錯密碼，就能把整家店其他
 * 收銀機一起鎖死 —— 那等於幫攻擊者做阻斷服務。鎖設備則只影響那一台，
 * 店裡其他機器照常營業。
 *
 * merchant_id 記的是「最後一次嘗試登入的客戶」。這樣管理者在自己的後台
 * 才看得到「我旗下有哪台設備被鎖住」—— 否則鎖定紀錄跟客戶對不起來，
 * 店家只會看到一台鎖住卻找不到地方解。
 *
 * device_id 由 App 提供，理論上可以被改。但要改它得先反組譯並重打包
 * App，那已經超出「撿到機器亂試密碼」的威脅範圍，不值得為此加更重的
 * 綁定（例如憑證），那會讓正常換機變得很麻煩。
 */
function db_create_pos_locks_table_if_not_exists($conn) {
    $sql = "
        CREATE TABLE IF NOT EXISTS pos_device_locks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(64) NOT NULL UNIQUE,
            merchant_id INT NULL,
            last_customer_code VARCHAR(16) NULL,
            last_account VARCHAR(64) NULL,
            failed_count INT NOT NULL DEFAULT 0,
            locked TINYINT(1) NOT NULL DEFAULT 0,
            last_failed_at DATETIME NULL,
            locked_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_merchant (merchant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sql)) {
        throw new Exception('建立 pos_device_locks 資料表失敗：' . mysqli_error($conn));
    }
}

/** 連續失敗幾次就鎖住這台設備 */
define('POS_LOCK_THRESHOLD', 5);

function db_get_pos_lock($conn, $deviceId) {
    if ($deviceId === null || $deviceId === '') {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM pos_device_locks WHERE device_id = ?');
    mysqli_stmt_bind_param($stmt, 's', $deviceId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * 記錄一次登入失敗。達到門檻就鎖住。
 * @return array('locked' => bool, 'remaining' => 剩幾次機會)
 */
function db_record_pos_login_failure($conn, $deviceId, $merchantId, $customerCode, $account) {
    if ($deviceId === null || $deviceId === '') {
        // 沒有設備識別就無從鎖起。仍然回報，讓呼叫端知道沒有這層保護。
        return array('locked' => false, 'remaining' => null);
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO pos_device_locks
            (device_id, merchant_id, last_customer_code, last_account, failed_count, last_failed_at)
         VALUES (?,?,?,?,1,NOW())
         ON DUPLICATE KEY UPDATE
            merchant_id = VALUES(merchant_id),
            last_customer_code = VALUES(last_customer_code),
            last_account = VALUES(last_account),
            failed_count = failed_count + 1,
            last_failed_at = NOW()'
    );
    mysqli_stmt_bind_param($stmt, 'siss', $deviceId, $merchantId, $customerCode, $account);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $row = db_get_pos_lock($conn, $deviceId);
    $count = $row ? (int) $row['failed_count'] : 1;

    if ($count >= POS_LOCK_THRESHOLD && (!$row || (int) $row['locked'] !== 1)) {
        $lock = mysqli_prepare($conn,
            'UPDATE pos_device_locks SET locked = 1, locked_at = NOW() WHERE device_id = ?');
        mysqli_stmt_bind_param($lock, 's', $deviceId);
        mysqli_stmt_execute($lock);
        mysqli_stmt_close($lock);
        return array('locked' => true, 'remaining' => 0);
    }
    return array(
        'locked' => $row && (int) $row['locked'] === 1,
        'remaining' => max(0, POS_LOCK_THRESHOLD - $count),
    );
}

/** 登入成功就把失敗次數歸零 —— 累計的是「連續」失敗，不是歷史總數 */
function db_reset_pos_login_failures($conn, $deviceId) {
    if ($deviceId === null || $deviceId === '') {
        return;
    }
    $stmt = mysqli_prepare($conn,
        'UPDATE pos_device_locks SET failed_count = 0 WHERE device_id = ? AND locked = 0');
    mysqli_stmt_bind_param($stmt, 's', $deviceId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/** 管理者解鎖 */
function db_unlock_pos_device($conn, $deviceId) {
    $stmt = mysqli_prepare($conn,
        'UPDATE pos_device_locks SET locked = 0, failed_count = 0, locked_at = NULL WHERE device_id = ?');
    mysqli_stmt_bind_param($stmt, 's', $deviceId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * 某個客戶旗下的收銀機清單（含鎖定狀態）。
 *
 * 來源有兩個並集：曾經登入過的（merchant_sessions）與曾經嘗試登入的
 *（pos_device_locks）—— 只看前者的話，一台從沒成功登入過就被鎖住的
 * 機器不會出現在清單裡，管理者就找不到地方解鎖。
 */
function db_list_pos_devices_by_merchant($conn, $merchantId) {
    $sql = '
        SELECT d.device_id,
               MAX(d.locked) AS locked,
               MAX(d.failed_count) AS failed_count,
               MAX(d.last_failed_at) AS last_failed_at,
               MAX(d.locked_at) AS locked_at,
               MAX(s.last_used_at) AS last_used_at,
               dev.brand, dev.model, dev.name, dev.serial_no
        FROM pos_device_locks d
        LEFT JOIN merchant_sessions s ON s.device_id = d.device_id AND s.merchant_id = ?
        LEFT JOIN devices dev ON dev.device_id = d.device_id
        WHERE d.merchant_id = ?
        GROUP BY d.device_id, dev.brand, dev.model, dev.name, dev.serial_no

        UNION

        SELECT s.device_id,
               0 AS locked, 0 AS failed_count, NULL AS last_failed_at, NULL AS locked_at,
               MAX(s.last_used_at) AS last_used_at,
               dev.brand, dev.model, dev.name, dev.serial_no
        FROM merchant_sessions s
        LEFT JOIN devices dev ON dev.device_id = s.device_id
        WHERE s.merchant_id = ?
          AND s.device_id IS NOT NULL
          AND s.device_id NOT IN (SELECT device_id FROM pos_device_locks WHERE merchant_id = ?)
        GROUP BY s.device_id, dev.brand, dev.model, dev.name, dev.serial_no
    ';
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'iiii', $merchantId, $merchantId, $merchantId, $merchantId);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

// ── 經銷商 ──────────────────────────────────────────────────────

function db_list_dealers($conn) {
    $res = mysqli_query($conn, 'SELECT * FROM dealers ORDER BY id DESC');
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : array();
}

function db_find_dealer($conn, $id) {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM dealers WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function db_find_dealer_by_account($conn, $account) {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM dealers WHERE login_account = ?');
    mysqli_stmt_bind_param($stmt, 's', $account);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/** @param string|null $passwordHash null = 不變更密碼 */
function db_save_dealer($conn, $id, $name, $account, $passwordHash, $enabled, $note) {
    if ($id) {
        if ($passwordHash === null) {
            $stmt = mysqli_prepare($conn,
                'UPDATE dealers SET name=?, login_account=?, enabled=?, note=? WHERE id=?');
            mysqli_stmt_bind_param($stmt, 'ssisi', $name, $account, $enabled, $note, $id);
        } else {
            $stmt = mysqli_prepare($conn,
                'UPDATE dealers SET name=?, login_account=?, password_hash=?, enabled=?, note=? WHERE id=?');
            mysqli_stmt_bind_param($stmt, 'sssisi', $name, $account, $passwordHash, $enabled, $note, $id);
        }
    } else {
        $stmt = mysqli_prepare($conn,
            'INSERT INTO dealers (name, login_account, password_hash, enabled, note) VALUES (?,?,?,?,?)');
        mysqli_stmt_bind_param($stmt, 'sssis', $name, $account, $passwordHash, $enabled, $note);
    }
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('儲存經銷商失敗：' . mysqli_stmt_error($stmt));
    }
    $newId = $id ?: mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $newId;
}

function db_count_merchants_by_dealer($conn, $dealerId) {
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) c FROM merchants WHERE dealer_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $dealerId);
    mysqli_stmt_execute($stmt);
    $c = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);
    return $c;
}

// ── 客戶 ────────────────────────────────────────────────────────

function db_list_merchants($conn, $dealerId = null) {
    if ($dealerId === null) {
        $res = mysqli_query($conn, 'SELECT * FROM merchants ORDER BY id DESC');
        return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : array();
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM merchants WHERE dealer_id = ? ORDER BY id DESC');
    mysqli_stmt_bind_param($stmt, 'i', $dealerId);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

function db_find_merchant($conn, $id) {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM merchants WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/** 收銀機登入用：客戶編號 + 帳號 才能定位到唯一一個客戶 */
function db_find_merchant_by_login($conn, $customerCode, $account) {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM merchants WHERE customer_code = ? AND login_account = ?');
    mysqli_stmt_bind_param($stmt, 'ss', $customerCode, $account);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/** @param string|null $passwordHash null = 不變更密碼 */
function db_save_merchant($conn, $id, $dealerId, $customerCode, $taxId, $name, $account, $passwordHash, $enabled, $note) {
    if ($id) {
        // customer_code 建立後不可修改 —— 店家已經把它記在收銀機旁邊了，
        // 改掉等於讓所有分店突然登不進去
        if ($passwordHash === null) {
            $stmt = mysqli_prepare($conn,
                'UPDATE merchants SET dealer_id=?, tax_id=?, name=?, login_account=?, enabled=?, note=? WHERE id=?');
            mysqli_stmt_bind_param($stmt, 'isssisi', $dealerId, $taxId, $name, $account, $enabled, $note, $id);
        } else {
            $stmt = mysqli_prepare($conn,
                'UPDATE merchants SET dealer_id=?, tax_id=?, name=?, login_account=?, password_hash=?, enabled=?, note=? WHERE id=?');
            mysqli_stmt_bind_param($stmt, 'issssisi', $dealerId, $taxId, $name, $account, $passwordHash, $enabled, $note, $id);
        }
    } else {
        $stmt = mysqli_prepare($conn,
            'INSERT INTO merchants (dealer_id, customer_code, tax_id, name, login_account, password_hash, enabled, note)
             VALUES (?,?,?,?,?,?,?,?)');
        mysqli_stmt_bind_param($stmt, 'isssssis', $dealerId, $customerCode, $taxId, $name, $account, $passwordHash, $enabled, $note);
    }
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('儲存客戶失敗：' . mysqli_stmt_error($stmt));
    }
    $newId = $id ?: mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $newId;
}

/**
 * 配發下一個客戶編號。
 *
 * 從 100001 開始遞增。用六位數而不是接續資料庫的 id：
 *   - id 從 1 開始，「客戶 3 號」看起來像測試資料，不夠正式
 *   - 固定長度讓店員比較不會少打一位
 *
 * 併發時兩個請求可能拿到同一個號碼，但 customer_code 有 UNIQUE 限制，
 * 後寫入的那筆會失敗並顯示錯誤 —— 管理者重按一次即可。這個情境極少發生
 * （只在新增客戶時），不值得為它加鎖。
 */
function db_next_customer_code($conn) {
    $res = mysqli_query($conn, 'SELECT MAX(CAST(customer_code AS UNSIGNED)) AS m FROM merchants');
    $max = $res ? (int) mysqli_fetch_assoc($res)['m'] : 0;
    return (string) max(100001, $max + 1);
}

// ── 商店 ────────────────────────────────────────────────────────

function db_list_stores($conn, $merchantId) {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM merchant_stores WHERE merchant_id = ? ORDER BY id');
    mysqli_stmt_bind_param($stmt, 'i', $merchantId);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

function db_find_store($conn, $id) {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM merchant_stores WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function db_save_store($conn, $id, $merchantId, $name, $merId, $provider, $enabled, $note) {
    if ($id) {
        $stmt = mysqli_prepare($conn,
            'UPDATE merchant_stores SET name=?, mer_id=?, provider=?, enabled=?, note=? WHERE id=? AND merchant_id=?');
        mysqli_stmt_bind_param($stmt, 'sssisii', $name, $merId, $provider, $enabled, $note, $id, $merchantId);
    } else {
        $stmt = mysqli_prepare($conn,
            'INSERT INTO merchant_stores (merchant_id, name, mer_id, provider, enabled, note) VALUES (?,?,?,?,?,?)');
        mysqli_stmt_bind_param($stmt, 'isssis', $merchantId, $name, $merId, $provider, $enabled, $note);
    }
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('儲存商店失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/** 這家商店被幾筆交易用過。有交易就不該刪 —— 歷史會失去歸屬。 */
function db_count_orders_by_store($conn, $storeId) {
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) c FROM orders WHERE store_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $storeId);
    mysqli_stmt_execute($stmt);
    $c = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);
    return $c;
}

function db_delete_store($conn, $id, $merchantId) {
    $stmt = mysqli_prepare($conn, 'DELETE FROM merchant_stores WHERE id = ? AND merchant_id = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $id, $merchantId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// ── 收銀機登入 ──────────────────────────────────────────────────

/** 建立登入 token。回傳明文 token（只有這一次拿得到）。 */
function db_create_merchant_session($conn, $merchantId, $storeId, $deviceId) {
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $stmt = mysqli_prepare($conn,
        'INSERT INTO merchant_sessions (merchant_id, store_id, token_hash, device_id, last_used_at)
         VALUES (?,?,?,?,NOW())');
    mysqli_stmt_bind_param($stmt, 'iiss', $merchantId, $storeId, $hash, $deviceId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $token;
}

/** 綁定這個 token 以哪家分店營業 */
function db_bind_session_store($conn, $token, $storeId) {
    $hash = hash('sha256', $token);
    $stmt = mysqli_prepare($conn, 'UPDATE merchant_sessions SET store_id = ? WHERE token_hash = ?');
    mysqli_stmt_bind_param($stmt, 'is', $storeId, $hash);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * 用 token 找出登入身分。
 * 客戶或商店任一被停用就視同失效 —— 停用了還能繼續收款是不可接受的。
 */
function db_find_session_by_token($conn, $token) {
    if ($token === '' || $token === null) {
        return null;
    }
    $hash = hash('sha256', $token);
    $stmt = mysqli_prepare($conn,
        'SELECT s.id AS session_id, s.store_id,
                m.id AS merchant_id, m.name AS merchant_name, m.dealer_id,
                m.enabled AS merchant_enabled,
                st.mer_id, st.name AS store_name, st.provider, st.enabled AS store_enabled
         FROM merchant_sessions s
         JOIN merchants m ON m.id = s.merchant_id
         LEFT JOIN merchant_stores st ON st.id = s.store_id
         WHERE s.token_hash = ?');
    mysqli_stmt_bind_param($stmt, 's', $hash);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row || (int) $row['merchant_enabled'] !== 1) {
        return null;
    }
    if ($row['store_id'] !== null && (int) $row['store_enabled'] !== 1) {
        return null;
    }

    $upd = mysqli_prepare($conn, 'UPDATE merchant_sessions SET last_used_at = NOW() WHERE token_hash = ?');
    mysqli_stmt_bind_param($upd, 's', $hash);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
    return $row;
}

/** 撤銷某客戶的所有登入。停用、改密碼、商店異動時要呼叫。 */
function db_revoke_merchant_sessions($conn, $merchantId) {
    $stmt = mysqli_prepare($conn, 'DELETE FROM merchant_sessions WHERE merchant_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $merchantId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * 上游金流機構的設定。
 *
 * credentials_enc 是加密後的 JSON（見 providers.php 的 provider_secret_*）。
 * **絕對不要改成明文儲存** —— 資料庫備份、SQL 注入、管理帳號外洩，任何
 * 一個都會把所有上游的串接金鑰一次交出去。
 */
function db_create_providers_table_if_not_exists($conn) {
    $sql = "
        CREATE TABLE IF NOT EXISTS providers (
            name VARCHAR(32) NOT NULL PRIMARY KEY,
            label VARCHAR(64) NOT NULL,
            driver VARCHAR(32) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            endpoints TEXT NULL,
            credentials_enc TEXT NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sql)) {
        throw new Exception('建立 providers 資料表失敗：' . mysqli_error($conn));
    }
}

function db_list_providers($conn) {
    $res = mysqli_query($conn, 'SELECT * FROM providers ORDER BY name');
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : array();
}

function db_find_provider($conn, $name) {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM providers WHERE name = ?');
    mysqli_stmt_bind_param($stmt, 's', $name);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * 新增或更新上游。
 * @param string|null $credentialsEnc 傳 null 代表「不變更既有金鑰」——
 *        編輯畫面不會把金鑰讀回瀏覽器，留空就是保持原值。
 */
function db_save_provider($conn, $name, $label, $driver, $enabled, $endpointsJson, $credentialsEnc, $note) {
    if ($credentialsEnc === null) {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO providers (name, label, driver, enabled, endpoints, note)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE label=VALUES(label), driver=VALUES(driver),
                 enabled=VALUES(enabled), endpoints=VALUES(endpoints), note=VALUES(note)'
        );
        mysqli_stmt_bind_param($stmt, 'sssiss', $name, $label, $driver, $enabled, $endpointsJson, $note);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO providers (name, label, driver, enabled, endpoints, credentials_enc, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE label=VALUES(label), driver=VALUES(driver),
                 enabled=VALUES(enabled), endpoints=VALUES(endpoints),
                 credentials_enc=VALUES(credentials_enc), note=VALUES(note)'
        );
        mysqli_stmt_bind_param($stmt, 'sssisss', $name, $label, $driver, $enabled, $endpointsJson, $credentialsEnc, $note);
    }
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('儲存上游設定失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/** 這家上游被幾筆交易用過。有交易就不該刪掉 —— 歷史紀錄會失去歸屬。 */
function db_count_orders_by_provider($conn, $name) {
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM orders WHERE provider = ?');
    mysqli_stmt_bind_param($stmt, 's', $name);
    mysqli_stmt_execute($stmt);
    $c = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);
    return $c;
}

function db_delete_provider($conn, $name) {
    $stmt = mysqli_prepare($conn, 'DELETE FROM providers WHERE name = ?');
    mysqli_stmt_bind_param($stmt, 's', $name);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function db_insert_pending_order($conn, $merTradeNo, $amount, $deviceId = null, $deviceSerial = null,
                                 $cardInst = 1, $merchantId = null, $merId = null,
                                 $storeId = null, $dealerId = null, $paymentMethod = 'credit') {
    $stmt = mysqli_prepare($conn,
        'INSERT INTO orders (mer_trade_no, amount, status, device_id, device_serial,
                             card_inst, merchant_id, mer_id, store_id, dealer_id, payment_method)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $status = 'pending';
    // 型別字串要跟欄位順序一一對應：整數欄位是 i，其餘 s。
    // 之前 appVersion 就因為型別對錯位置，把 "0.1-dev" 靜默轉成 0。
    mysqli_stmt_bind_param($stmt, 'sisssiisiis', $merTradeNo, $amount, $status, $deviceId, $deviceSerial,
        $cardInst, $merchantId, $merId, $storeId, $dealerId, $paymentMethod);
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
