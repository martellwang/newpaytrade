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
        // 進倉登錄時間：由總部進倉人員用 App 的隱藏登錄操作寫入，代表這台
        // 機器已進倉建檔（還沒派工、也還沒交易過）。t_id 就是 serial_no。
        'enrolled_at' => "ALTER TABLE devices ADD COLUMN enrolled_at DATETIME NULL",
        // 用哪張授權卡登錄的（稽核：知道是誰拿哪張卡進的倉）
        'enrolled_card_uid' => "ALTER TABLE devices ADD COLUMN enrolled_card_uid VARCHAR(32) NULL",
        // 派工：這台機器派給了哪個客戶或哪個經銷商（兩者只會有一個，或都空＝未派工）。
        //   派給客戶 → 客戶自己決定用在哪家店
        //   派給經銷商 → 經銷商自己決定派給旗下哪個客戶
        'dispatched_merchant_id' => "ALTER TABLE devices ADD COLUMN dispatched_merchant_id INT NULL, ADD INDEX idx_disp_merchant (dispatched_merchant_id)",
        'dispatched_dealer_id' => "ALTER TABLE devices ADD COLUMN dispatched_dealer_id INT NULL, ADD INDEX idx_disp_dealer (dispatched_dealer_id)",
        'dispatched_at' => "ALTER TABLE devices ADD COLUMN dispatched_at DATETIME NULL",
    );
    foreach ($deviceCols as $col => $ddl) {
        $res = mysqli_query($conn, "SHOW COLUMNS FROM devices LIKE '$col'");
        if ($res && mysqli_num_rows($res) === 0) {
            mysqli_query($conn, $ddl);
        }
    }

    /*
     * 進倉登錄授權卡：只有 UID 在這張表裡的 NFC 卡，才能在 App 隱藏入口
     * （連點版本號 7 下）之後真正登錄設備。連點只是找入口，這張卡才是授權。
     */
    $sqlEnrollCard = "
        CREATE TABLE IF NOT EXISTS enroll_cards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            card_uid VARCHAR(32) NOT NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_enroll_card (card_uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sqlEnrollCard)) {
        throw new Exception('建立 enroll_cards 資料表失敗：' . mysqli_error($conn));
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
        /*
         * 這筆交易是哪位店員經手的。
         *
         * 可為 NULL —— 沒有開班就直接收款仍然允許（不能因為店員忘了開班
         * 就讓門市收不了錢），只是那些交易在交班單上會歸到「未指定」。
         *
         * 存 id 也存姓名快照：店員離職後資料可能被刪除或改名，但歷史交易
         * 仍要查得出當時是誰收的 —— 這正是爭議發生時最需要的資訊。
         */
        'staff_id' => "ALTER TABLE orders ADD COLUMN staff_id INT NULL, ADD INDEX idx_staff (staff_id)",
        'staff_name' => "ALTER TABLE orders ADD COLUMN staff_name VARCHAR(64) NULL",
        /*
         * 發動這筆交易的來源 IP。POS 打到本主機時的 REMOTE_ADDR，
         * 收單當下抓下來，並同時以 UserIP 參數送給 PAYUNi，讓兩邊記錄一致。
         * 幕後授權是伺服器對伺服器，PAYUNi 本來只看得到我們主機 IP，
         * 帶了才知道真正發動端是哪台 POS。
         */
        'user_ip' => "ALTER TABLE orders ADD COLUMN user_ip VARCHAR(45) NULL",
        /*
         * 卡號前六碼（BIN）與收單／發卡銀行代碼。消費者的刷卡簽單上必須
         * 列出收單銀行，這兩個都是簽單與對帳的必要資訊。
         * 從 PAYUNi 回應解密後的 Card6No / CardBank(或 AuthBank) 取得。
         */
        'card6_no' => "ALTER TABLE orders ADD COLUMN card6_no VARCHAR(6) NULL",
        'card_bank' => "ALTER TABLE orders ADD COLUMN card_bank VARCHAR(8) NULL",
        /*
         * 收銀機自己的一組訂單編號（跟我們送 PAYUNi 的 MerTradeNo 不同）。
         * 由 POS 端在發動交易時帶上來，方便門市用自己的單號對帳。
         */
        'store_order_no' => "ALTER TABLE orders ADD COLUMN store_order_no VARCHAR(64) NULL, ADD INDEX idx_store_order_no (store_order_no)",
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

/**
 * 進倉登錄：把裝置寫進 devices（沿用 db_upsert_device 的去重與欄位），
 * 並蓋上 enrolled_at 時間戳，代表這台機器已由進倉人員建檔。
 * 回傳這台機器最後在資料庫裡的 device_id（去重後可能沿用既有那筆的）。
 */
function db_enroll_device($conn, $d, $cardUid = '') {
    db_upsert_device($conn, $d);
    // db_upsert_device 可能因序號去重而沿用既有 device_id，這裡用序號回查最終那筆
    $deviceId = $d['deviceId'];
    if (!empty($d['serialNo'])) {
        $stmt = mysqli_prepare($conn, 'SELECT device_id FROM devices WHERE serial_no = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 's', $d['serialNo']);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($row) { $deviceId = $row['device_id']; }
    }
    // 記下進倉時間與「用哪張授權卡登錄的」（稽核）
    $cardUid = strtoupper(trim((string) $cardUid));
    $stmt = mysqli_prepare($conn,
        'UPDATE devices SET enrolled_at = NOW(), enrolled_card_uid = ? WHERE device_id = ?');
    mysqli_stmt_bind_param($stmt, 'ss', $cardUid, $deviceId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $deviceId;
}

// ── 派工 ─────────────────────────────────────────────────────────

/** 派給客戶（清掉經銷商指派，兩者互斥）。 */
function db_dispatch_device_to_merchant($conn, $deviceId, $merchantId) {
    $stmt = mysqli_prepare($conn,
        'UPDATE devices SET dispatched_merchant_id = ?, dispatched_dealer_id = NULL, dispatched_at = NOW()
         WHERE device_id = ?');
    mysqli_stmt_bind_param($stmt, 'is', $merchantId, $deviceId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/** 派給經銷商（清掉客戶指派，兩者互斥）。 */
function db_dispatch_device_to_dealer($conn, $deviceId, $dealerId) {
    $stmt = mysqli_prepare($conn,
        'UPDATE devices SET dispatched_dealer_id = ?, dispatched_merchant_id = NULL, dispatched_at = NOW()
         WHERE device_id = ?');
    mysqli_stmt_bind_param($stmt, 'is', $dealerId, $deviceId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/** 收回派工（回到未派工／可再派）。 */
function db_recall_device($conn, $deviceId) {
    $stmt = mysqli_prepare($conn,
        'UPDATE devices SET dispatched_merchant_id = NULL, dispatched_dealer_id = NULL, dispatched_at = NULL
         WHERE device_id = ?');
    mysqli_stmt_bind_param($stmt, 's', $deviceId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// ── 進倉登錄授權卡 ──────────────────────────────────────────────

/** 這張卡的 UID 是不是授權的登錄卡（UID 一律大寫比對）。 */
function db_is_enroll_card($conn, $cardUid) {
    $cardUid = strtoupper(trim((string) $cardUid));
    if ($cardUid === '') return false;
    $stmt = mysqli_prepare($conn, 'SELECT 1 FROM enroll_cards WHERE card_uid = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $cardUid);
    mysqli_stmt_execute($stmt);
    $ok = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) !== null;
    mysqli_stmt_close($stmt);
    return $ok;
}

function db_list_enroll_cards($conn) {
    $r = mysqli_query($conn, 'SELECT id, card_uid, note, created_at FROM enroll_cards ORDER BY id');
    return $r ? mysqli_fetch_all($r, MYSQLI_ASSOC) : array();
}

function db_add_enroll_card($conn, $cardUid, $note = '') {
    $cardUid = strtoupper(trim((string) $cardUid));
    if (!preg_match('/^[0-9A-F]{8,32}$/', $cardUid)) {
        throw new Exception('卡片 UID 格式不正確（8～32 位 16 進位）');
    }
    if (db_is_enroll_card($conn, $cardUid)) {
        throw new Exception("這張卡（{$cardUid}）已經是授權登錄卡");
    }
    $stmt = mysqli_prepare($conn, 'INSERT INTO enroll_cards (card_uid, note) VALUES (?,?)');
    mysqli_stmt_bind_param($stmt, 'ss', $cardUid, $note);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('新增授權卡失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

function db_delete_enroll_card($conn, $id) {
    $stmt = mysqli_prepare($conn, 'DELETE FROM enroll_cards WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/** 列出所有登記過的裝置，附帶各自的交易統計 */
/**
 * @param int|null $limit  留 null 代表不分頁，回傳全部 —— 保留原本的行為，
 *                         現有呼叫端（如果有的話）不會因為加這個參數而變動。
 * @param string   $sort   'desc'（預設，最近使用優先）或 'asc'
 */
function db_list_devices($conn, $limit = null, $offset = 0, $sort = 'desc') {
    $order = ($sort === 'asc') ? 'ASC' : 'DESC';
    $sql = "
        SELECT d.*,
               (SELECT COUNT(*) FROM orders o WHERE o.device_id = d.device_id) AS order_cnt,
               (SELECT COALESCE(SUM(o.amount),0) FROM orders o
                 WHERE o.device_id = d.device_id AND o.status='success') AS success_amt,
               (SELECT m.name FROM merchants m WHERE m.id = d.dispatched_merchant_id) AS dispatched_merchant_name,
               (SELECT dl.name FROM dealers dl WHERE dl.id = d.dispatched_dealer_id) AS dispatched_dealer_name
        FROM devices d ORDER BY d.last_seen $order
    ";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    }
    $res = mysqli_query($conn, $sql);
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : array();
}

function db_count_devices($conn) {
    $r = mysqli_query($conn, 'SELECT COUNT(*) c FROM devices');
    return (int) mysqli_fetch_assoc($r)['c'];
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
     * 多租戶客戶後台改版（2026-07-22）：
     *
     *   owner_merchant_id —— 這個經銷商由哪個「客戶」經營。客戶登入網站後台時，
     *                        若有經銷商的 owner_merchant_id = 自己，就顯示
     *                        「經銷商介面」。null = 純公司建立、沒有對應客戶。
     */
    $dealerCols = array(
        'owner_merchant_id' => "ALTER TABLE dealers ADD COLUMN owner_merchant_id INT NULL,
                                ADD INDEX idx_owner_merchant (owner_merchant_id)",
    );
    foreach ($dealerCols as $col => $ddl) {
        $res = mysqli_query($conn, "SHOW COLUMNS FROM dealers LIKE '$col'");
        if ($res && mysqli_num_rows($res) === 0) {
            mysqli_query($conn, $ddl);
        }
    }

    /*
     * 經銷商前置碼。**一個經銷商可以有多個前置碼**，所以獨立一張表，不放
     * 在 dealers 上。前置碼固定 4 個大寫英文字母（例如 ABCD），全系統唯一。
     * 商店代號 = 前置碼 + 後綴（例如 ABCD001），所以看前 4 碼就知道商店
     * 屬於哪個經銷商。收銀機輸入時允許小寫，App 端自動轉大寫。
     */
    $sqlPrefix = "
        CREATE TABLE IF NOT EXISTS dealer_prefixes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dealer_id INT NOT NULL,
            prefix CHAR(4) NOT NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_prefix (prefix),
            INDEX idx_prefix_dealer (dealer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sqlPrefix)) {
        throw new Exception('建立 dealer_prefixes 資料表失敗：' . mysqli_error($conn));
    }

    // 一次性清掉先前錯放在 dealers 上的單一 prefix 欄位（改為 dealer_prefixes 表）
    $res = mysqli_query($conn, "SHOW COLUMNS FROM dealers LIKE 'prefix'");
    if ($res && mysqli_num_rows($res) > 0) {
        mysqli_query($conn, "ALTER TABLE dealers DROP COLUMN prefix");
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
     * 多租戶客戶後台改版（2026-07-22）新增的欄位，逐一補上（重複執行不會出錯）：
     *
     *   store_code —— **本系統自己的商店代號**（不是 mer_id 那個上游 PAYUNi
     *                 MerID）。收銀機登入輸入這個。格式：經銷商前置碼 + 後綴，
     *                 例如 A001。全系統唯一。
     *   dealer_id  —— 經銷商歸屬從客戶層搬到商店層：一個客戶可有多個商店代號，
     *                 每個商店代號只對應一個經銷商。
     */
    $storeCols = array(
        'store_code' => "ALTER TABLE merchant_stores ADD COLUMN store_code VARCHAR(16) NULL,
                         ADD UNIQUE KEY uk_store_code (store_code)",
        'dealer_id' => "ALTER TABLE merchant_stores ADD COLUMN dealer_id INT NULL,
                        ADD INDEX idx_store_dealer (dealer_id)",
        // 列印簽單用的 logo，商店自己在客戶後台上傳。存 data URI（base64），
        // 避開 .htaccess 白名單擋靜態檔的問題，也方便直接發給收銀機列印。
        'logo' => "ALTER TABLE merchant_stores ADD COLUMN logo MEDIUMTEXT NULL",
        // 是否列印「存根聯」（店家留存的那一聯）。預設印；商店可在客戶後台關掉。
        // 「收執聯」（客人那聯）不在這裡設，由店員現場問客人要不要再決定。
        'print_merchant_copy' => "ALTER TABLE merchant_stores ADD COLUMN print_merchant_copy TINYINT(1) NOT NULL DEFAULT 1",
        // 收執聯下方是否印「掃碼退款 QR」。預設印；不喜歡現場退款、偏好由後台退的
        // 商店可以關掉。
        'print_refund_qr' => "ALTER TABLE merchant_stores ADD COLUMN print_refund_qr TINYINT(1) NOT NULL DEFAULT 1",
    );
    foreach ($storeCols as $col => $ddl) {
        $res = mysqli_query($conn, "SHOW COLUMNS FROM merchant_stores LIKE '$col'");
        if ($res && mysqli_num_rows($res) === 0) {
            mysqli_query($conn, $ddl);
        }
    }
    // 回填：商店的經銷商從所屬客戶繼承（只補還沒設的，重複執行安全）。
    // store_code 不自動帶 —— 那要用經銷商前置碼，得由人指定。
    mysqli_query($conn,
        "UPDATE merchant_stores s JOIN merchants m ON m.id = s.merchant_id
         SET s.dealer_id = m.dealer_id
         WHERE s.dealer_id IS NULL AND m.dealer_id IS NOT NULL");

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
            -- 目前開班中的店員。NULL = 沒人開班（仍可收款，只是查不到經手人）
            staff_id INT NULL,
            shift_started_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            INDEX idx_merchant (merchant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sql4)) {
        throw new Exception('建立 merchant_sessions 資料表失敗：' . mysqli_error($conn));
    }

    /*
     * 既有的資料表不會因為 CREATE TABLE IF NOT EXISTS 而長出新欄位，
     * 要另外補。已經上線的主機走的是這條路，不是上面的建表。
     */
    $sessionCols = array(
        'staff_id' => "ALTER TABLE merchant_sessions ADD COLUMN staff_id INT NULL",
        'shift_started_at' => "ALTER TABLE merchant_sessions ADD COLUMN shift_started_at DATETIME NULL",
    );
    foreach ($sessionCols as $col => $ddl) {
        $res = mysqli_query($conn, "SHOW COLUMNS FROM merchant_sessions LIKE '$col'");
        if ($res && mysqli_num_rows($res) === 0) {
            if (!mysqli_query($conn, $ddl)) {
                throw new Exception("merchant_sessions 新增 $col 欄位失敗：" . mysqli_error($conn));
            }
        }
    }
}

/**
 * 店員開班：把店員綁到這個收銀機 session 上。
 *
 * 綁在 session 而不是由 App 每次交易帶 staff_id —— 與商店代號同一個原則：
 * **身分由後端決定，不讓呼叫端指定**。App 自己帶的話，改一個數字就能把
 * 交易記到別人頭上，而交班單、退款權限都是靠這個欄位算的。
 */
function db_start_shift($conn, $sessionId, $staffId) {
    $stmt = mysqli_prepare($conn,
        'UPDATE merchant_sessions SET staff_id = ?, shift_started_at = NOW() WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $staffId, $sessionId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('開班失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/** 交班：解除綁定。收銀機仍保持登入，只是回到「沒人開班」的狀態。 */
function db_end_shift($conn, $sessionId) {
    $stmt = mysqli_prepare($conn,
        'UPDATE merchant_sessions SET staff_id = NULL, shift_started_at = NULL WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $sessionId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * 這個班次的收款彙總。
 *
 * **輕量做法：不建 shifts 資料表**，直接用「這位店員 + 開班時間之後」去算。
 * 班次資料本來就能從交易紀錄推導出來，多開一張表只是多一份要同步的狀態。
 *
 * 代價：交班之後那個班次就不再查得到（沒有留存）。如果日後需要保留歷史
 * 班次報表，再補一張 shifts 表把彙總結果存下來即可，現有欄位都夠用。
 */
function db_sum_shift($conn, $storeId, $staffId, $since) {
    $stmt = mysqli_prepare($conn,
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total
           FROM orders
          WHERE store_id = ? AND staff_id = ? AND status = 'success' AND created_at >= ?");
    mysqli_stmt_bind_param($stmt, 'iis', $storeId, $staffId, $since);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return array(
        'count' => (int) $row['cnt'],
        'total' => (int) $row['total'],
    );
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

// ── 系統設定（總管理者可調整的全站參數）─────────────────────────

/**
 * 通用的 key-value 設定表。
 *
 * 現在只有一項設定（交易紀錄預設每頁筆數），但用 key-value 而不是在
 * config.php 開一個常數，是因為**這是要讓總管理者在後台自己改的**，
 * 常數改了要動到主機檔案，一般管理者做不到。之後若有其他要讓後台調整
 * 的全站參數，直接多存一個 name 就好，不必再開新表。
 */
function db_create_app_settings_table_if_not_exists($conn) {
    $sql = "
        CREATE TABLE IF NOT EXISTS app_settings (
            name VARCHAR(64) PRIMARY KEY,
            value VARCHAR(255) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sql)) {
        throw new Exception('建立 app_settings 資料表失敗：' . mysqli_error($conn));
    }
}

/** 讀取設定值，沒有設定過就回傳 $default（不會寫入資料庫） */
function db_get_setting($conn, $name, $default = null) {
    $stmt = mysqli_prepare($conn, 'SELECT value FROM app_settings WHERE name = ?');
    mysqli_stmt_bind_param($stmt, 's', $name);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ? $row['value'] : $default;
}

/** 寫入或更新設定值 */
function db_set_setting($conn, $name, $value) {
    $stmt = mysqli_prepare($conn,
        'INSERT INTO app_settings (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)');
    mysqli_stmt_bind_param($stmt, 'ss', $name, $value);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('儲存設定失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

// ── 列印簽單範本 ────────────────────────────────────────────────
//
// 範本 = 一組「行」，存成 JSON 放在 app_settings（key = receipt_template）。
// 每一行：text（可含 {{參數}}）、size（small/normal/large/xlarge）、
// bold（bool）、align（left/center/right）。
// 收銀機列印時把 {{參數}} 換成該筆交易的值。原則上一行不混字級。

/** 系統預設範本（還沒設定過時用這個）。 */
function db_default_receipt_lines() {
    return array(
        array('text' => '{{storeName}}', 'size' => 'large', 'bold' => true, 'align' => 'center'),
        array('text' => '{{copyLabel}}', 'size' => 'normal', 'bold' => true, 'align' => 'center'),
        array('text' => '銷售憑證', 'size' => 'normal', 'bold' => false, 'align' => 'center'),
        array('text' => '--------------------------------', 'size' => 'small', 'bold' => false, 'align' => 'center'),
        array('text' => '交易時間：{{time}}', 'size' => 'normal', 'bold' => false, 'align' => 'left'),
        // 商店訂單編號可能很長（商店代號+時間），拆成「標籤」＋「值單獨一列」，
        // 值用小字左對齊，避免跟標籤擠在一起被切行。
        array('text' => '商店訂單編號', 'size' => 'normal', 'bold' => false, 'align' => 'left'),
        array('text' => '{{storeOrderNo}}', 'size' => 'small', 'bold' => false, 'align' => 'left'),
        array('text' => '交易序號：{{payuniTradeNo}}', 'size' => 'small', 'bold' => false, 'align' => 'left'),
        array('text' => '付款方式：{{paymentMethod}}', 'size' => 'normal', 'bold' => false, 'align' => 'left'),
        array('text' => '卡號：{{card6No}}******{{card4No}}', 'size' => 'normal', 'bold' => false, 'align' => 'left'),
        array('text' => '收單銀行：{{cardBank}}', 'size' => 'normal', 'bold' => false, 'align' => 'left'),
        array('text' => '授權碼：{{authCode}}', 'size' => 'normal', 'bold' => false, 'align' => 'left'),
        array('text' => '--------------------------------', 'size' => 'small', 'bold' => false, 'align' => 'center'),
        array('text' => '金額 NT$ {{amount}}', 'size' => 'large', 'bold' => true, 'align' => 'center'),
        array('text' => '感謝惠顧', 'size' => 'small', 'bold' => false, 'align' => 'center'),
    );
}

/** 可用參數清單（後台編輯畫面顯示、也給前端替換用）。 */
function db_receipt_placeholders() {
    return array(
        'copyLabel' => '聯別（存根聯／收執聯，列印時自動帶）',
        'storeName' => '商店名稱',
        'merchantName' => '會員（客戶）名稱',
        'storeCode' => '本系統商店代號',
        'time' => '交易時間',
        'amount' => '金額（數字）',
        'paymentMethod' => '付款方式（信用卡／Apple Pay…）',
        'card6No' => '卡號前六碼',
        'card4No' => '卡號末四碼',
        'cardBank' => '收單銀行',
        'authCode' => '授權碼',
        'payuniTradeNo' => 'PAYUNi 交易序號',
        'storeOrderNo' => '商店訂單編號',
        'merTradeNo' => '系統訂單編號',
        'provider' => '第三方支付',
    );
}

/** 讀出範本行陣列（沒設定過回預設）。一律回傳乾淨、欄位齊全的陣列。 */
function db_get_receipt_lines($conn) {
    $raw = db_get_setting($conn, 'receipt_template', null);
    $lines = $raw ? json_decode($raw, true) : null;
    if (!is_array($lines) || !$lines) {
        return db_default_receipt_lines();
    }
    $sizes = array('small', 'normal', 'large', 'xlarge');
    $aligns = array('left', 'center', 'right');
    $clean = array();
    foreach ($lines as $ln) {
        if (!is_array($ln)) continue;
        $clean[] = array(
            'text' => isset($ln['text']) ? (string) $ln['text'] : '',
            'size' => (isset($ln['size']) && in_array($ln['size'], $sizes, true)) ? $ln['size'] : 'normal',
            'bold' => !empty($ln['bold']),
            'align' => (isset($ln['align']) && in_array($ln['align'], $aligns, true)) ? $ln['align'] : 'left',
        );
    }
    return $clean ?: db_default_receipt_lines();
}

/** 存範本行陣列（會過濾成乾淨結構）。 */
function db_save_receipt_lines($conn, $lines) {
    $sizes = array('small', 'normal', 'large', 'xlarge');
    $aligns = array('left', 'center', 'right');
    $clean = array();
    foreach ((array) $lines as $ln) {
        if (!is_array($ln)) continue;
        $text = isset($ln['text']) ? trim((string) $ln['text']) : '';
        // 全空的行（沒文字）就跳過，避免存一堆空行
        if ($text === '' && empty($ln['keep_empty'])) continue;
        $clean[] = array(
            'text' => mb_substr($text, 0, 120),
            'size' => (isset($ln['size']) && in_array($ln['size'], $sizes, true)) ? $ln['size'] : 'normal',
            'bold' => !empty($ln['bold']),
            'align' => (isset($ln['align']) && in_array($ln['align'], $aligns, true)) ? $ln['align'] : 'left',
        );
    }
    db_set_setting($conn, 'receipt_template', json_encode($clean, JSON_UNESCAPED_UNICODE));
    return $clean;
}

/** 存／讀商店 logo（data URI）。 */
function db_get_store_logo($conn, $storeId) {
    $stmt = mysqli_prepare($conn, 'SELECT logo FROM merchant_stores WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $storeId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ? $row['logo'] : null;
}

function db_save_store_logo($conn, $storeId, $dataUri) {
    $stmt = mysqli_prepare($conn, 'UPDATE merchant_stores SET logo = ? WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'si', $dataUri, $storeId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('儲存商店 logo 失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/** 這家店要不要印「存根聯」（店家留存那聯）。預設 true。 */
function db_get_store_print_merchant_copy($conn, $storeId) {
    $stmt = mysqli_prepare($conn, 'SELECT print_merchant_copy FROM merchant_stores WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $storeId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    // 沒有資料就當預設「要印」
    return $row ? ((int) $row['print_merchant_copy'] === 1) : true;
}

function db_save_store_print_merchant_copy($conn, $storeId, $enabled) {
    $val = $enabled ? 1 : 0;
    $stmt = mysqli_prepare($conn, 'UPDATE merchant_stores SET print_merchant_copy = ? WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $val, $storeId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('儲存存根聯設定失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/** 收執聯要不要印「掃碼退款 QR」。預設 true。 */
function db_get_store_print_refund_qr($conn, $storeId) {
    $stmt = mysqli_prepare($conn, 'SELECT print_refund_qr FROM merchant_stores WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $storeId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ? ((int) $row['print_refund_qr'] === 1) : true;
}

function db_save_store_print_refund_qr($conn, $storeId, $enabled) {
    $val = $enabled ? 1 : 0;
    $stmt = mysqli_prepare($conn, 'UPDATE merchant_stores SET print_refund_qr = ? WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $val, $storeId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('儲存退款 QR 設定失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

// ── 店員 ────────────────────────────────────────────────────────

/**
 * 建立 store_staff 資料表：商店底下的個別店員。
 *
 * ── 為什麼要獨立一層，而不是把店員做成另一組登入帳號 ──────
 *
 * 現有的登入（merchants.login_account）是**機器綁商店**用的：一家店的多台
 * 收銀機共用同一組帳密，登入一次可以用很久。那個設計是對的，不要動它。
 *
 * 店員身分要解決的是不同的問題：「這一班**誰**收了多少」、「這筆退款是
 * 誰按的」。它的生命週期是一個班次，不是一台機器的綁定。
 *
 * 兩者正交，所以疊上去而不是改寫。收銀機仍先用商店帳號登入，開班時再由
 * 店員輸入工號與 PIN。
 *
 * ── PIN 而不是密碼 ──
 * 店員在櫃檯、客人面前、可能戴著手套輸入，長密碼不切實際。PIN 的強度不高，
 * 所以**它不能單獨用來認證** —— 前提是這台機器已經用商店帳號登入過了，
 * PIN 只是在已授權的機器上區分「是哪位同事」。
 */
function db_create_store_staff_table_if_not_exists($conn) {
    $sql = "
        CREATE TABLE IF NOT EXISTS store_staff (
            id INT AUTO_INCREMENT PRIMARY KEY,
            store_id INT NOT NULL,
            staff_code VARCHAR(16) NOT NULL,
            name VARCHAR(64) NOT NULL,
            pin_hash VARCHAR(255) NOT NULL,
            -- 感應卡的 UID（16 進位字串）。可為 NULL —— 只用工號開班的店員
            -- 不需要卡片，兩種方式併行。
            card_uid VARCHAR(32) NULL,
            can_refund TINYINT(1) NOT NULL DEFAULT 0,
            -- 可以在收銀機上把新卡登記給其他店員
            can_enroll TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            -- 工號只要在同一家店裡唯一就好。不同店的店員可以都叫 01，
            -- 強制全系統唯一只會讓店家沒辦法用自己習慣的編號。
            UNIQUE KEY uniq_store_code (store_id, staff_code),
            -- 一張卡在同一家店只能對應一個人。跨店不限制 —— 同一個人可能
            -- 在同集團的不同分店輪班，用同一張卡是合理的。
            UNIQUE KEY uniq_store_card (store_id, card_uid),
            INDEX idx_store (store_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sql)) {
        throw new Exception('建立 store_staff 資料表失敗：' . mysqli_error($conn));
    }

    // 既有資料表要補欄位（正式機走的是這條路，不是上面的建表）
    $cols = array(
        'card_uid' => "ALTER TABLE store_staff ADD COLUMN card_uid VARCHAR(32) NULL,
                       ADD UNIQUE KEY uniq_store_card (store_id, card_uid)",
        'can_enroll' => "ALTER TABLE store_staff ADD COLUMN can_enroll TINYINT(1) NOT NULL DEFAULT 0",
    );
    foreach ($cols as $col => $ddl) {
        $res = mysqli_query($conn, "SHOW COLUMNS FROM store_staff LIKE '$col'");
        if ($res && mysqli_num_rows($res) === 0) {
            if (!mysqli_query($conn, $ddl)) {
                throw new Exception("store_staff 新增 $col 欄位失敗：" . mysqli_error($conn));
            }
        }
    }
}

/**
 * 依感應卡 UID 找店員（刷卡開班用）。只找啟用中的。
 *
 * ⚠️ **UID 不是密碼。** 任何手機都讀得到，空白卡也能改成別人的 UID。
 * 所以查到人之後**一定還要驗 PIN** —— 卡片只是「你有什麼」，
 * PIN 才是真正擋人的那一層。
 */
function db_find_staff_by_card($conn, $storeId, $cardUid) {
    if ($cardUid === '' || $cardUid === null) {
        return null;
    }
    $stmt = mysqli_prepare($conn,
        'SELECT * FROM store_staff WHERE store_id = ? AND card_uid = ? AND active = 1');
    mysqli_stmt_bind_param($stmt, 'is', $storeId, $cardUid);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row;
}

/** 把一張卡綁到某位店員身上（收銀機建檔用）*/
function db_set_staff_card($conn, $staffId, $cardUid) {
    $stmt = mysqli_prepare($conn, 'UPDATE store_staff SET card_uid = ? WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'si', $cardUid, $staffId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('綁定卡片失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/** 這家店有幾位店員 —— 用來判斷是不是「第一張卡」的啟用情境 */
function db_count_staff($conn, $storeId) {
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) c FROM store_staff WHERE store_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $storeId);
    mysqli_stmt_execute($stmt);
    $c = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);
    return $c;
}

/**
 * 這家店有沒有「現在就能刷卡開班、且有建檔權限」的店員。
 *
 * 收銀機建檔的商店密碼放行條件本來只看「一個店員都沒有」，但後台可以
 * 預先建立店員資料（例如先登記姓名、工號、PIN，卡片之後再補）——
 * 這種店員存在，但沒有卡片就沒辦法刷卡開班，一樣會卡住，跟真的
 * 一個店員都沒有是同一種處境。用這支取代單純的數量判斷。
 */
function db_has_enroll_capable_staff($conn, $storeId) {
    $stmt = mysqli_prepare($conn,
        'SELECT 1 FROM store_staff
         WHERE store_id = ? AND active = 1 AND can_enroll = 1 AND card_uid IS NOT NULL
         LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $storeId);
    mysqli_stmt_execute($stmt);
    $has = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) !== null;
    mysqli_stmt_close($stmt);
    return $has;
}

/** @param int|null $limit 留 null 代表不分頁，回傳這家店全部店員 */
function db_list_staff($conn, $storeId, $limit = null, $offset = 0, $sort = 'asc') {
    $order = ($sort === 'desc') ? 'DESC' : 'ASC';
    $sql = "SELECT * FROM store_staff WHERE store_id = ? ORDER BY staff_code $order";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    }
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $storeId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = array();
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function db_count_staff_in_store($conn, $storeId) {
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) c FROM store_staff WHERE store_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $storeId);
    mysqli_stmt_execute($stmt);
    $c = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);
    return $c;
}

function db_find_staff($conn, $id) {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM store_staff WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row;
}

/** 依工號找店員（開班時用）。只找啟用中的。 */
function db_find_staff_by_code($conn, $storeId, $staffCode) {
    $stmt = mysqli_prepare($conn,
        'SELECT * FROM store_staff WHERE store_id = ? AND staff_code = ? AND active = 1');
    mysqli_stmt_bind_param($stmt, 'is', $storeId, $staffCode);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row;
}

/**
 * 新增或更新店員。
 *
 * $pin 傳空字串代表「不修改現有 PIN」—— 編輯姓名或權限時不該被迫重設 PIN。
 * 新增時則必填。
 */
function db_save_staff($conn, $id, $storeId, $staffCode, $name, $pin, $canRefund, $active, $note,
                      $cardUid = null, $canEnroll = 0) {
    // 空字串一律轉成 NULL：UNIQUE 索引允許多個 NULL，但不允許多個空字串 ——
    // 不轉的話第二位沒有卡片的店員會存不進去。
    if ($cardUid === '') { $cardUid = null; }

    if ($id) {
        if ($pin !== '') {
            $hash = password_hash($pin, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn,
                'UPDATE store_staff SET staff_code=?, name=?, pin_hash=?, card_uid=?,
                        can_refund=?, can_enroll=?, active=?, note=? WHERE id=?');
            mysqli_stmt_bind_param($stmt, 'ssssiiisi', $staffCode, $name, $hash, $cardUid,
                $canRefund, $canEnroll, $active, $note, $id);
        } else {
            $stmt = mysqli_prepare($conn,
                'UPDATE store_staff SET staff_code=?, name=?, card_uid=?, can_refund=?,
                        can_enroll=?, active=?, note=? WHERE id=?');
            mysqli_stmt_bind_param($stmt, 'sssiiisi', $staffCode, $name, $cardUid,
                $canRefund, $canEnroll, $active, $note, $id);
        }
    } else {
        $hash = password_hash($pin, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn,
            'INSERT INTO store_staff (store_id, staff_code, name, pin_hash, card_uid,
                                      can_refund, can_enroll, active, note)
             VALUES (?,?,?,?,?,?,?,?,?)');
        mysqli_stmt_bind_param($stmt, 'issssiiis', $storeId, $staffCode, $name, $hash, $cardUid,
            $canRefund, $canEnroll, $active, $note);
    }
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('儲存店員失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

function db_delete_staff($conn, $id) {
    $stmt = mysqli_prepare($conn, 'DELETE FROM store_staff WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/** 這位店員經手過幾筆交易 —— 有紀錄的就不該直接刪除，改為停用 */
function db_count_orders_by_staff($conn, $staffId) {
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM orders WHERE staff_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $staffId);
    mysqli_stmt_execute($stmt);
    $c = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);
    return $c;
}

// ── 掃碼付款中轉頁 ──────────────────────────────────────────────

/**
 * 建立 payment_links 資料表：收銀機顯示的 QR 指向這裡的一筆紀錄。
 *
 * 為什麼要有這張表，而不是把訂單編號直接放進 QR：
 *
 *   1. **QR 是公開的** —— 貼在櫃檯上任何人都能拍。訂單編號可以推測
 *      （POS+時間戳），拿別人的編號去開頁面就能看到金額與店名。
 *      用隨機 token 讓網址不可推測。
 *   2. 金額只能來自這張表，不能來自網址參數。放在網址上等於讓客人
 *      自己決定要付多少。
 *   3. 有效期限要在伺服器強制，不能只靠畫面倒數。
 */
function db_create_payment_links_table_if_not_exists($conn) {
    $sql = "
        CREATE TABLE IF NOT EXISTS payment_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token CHAR(32) NOT NULL UNIQUE,
            mer_trade_no VARCHAR(25) NOT NULL,
            merchant_id INT NULL,
            store_id INT NULL,
            mer_id VARCHAR(32) NULL,
            amount INT NOT NULL,
            store_name VARCHAR(100) NULL,
            method VARCHAR(16) NULL,
            expires_at DATETIME NOT NULL,
            opened_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mer_trade_no (mer_trade_no),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sql)) {
        throw new Exception('建立 payment_links 資料表失敗：' . mysqli_error($conn));
    }
}

/** 掃碼付款頁的有效時間（秒）。客人拿出手機、掃碼、選錢包、認證，5 分鐘夠用。 */
define('PAYMENT_LINK_TTL', 300);

/**
 * 建立一筆付款連結，回傳 token。
 *
 * token 用 random_bytes 而不是 uniqid()/mt_rand() —— 那些是可預測的，
 * 猜得到就能看到別家店的交易金額。
 */
function db_create_payment_link($conn, $merTradeNo, $amount, $merchantId, $storeId, $merId, $storeName) {
    $token = bin2hex(random_bytes(16));
    $stmt = mysqli_prepare($conn,
        'INSERT INTO payment_links
            (token, mer_trade_no, merchant_id, store_id, mer_id, amount, store_name, expires_at)
         VALUES (?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? SECOND))');
    $ttl = PAYMENT_LINK_TTL;
    mysqli_stmt_bind_param($stmt, 'ssiisisi', $token, $merTradeNo, $merchantId, $storeId,
        $merId, $amount, $storeName, $ttl);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('建立付款連結失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
    return $token;
}

/** 取出付款連結。已過期回 null —— 由資料庫判斷時間，不信任呼叫端。 */
function db_find_payment_link($conn, $token) {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $stmt = mysqli_prepare($conn,
        'SELECT * FROM payment_links WHERE token = ? AND expires_at > NOW()');
    mysqli_stmt_bind_param($stmt, 's', $token);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row;
}

/** 記錄客人選了哪種錢包，以及第一次開啟的時間（用來看有多少客人掃了卻沒付完）*/
function db_mark_payment_link_opened($conn, $token, $method = null) {
    $stmt = mysqli_prepare($conn,
        'UPDATE payment_links
            SET opened_at = COALESCE(opened_at, NOW()), method = COALESCE(?, method)
          WHERE token = ?');
    mysqli_stmt_bind_param($stmt, 'ss', $method, $token);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
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

/** @param int|null $limit 留 null 代表不分頁 —— 下拉選單等用途需要完整清單 */
function db_list_dealers($conn, $limit = null, $offset = 0, $sort = 'desc') {
    $order = ($sort === 'asc') ? 'ASC' : 'DESC';
    $sql = "SELECT * FROM dealers ORDER BY id $order";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    }
    $res = mysqli_query($conn, $sql);
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : array();
}

function db_count_dealers($conn) {
    $r = mysqli_query($conn, 'SELECT COUNT(*) c FROM dealers');
    return (int) mysqli_fetch_assoc($r)['c'];
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

// ── 經銷商 ↔ 客戶（誰經營這個經銷商）────────────────────────────

/** 設定經銷商由哪個客戶經營（null = 沒有對應客戶，純公司建立）。 */
function db_set_dealer_owner($conn, $dealerId, $merchantId) {
    $mid = $merchantId ? (int) $merchantId : null;
    $stmt = mysqli_prepare($conn, 'UPDATE dealers SET owner_merchant_id = ? WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $mid, $dealerId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/** 這個客戶經營了哪些經銷商（portal 用來判斷要不要顯示經銷商介面）。 */
function db_find_dealers_by_owner($conn, $merchantId) {
    $stmt = mysqli_prepare($conn,
        'SELECT id, name FROM dealers WHERE owner_merchant_id = ? AND enabled = 1 ORDER BY id');
    mysqli_stmt_bind_param($stmt, 'i', $merchantId);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

/** 這個經銷商旗下的商店（依 merchant_stores.dealer_id），含所屬客戶名稱。 */
function db_list_stores_by_dealer($conn, $dealerId) {
    $stmt = mysqli_prepare($conn,
        'SELECT s.*, m.name AS merchant_name, m.customer_code
         FROM merchant_stores s JOIN merchants m ON m.id = s.merchant_id
         WHERE s.dealer_id = ? ORDER BY s.store_code');
    mysqli_stmt_bind_param($stmt, 'i', $dealerId);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

/** 這個經銷商旗下所有商店的交易彙總（成功筆數/金額），可帶日期區間。 */
function db_dealer_order_summary($conn, $dealerId, $from, $to) {
    $stmt = mysqli_prepare($conn,
        "SELECT COUNT(*) AS total_cnt,
                SUM(o.status='success') AS success_cnt,
                COALESCE(SUM(CASE WHEN o.status='success' THEN o.amount ELSE 0 END),0) AS success_amt
         FROM orders o JOIN merchant_stores s ON s.id = o.store_id
         WHERE s.dealer_id = ? AND o.created_at >= ? AND o.created_at < DATE_ADD(?, INTERVAL 1 DAY)");
    mysqli_stmt_bind_param($stmt, 'iss', $dealerId, $from, $to);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row;
}

/** 派到這個經銷商、還沒再派給客戶的設備（dispatched_dealer_id = 該經銷商）。 */
function db_list_devices_by_dealer($conn, $dealerId) {
    $stmt = mysqli_prepare($conn,
        'SELECT device_id, serial_no, brand, model, enrolled_at
         FROM devices WHERE dispatched_dealer_id = ? ORDER BY enrolled_at DESC');
    mysqli_stmt_bind_param($stmt, 'i', $dealerId);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

/** 一台設備目前是不是派給這個經銷商（授權檢查用）。 */
function db_device_is_dispatched_to_dealer($conn, $deviceId, $dealerId) {
    $stmt = mysqli_prepare($conn,
        'SELECT 1 FROM devices WHERE device_id = ? AND dispatched_dealer_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'si', $deviceId, $dealerId);
    mysqli_stmt_execute($stmt);
    $ok = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) !== null;
    mysqli_stmt_close($stmt);
    return $ok;
}

/** 這個客戶是不是掛在這個經銷商底下（merchants.dealer_id）—— 再派工的授權檢查。 */
function db_merchant_under_dealer($conn, $merchantId, $dealerId) {
    $stmt = mysqli_prepare($conn,
        'SELECT 1 FROM merchants WHERE id = ? AND dealer_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $merchantId, $dealerId);
    mysqli_stmt_execute($stmt);
    $ok = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) !== null;
    mysqli_stmt_close($stmt);
    return $ok;
}

// ── 經銷商前置碼（一個經銷商可有多個，4 碼大寫英文，全系統唯一）─────

/** 前置碼格式：正好 4 個大寫英文字母。輸入的小寫會被轉大寫。 */
function db_normalize_prefix($prefix) {
    return strtoupper(trim((string) $prefix));
}
function db_is_valid_prefix($prefix) {
    return (bool) preg_match('/^[A-Z]{4}$/', $prefix);
}

function db_list_dealer_prefixes($conn, $dealerId) {
    $stmt = mysqli_prepare($conn,
        'SELECT id, dealer_id, prefix, note FROM dealer_prefixes WHERE dealer_id = ? ORDER BY prefix');
    mysqli_stmt_bind_param($stmt, 'i', $dealerId);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

/** 所有前置碼 + 所屬經銷商名稱，供商店表單的前置碼下拉用 */
function db_list_all_prefixes($conn) {
    $r = mysqli_query($conn,
        'SELECT p.id, p.prefix, p.dealer_id, d.name AS dealer_name
         FROM dealer_prefixes p JOIN dealers d ON d.id = p.dealer_id
         ORDER BY p.prefix');
    return mysqli_fetch_all($r, MYSQLI_ASSOC);
}

/** 用前置碼找出所屬經銷商，查無回 null */
function db_find_prefix($conn, $prefix) {
    $prefix = db_normalize_prefix($prefix);
    $stmt = mysqli_prepare($conn, 'SELECT id, dealer_id, prefix FROM dealer_prefixes WHERE prefix = ?');
    mysqli_stmt_bind_param($stmt, 's', $prefix);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function db_add_dealer_prefix($conn, $dealerId, $prefix, $note = '') {
    $prefix = db_normalize_prefix($prefix);
    if (!db_is_valid_prefix($prefix)) {
        throw new Exception('前置碼必須是 4 個英文字母');
    }
    if (db_find_prefix($conn, $prefix)) {
        throw new Exception("前置碼「{$prefix}」已經被使用");
    }
    $stmt = mysqli_prepare($conn,
        'INSERT INTO dealer_prefixes (dealer_id, prefix, note) VALUES (?,?,?)');
    mysqli_stmt_bind_param($stmt, 'iss', $dealerId, $prefix, $note);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('新增前置碼失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/** 刪除前置碼。已有商店代號用到這個前置碼時擋下 —— 刪了會讓那些店對不到經銷商。 */
function db_delete_dealer_prefix($conn, $id) {
    $stmt = mysqli_prepare($conn, 'SELECT prefix FROM dealer_prefixes WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row) return;

    $like = $row['prefix'] . '%';
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) c FROM merchant_stores WHERE store_code LIKE ?');
    mysqli_stmt_bind_param($stmt, 's', $like);
    mysqli_stmt_execute($stmt);
    $used = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);
    if ($used > 0) {
        throw new Exception("前置碼「{$row['prefix']}」已有 {$used} 家商店在用，無法刪除");
    }

    $stmt = mysqli_prepare($conn, 'DELETE FROM dealer_prefixes WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * 產生某前置碼底下的下一個商店代號（前置碼 + **6 碼**流水號，例如 NPAA000001）。
 * 取該前置碼現有後綴的最大數值 +1（用 CAST 取數值，不受手動代號長短影響）。
 */
function db_next_store_code($conn, $prefix) {
    $prefix = db_normalize_prefix($prefix);
    $like = $prefix . '%';
    // 前置碼固定 4 碼，所以後綴從第 5 個字元起
    $stmt = mysqli_prepare($conn,
        'SELECT MAX(CAST(SUBSTRING(store_code, 5) AS UNSIGNED)) AS m
         FROM merchant_stores WHERE store_code LIKE ?');
    mysqli_stmt_bind_param($stmt, 's', $like);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $next = ($row && $row['m'] !== null) ? (int) $row['m'] + 1 : 1;
    return $prefix . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
}

/** 某前置碼底下有幾家商店（用來判斷前置碼能不能刪） */
function db_count_stores_by_prefix($conn, $prefix) {
    $like = db_normalize_prefix($prefix) . '%';
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) c FROM merchant_stores WHERE store_code LIKE ?');
    mysqli_stmt_bind_param($stmt, 's', $like);
    mysqli_stmt_execute($stmt);
    $c = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);
    return $c;
}

/** 商店代號是否已被別家店用（排除自己） */
function db_store_code_taken($conn, $storeCode, $exceptStoreId = 0) {
    $stmt = mysqli_prepare($conn,
        'SELECT id FROM merchant_stores WHERE store_code = ? AND id <> ?');
    mysqli_stmt_bind_param($stmt, 'si', $storeCode, $exceptStoreId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row !== null;
}

// ── 客戶 ────────────────────────────────────────────────────────

/**
 * @param int|null $dealerId 指定經銷商就只列該經銷商底下的客戶（不分頁 ——
 *                           目前這個用法只在小範圍情境使用）
 * @param int|null $limit    留 null 代表不分頁，回傳全部
 */
function db_list_merchants($conn, $dealerId = null, $limit = null, $offset = 0, $sort = 'desc') {
    $order = ($sort === 'asc') ? 'ASC' : 'DESC';
    if ($dealerId === null) {
        $sql = "SELECT * FROM merchants ORDER BY id $order";
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        }
        $res = mysqli_query($conn, $sql);
        return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : array();
    }
    $sql = "SELECT * FROM merchants WHERE dealer_id = ? ORDER BY id $order";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    }
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $dealerId);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

function db_count_merchants($conn) {
    $r = mysqli_query($conn, 'SELECT COUNT(*) c FROM merchants');
    return (int) mysqli_fetch_assoc($r)['c'];
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

/**
 * 員工登入用：只靠客戶編號定位客戶。
 *
 * 員工登入時手上只有卡片/工號 + PIN，沒有商店帳號，所以不能用
 * db_find_merchant_by_login。customer_code 本身就有 UNIQUE 索引，
 * 單獨拿它定位客戶是安全的（帳號只是同一客戶底下多帳號時才需要）。
 */
function db_find_merchant_by_code($conn, $customerCode) {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM merchants WHERE customer_code = ?');
    mysqli_stmt_bind_param($stmt, 's', $customerCode);
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

/**
 * 用本系統商店代號找商店（收銀機登入用）。連帶帶出所屬客戶名稱與啟用狀態，
 * 讓呼叫端一次判斷商店與客戶是否都啟用。查無回 null。商店代號一律大寫比對。
 */
function db_find_store_by_code($conn, $storeCode) {
    $storeCode = strtoupper(trim((string) $storeCode));
    if ($storeCode === '') return null;
    $stmt = mysqli_prepare($conn,
        'SELECT s.*, m.name AS merchant_name, m.enabled AS merchant_enabled
         FROM merchant_stores s JOIN merchants m ON m.id = s.merchant_id
         WHERE s.store_code = ?');
    mysqli_stmt_bind_param($stmt, 's', $storeCode);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * @param string $storeCode 本系統商店代號（前置碼+後綴）。dealer_id 由前置碼決定。
 */
function db_save_store($conn, $id, $merchantId, $name, $merId, $provider, $enabled, $note, $storeCode = '') {
    $storeCode = db_normalize_prefix($storeCode); // 商店代號一律大寫存
    $dealerId = null;
    if ($storeCode !== '') {
        // 格式：前置碼 4 個大寫字母 + 4~12 位數字（總長 8~16，塞得進 VARCHAR(16)）
        if (!preg_match('/^[A-Z]{4}[0-9]{4,12}$/', $storeCode)) {
            throw new Exception('商店代號格式錯誤：前置碼 4 個英文字母 + 4 到 12 位數字');
        }
        // 商店代號前 4 碼 = 前置碼 → 決定經銷商歸屬
        $prefix = db_find_prefix($conn, substr($storeCode, 0, 4));
        if (!$prefix) {
            throw new Exception('商店代號的前 4 碼不是有效的經銷商前置碼');
        }
        if (db_store_code_taken($conn, $storeCode, $id)) {
            throw new Exception("商店代號「{$storeCode}」已經被其他商店使用");
        }
        $dealerId = (int) $prefix['dealer_id'];
    }

    if ($id) {
        $stmt = mysqli_prepare($conn,
            'UPDATE merchant_stores SET name=?, mer_id=?, provider=?, enabled=?, note=?, store_code=?, dealer_id=?
             WHERE id=? AND merchant_id=?');
        // store_code / dealer_id 允許為空（尚未指派）
        $sc = ($storeCode === '') ? null : $storeCode;
        mysqli_stmt_bind_param($stmt, 'sssissiii',
            $name, $merId, $provider, $enabled, $note, $sc, $dealerId, $id, $merchantId);
    } else {
        $stmt = mysqli_prepare($conn,
            'INSERT INTO merchant_stores (merchant_id, name, mer_id, provider, enabled, note, store_code, dealer_id)
             VALUES (?,?,?,?,?,?,?,?)');
        $sc = ($storeCode === '') ? null : $storeCode;
        mysqli_stmt_bind_param($stmt, 'isssissi',
            $merchantId, $name, $merId, $provider, $enabled, $note, $sc, $dealerId);
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
        'SELECT s.id AS session_id, s.store_id, s.staff_id, s.shift_started_at,
                m.id AS merchant_id, m.name AS merchant_name, m.dealer_id,
                m.enabled AS merchant_enabled,
                st.mer_id, st.name AS store_name, st.provider, st.enabled AS store_enabled,
                sf.name AS staff_name, sf.staff_code, sf.can_refund, sf.can_enroll,
                sf.active AS staff_active
         FROM merchant_sessions s
         JOIN merchants m ON m.id = s.merchant_id
         LEFT JOIN merchant_stores st ON st.id = s.store_id
         LEFT JOIN store_staff sf ON sf.id = s.staff_id
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
                                 $storeId = null, $dealerId = null, $paymentMethod = 'credit',
                                 $staffId = null, $staffName = null, $userIp = null, $storeOrderNo = null) {
    $stmt = mysqli_prepare($conn,
        'INSERT INTO orders (mer_trade_no, amount, status, device_id, device_serial,
                             card_inst, merchant_id, mer_id, store_id, dealer_id, payment_method,
                             staff_id, staff_name, user_ip, store_order_no)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $status = 'pending';
    // 型別字串要跟欄位順序一一對應：整數欄位是 i，其餘 s。
    // 之前 appVersion 就因為型別對錯位置，把 "0.1-dev" 靜默轉成 0。
    mysqli_stmt_bind_param($stmt, 'sisssiisiisisss', $merTradeNo, $amount, $status, $deviceId, $deviceSerial,
        $cardInst, $merchantId, $merId, $storeId, $dealerId, $paymentMethod, $staffId, $staffName,
        $userIp, $storeOrderNo);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('寫入訂單紀錄失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/**
 * 收到 PAYUNi 回應後更新訂單狀態。
 *
 * $card6No / $cardBank 用 COALESCE 更新：只有帶值時才寫入，避免後續的
 * pending→success 多段更新用 null 把已存的卡片資訊蓋掉。
 */
function db_update_order_result($conn, $merTradeNo, $status, $payuniTradeNo, $authCode, $card4No, $message, $rawResponse, $card6No = null, $cardBank = null) {
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE orders SET status = ?, payuni_trade_no = ?, auth_code = ?, card4_no = ?, message = ?, raw_response = ?,
                           card6_no = COALESCE(?, card6_no), card_bank = COALESCE(?, card_bank)
         WHERE mer_trade_no = ?'
    );
    mysqli_stmt_bind_param(
        $stmt,
        'sssssssss',
        $status,
        $payuniTradeNo,
        $authCode,
        $card4No,
        $message,
        $rawResponse,
        $card6No,
        $cardBank,
        $merTradeNo
    );
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('更新訂單紀錄失敗：' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

/**
 * 收銀機的交易紀錄查詢。
 *
 * **一定要同時用 store_id 過濾**，不能只靠 device_id ——
 * 收銀機可以換綁到別家商店（登出再用另一組帳號登入），只比對裝置的話，
 * 新的店員就會看到前一家商店的交易金額。
 *
 * @param string|null $deviceId null = 查整家商店（給店長看的），
 *                              有值 = 只查這台機器（店員對帳用）
 * @param string|null $date     YYYY-MM-DD，null = 不限日期
 */
function db_list_store_orders($conn, $storeId, $deviceId = null, $date = null, $limit = 100) {
    $sql = 'SELECT mer_trade_no, amount, status, payuni_trade_no, auth_code, card4_no,
                   message, payment_method, card_inst, device_id, staff_name,
                   created_at, updated_at
              FROM orders
             WHERE store_id = ?';
    $types = 'i';
    $params = array($storeId);

    if ($deviceId !== null && $deviceId !== '') {
        $sql .= ' AND device_id = ?';
        $types .= 's';
        $params[] = $deviceId;
    }
    if ($date !== null && $date !== '') {
        $sql .= ' AND DATE(created_at) = ?';
        $types .= 's';
        $params[] = $date;
    }
    // 新的排前面 —— 收銀員要找的幾乎都是剛才那筆
    $sql .= ' ORDER BY id DESC LIMIT ?';
    $types .= 'i';
    $params[] = (int) $limit;

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = array();
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * 這家商店在指定日期的收款彙總。
 *
 * ⚠️ **只計入 status='success'，並扣掉已成功的退款。**
 * 把 pending 也算進去會讓店員以為收到了實際上沒收到的錢；不扣退款則會讓
 * 帳面永遠比實際多。這個數字是店員拿來跟現場對帳的，寧可保守。
 */
function db_sum_store_orders($conn, $storeId, $deviceId = null, $date = null) {
    $sql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total
              FROM orders
             WHERE store_id = ? AND status = 'success'";
    $types = 'i';
    $params = array($storeId);
    if ($deviceId !== null && $deviceId !== '') {
        $sql .= ' AND device_id = ?';
        $types .= 's';
        $params[] = $deviceId;
    }
    if ($date !== null && $date !== '') {
        $sql .= ' AND DATE(created_at) = ?';
        $types .= 's';
        $params[] = $date;
    }
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    // 退款要一併扣掉，否則帳面會比實際收到的多
    $sql2 = "SELECT COALESCE(SUM(r.amount), 0) AS refunded
               FROM refunds r
               JOIN orders o ON o.mer_trade_no = r.mer_trade_no
              WHERE o.store_id = ? AND r.status = 'success' AND r.close_type = 2";
    $types2 = 'i';
    $params2 = array($storeId);
    if ($deviceId !== null && $deviceId !== '') {
        $sql2 .= ' AND o.device_id = ?';
        $types2 .= 's';
        $params2[] = $deviceId;
    }
    if ($date !== null && $date !== '') {
        $sql2 .= ' AND DATE(r.created_at) = ?';
        $types2 .= 's';
        $params2[] = $date;
    }
    $stmt2 = mysqli_prepare($conn, $sql2);
    mysqli_stmt_bind_param($stmt2, $types2, ...$params2);
    mysqli_stmt_execute($stmt2);
    $row2 = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt2));
    mysqli_stmt_close($stmt2);

    return array(
        'count' => (int) $row['cnt'],
        'total' => (int) $row['total'],
        'refunded' => (int) $row2['refunded'],
        'net' => (int) $row['total'] - (int) $row2['refunded'],
    );
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
 * 同一商店的「商店訂單編號」是否已被占用。
 *
 * 用於擋掉進銷存系統重複送同一張單號 —— 只擋**已成功或處理中**的，
 * 若之前那筆是失敗的則放行（允許用同一單號重試）。
 * 依商店（store_id）判定：每家店的進銷存各自編號，跨店不算重複。
 *
 * @return array|null 撞號的既有訂單（有的話），否則 null
 */
function db_find_active_order_by_store_order_no($conn, $storeId, $storeOrderNo) {
    if ($storeOrderNo === null || $storeOrderNo === '' || $storeId === null) {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT mer_trade_no, status FROM orders
         WHERE store_id = ? AND store_order_no = ? AND status IN ('success', 'pending')
         ORDER BY id DESC LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'is', $storeId, $storeOrderNo);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row;
}

/**
 * 當外部進銷存／店員都沒帶「商店訂單編號」時，用這台機器所屬商店的
 * 商店代號 + Unix timestamp（10 碼）+ 2 碼亂數自動產生一組
 * （例：NPAA000001178469288742，共 22 碼，只含英文與數字、無連字號）。
 * —— 使用者 2026-07-22 指定：不要「-」、時間用 10 碼 Unix timestamp、亂數 2 碼。
 *
 * 用商店的 store_code（本系統商店代號，非上游 MerID）。取不到 store_code
 * 的舊資料就退回用 mer_id 或 'POS'。尾碼 2 碼亂數避免同店同秒撞號。
 */
function db_auto_store_order_no($conn, $storeId) {
    $prefix = 'POS';
    if ($storeId !== null) {
        $stmt = mysqli_prepare($conn, 'SELECT store_code, mer_id FROM merchant_stores WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $storeId);
        mysqli_stmt_execute($stmt);
        $s = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($s) {
            $prefix = !empty($s['store_code']) ? $s['store_code']
                    : (!empty($s['mer_id']) ? $s['mer_id'] : 'POS');
        }
    }
    return $prefix . time() . sprintf('%02d', mt_rand(0, 99));
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
function db_insert_pending_refund($conn, $merTradeNo, $payuniTradeNo, $closeType, $amount,
                                  $staffId = null, $staffName = null) {
    // refunds 也要記經手人。退款是把錢還出去，「誰按的」比收款更需要留存。
    foreach (array(
        'staff_id' => "ALTER TABLE refunds ADD COLUMN staff_id INT NULL",
        'staff_name' => "ALTER TABLE refunds ADD COLUMN staff_name VARCHAR(64) NULL",
    ) as $col => $ddl) {
        $res = mysqli_query($conn, "SHOW COLUMNS FROM refunds LIKE '$col'");
        if ($res && mysqli_num_rows($res) === 0) {
            mysqli_query($conn, $ddl);
        }
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO refunds (mer_trade_no, payuni_trade_no, close_type, amount, status,
                              staff_id, staff_name) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $status = 'pending';
    mysqli_stmt_bind_param($stmt, 'ssiisis', $merTradeNo, $payuniTradeNo, $closeType, $amount,
        $status, $staffId, $staffName);
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
