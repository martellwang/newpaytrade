<?php
/**
 * 收銀機登入身分解析 —— 把 X-POS-Token 換成「這筆交易該用哪個商店代號」。
 *
 * ⚠️ authorize-direct.php 裡有一份幾乎相同的邏輯，**故意沒有一併改用這裡的
 *    函式**。那支是每天都在跑的正式金流端點，為了消除重複而動它不划算
 *    （payuni_query.php 開頭也記著同樣的取捨）。
 *
 *    但這代表**兩份要一起維護**：日後若修改身分驗證或停用判斷的規則，
 *    兩邊都要改。改完務必兩條路徑都重新驗證。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * 解析收銀機登入身分。
 *
 * @param string $posToken   App 帶來的 X-POS-Token，可為空字串
 * @param bool   $allowLegacy 是否允許未帶 token 時退回 config 的預設商店代號
 * @return array 成功：array('ok' => true, 'merchantId' =>, 'storeId' =>,
 *                           'dealerId' =>, 'merId' =>)
 *               失敗：array('ok' => false, 'httpCode' =>, 'body' => 要回傳的陣列)
 */
function pos_resolve_identity($posToken, $allowLegacy = false) {
    if ($posToken === '' || $posToken === null) {
        /*
         * 沒帶 token。
         *
         * 刷卡那條路允許退回 config 的預設商店代號，是為了讓還沒更新 App 的
         * 舊收銀機能繼續收款。**新功能不繼承這個包袱** —— LINE Pay 從第一天
         * 就強制登入，不必再背一次相容性債。
         */
        if (!$allowLegacy) {
            return array('ok' => false, 'httpCode' => 401, 'body' => array(
                'status' => 'failed',
                'message' => '請先登入收銀機',
                'needLogin' => true,
            ));
        }
        $fallback = defined('PAYUNI_MER_ID') ? PAYUNI_MER_ID : '';
        if ($fallback === '') {
            return array('ok' => false, 'httpCode' => 500, 'body' => array(
                'status' => 'failed', 'message' => '系統尚未設定商店代號',
            ));
        }
        return array('ok' => true, 'merchantId' => null, 'storeId' => null,
                     'dealerId' => null, 'merId' => $fallback);
    }

    try {
        $conn = db_connect();
        db_create_merchants_table_if_not_exists($conn);
        $session = db_find_session_by_token($conn, $posToken);
    } catch (Exception $e) {
        error_log('查詢收銀機登入身分失敗：' . $e->getMessage());
        return array('ok' => false, 'httpCode' => 500, 'body' => array(
            'status' => 'failed', 'message' => '系統錯誤，請稍後再試',
        ));
    }

    if (!$session) {
        // token 無效、客戶或商店已停用 —— 要明確擋下，不能默默退回預設商店
        // 代號，那會讓已停用的客戶還能繼續收款
        return array('ok' => false, 'httpCode' => 401, 'body' => array(
            'status' => 'failed',
            'message' => '收銀機登入已失效，請重新登入',
            'needLogin' => true,
        ));
    }

    if (empty($session['store_id']) || empty($session['mer_id'])) {
        // 登入了但還沒選分店。沒有商店代號就不知道這筆錢要進哪家店。
        return array('ok' => false, 'httpCode' => 400, 'body' => array(
            'status' => 'failed',
            'message' => '尚未選擇商店，請重新登入並選擇',
            'needStoreSelection' => true,
        ));
    }

    return array(
        'ok' => true,
        'merchantId' => (int) $session['merchant_id'],
        'storeId' => (int) $session['store_id'],
        'dealerId' => $session['dealer_id'] !== null ? (int) $session['dealer_id'] : null,
        'merId' => $session['mer_id'],
    );
}
