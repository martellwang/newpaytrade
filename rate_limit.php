<?php
/**
 * 速率限制（rate limiting）—— 防止 API Key 外流後被當成測卡（carding）工具。
 *
 * 威脅情境：BACKEND_API_KEY 存在 APK 裡，反組譯就能取得。拿到之後可以無限
 * 次呼叫 authorize-direct.php，把大量外流卡號丟進來測哪些還有效
 * （回 success 就是有效卡）。每一筆都是用我們的商店代號送出，後果是
 * 手續費、爭議款，最壞是被 PAYUNi 風控停權。
 *
 * 三道防線：
 *   1. 單一 IP 的請求頻率（正常收銀機不可能一分鐘幾十筆）
 *   2. 單一 IP 的「失敗」次數（測卡的失敗率極高，這是最有效的特徵）
 *   3. 同一張卡的嘗試次數（同卡反覆試也是測卡特徵）
 *
 * ⚠️ 卡號絕對不落地：第 3 道防線用 HMAC-SHA256(卡號, HashKey) 當識別碼。
 *    刻意用 HMAC 而非單純 SHA256 —— 卡號空間很小（且 BIN 前六碼可枚舉），
 *    單純雜湊可以暴力反推出原始卡號，加了只有我們知道的金鑰才安全。
 */

require_once __DIR__ . '/config.php';

// ---- 門檻設定（可視實際營運調整）----
// 正常收銀情境一分鐘刷不了幾筆，設 20 已經很寬鬆
define('RL_IP_MAX_PER_MINUTE', 20);
// 連續失敗是測卡最明顯的特徵，達到就鎖住這個 IP 一段時間
define('RL_IP_MAX_FAILURES', 10);
define('RL_IP_FAILURE_WINDOW_SEC', 600);   // 10 分鐘內
// 同一張卡短時間反覆嘗試
define('RL_CARD_MAX_PER_HOUR', 5);

/** 建立資料表（由 setup_db.php 呼叫） */
function rl_create_table_if_not_exists($conn) {
    $sql = "
        CREATE TABLE IF NOT EXISTS rate_limit_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            bucket VARCHAR(80) NOT NULL,
            outcome VARCHAR(10) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_bucket_time (bucket, created_at),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!mysqli_query($conn, $sql)) {
        throw new Exception('建立 rate_limit_events 資料表失敗：' . mysqli_error($conn));
    }
}

/**
 * 取得呼叫端 IP。
 *
 * ⚠️ 刻意「不」信任 X-Forwarded-For：那個 header 是呼叫端自己送的，
 * 攻擊者每次帶不同的假值就能完全繞過速率限制，等於這道防線不存在。
 * 如果之後前面真的架了 CDN/反向代理，必須改成「只信任已知代理來源送來的
 * XFF」，不能無條件採用。
 */
function rl_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
}

/** 用卡號算出不可反推的識別碼（絕不儲存卡號本身） */
function rl_card_bucket($cardNumber) {
    if ($cardNumber === '' || $cardNumber === null) return null;
    // 取前 32 碼就足以避免碰撞，也讓欄位短一點
    return 'card:' . substr(hash_hmac('sha256', $cardNumber, PAYUNI_HASH_KEY), 0, 32);
}

function rl_ip_bucket() {
    return 'ip:' . rl_client_ip();
}

/** 數某個 bucket 在過去 N 秒內的事件數 */
function rl_count($conn, $bucket, $windowSec, $outcome = null) {
    if ($outcome === null) {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT COUNT(*) AS c FROM rate_limit_events
             WHERE bucket = ? AND created_at > (NOW() - INTERVAL ? SECOND)'
        );
        mysqli_stmt_bind_param($stmt, 'si', $bucket, $windowSec);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT COUNT(*) AS c FROM rate_limit_events
             WHERE bucket = ? AND outcome = ? AND created_at > (NOW() - INTERVAL ? SECOND)'
        );
        mysqli_stmt_bind_param($stmt, 'ssi', $bucket, $outcome, $windowSec);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return (int) $row['c'];
}

/** 記一筆事件。outcome: 'attempt'（每次呼叫）或 'failed'（授權/退款失敗） */
function rl_record($conn, $bucket, $outcome) {
    if ($bucket === null) return;
    $stmt = mysqli_prepare($conn, 'INSERT INTO rate_limit_events (bucket, outcome) VALUES (?, ?)');
    mysqli_stmt_bind_param($stmt, 'ss', $bucket, $outcome);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * 檢查是否超過限制。
 * @return string|null 超過時回傳給使用者看的訊息，沒超過回 null
 *
 * 回傳的訊息刻意模糊（不說是哪一道防線、剩幾次），避免攻擊者靠回應
 * 反推出門檻值來調整攻擊節奏。詳細原因只寫進 error_log。
 */
function rl_check($conn, $cardNumber = null) {
    $ipBucket = rl_ip_bucket();

    // 每次請求會記 1 筆 attempt，失敗時再多記 1 筆 failed。
    // 「幾次請求」的門檻一律只數 attempt，不然一筆失敗的請求會被算成兩次，
    // 門檻等於被腰斬（實測發現過的問題）。
    // 1. 這個 IP 的整體請求頻率
    if (rl_count($conn, $ipBucket, 60, 'attempt') >= RL_IP_MAX_PER_MINUTE) {
        error_log('速率限制：IP ' . rl_client_ip() . ' 每分鐘請求數超過上限');
        return '請求過於頻繁，請稍後再試';
    }

    // 2. 這個 IP 的失敗次數（測卡最明顯的特徵）
    if (rl_count($conn, $ipBucket, RL_IP_FAILURE_WINDOW_SEC, 'failed') >= RL_IP_MAX_FAILURES) {
        error_log('速率限制：IP ' . rl_client_ip() . ' 失敗次數過多，暫時封鎖');
        return '因連續多次交易失敗，此裝置已暫時停止服務，請稍後再試或聯絡客服';
    }

    // 3. 同一張卡的嘗試次數
    $cardBucket = rl_card_bucket($cardNumber);
    if ($cardBucket !== null && rl_count($conn, $cardBucket, 3600, 'attempt') >= RL_CARD_MAX_PER_HOUR) {
        error_log('速率限制：同一張卡一小時內嘗試次數過多');
        return '這張卡片嘗試次數過多，請稍後再試或改用其他卡片';
    }

    return null;
}

/** 呼叫進來時記一次 attempt（IP 與卡片各記一筆） */
function rl_record_attempt($conn, $cardNumber = null) {
    rl_record($conn, rl_ip_bucket(), 'attempt');
    rl_record($conn, rl_card_bucket($cardNumber), 'attempt');
}

/** 交易失敗時記一次 failed，累積到門檻就會觸發封鎖 */
function rl_record_failure($conn, $cardNumber = null) {
    rl_record($conn, rl_ip_bucket(), 'failed');
    rl_record($conn, rl_card_bucket($cardNumber), 'failed');
}

/**
 * 清掉舊資料，避免資料表無限成長。
 * 用機率觸發（約 1% 的請求會執行），省得另外設排程。
 */
function rl_cleanup_occasionally($conn) {
    if (mt_rand(1, 100) !== 1) return;
    // 保留 7 天，足夠事後查異常，也不會讓資料表太大
    mysqli_query($conn, 'DELETE FROM rate_limit_events WHERE created_at < (NOW() - INTERVAL 7 DAY)');
}
