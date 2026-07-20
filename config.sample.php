<?php
// 這個檔案放正式環境的密鑰與設定，不要提交進版控，也不要在這個檔案裡
// echo/var_dump 任何內容（就算被人直接用網址打開，也只會看到空白頁）。
// 部署到主機後，請直接在主機上編輯這個檔案填入正式數值，不要在本機
// 填好正式密鑰後再上傳。

/*
 * ══ 上游金流機構 ═══════════════════════════════════════════════
 *
 * 這套系統是 TMS，未來會接不只一家上游（其他金流商、收單銀行、電子支付
 * 機構）。設定有新舊兩種寫法，**兩種都能動**：
 *
 * ── 舊寫法（目前正式環境在用）──
 *   直接用下面的 PAYUNI_* 常數。系統會自動組成一家名為 payuni 的上游。
 *   只有一家上游時這樣就夠，不需要改。
 *
 * ── 新寫法（要接第二家時改用這個）──
 *   定義 $PROVIDERS 陣列，一家一個區塊，然後用 DEFAULT_PROVIDER 指定
 *   預設走哪家。有 $PROVIDERS 時系統會忽略下面的 PAYUNI_* 常數。
 *
 *   $PROVIDERS = array(
 *       'payuni' => array(
 *           'label' => 'PAYUNi 統一金流',
 *           'driver' => 'payuni',          // 對應 providers/payuni.php
 *           'enabled' => true,
 *           'credentials' => array(
 *               'mer_id' => '', 'hash_key' => '', 'hash_iv' => '', 'agent_id' => '',
 *           ),
 *           'endpoints' => array(
 *               'authorize' => 'https://api.payuni.com.tw/api/credit',
 *               'close' => 'https://api.payuni.com.tw/api/trade/close',
 *               'query' => 'https://api.payuni.com.tw/api/trade/query',
 *               'merchant_status' => 'https://api.payuni.com.tw/api/agent/search_merchant_status',
 *           ),
 *       ),
 *       // 'somebank' => array('driver' => 'somebank', ...),
 *   );
 *   define('DEFAULT_PROVIDER', 'payuni');
 *
 * 每家上游的加密方式、欄位名稱、支援功能都不一樣，那些寫在
 * providers/<driver>.php 裡，不要塞進這個設定檔。
 */

// PAYUNi 統一金流串接資訊（從 PAYUNi 後台「會員 > 商店清單 > 串接設定」取得）
define('PAYUNI_MER_ID', '');
define('PAYUNI_HASH_KEY', ''); // 32 碼
define('PAYUNI_HASH_IV', '');  // 16 碼

// 幕後信用卡授權 API 網址
// 測試環境：https://sandbox-api.payuni.com.tw/api/credit
// 正式環境：https://api.payuni.com.tw/api/credit
define('PAYUNI_DIRECT_AUTH_URL', 'https://api.payuni.com.tw/api/credit');

// 交易請退款 API 網址（CloseType 1=請款 2=退款 -1/-2=取消）
// 測試環境：https://sandbox-api.payuni.com.tw/api/trade/close
// 正式環境：https://api.payuni.com.tw/api/trade/close
define('PAYUNI_REFUND_URL', 'https://api.payuni.com.tw/api/trade/close');

// 交易查詢 API 網址（用來查逾時 pending 交易的真實結果）
// 測試環境：https://sandbox-api.payuni.com.tw/api/trade/query
// 正式環境：https://api.payuni.com.tw/api/trade/query
define('PAYUNI_QUERY_URL', 'https://api.payuni.com.tw/api/trade/query');

/*
 * ── LINE Pay 幕後 ─────────────────────────────────────────────
 *
 * 收銀機顯示 QR、客人用手機掃碼付款。與信用卡共用同一組 Hash Key / IV，
 * 只是端點與 Version 不同（LINE Pay 固定 1.2）。
 *
 * ⚠️ 開通前這支一定會失敗，需要兩道申請：
 *   1. 向 PAYUNi 申請開通並**綁定送出請求的伺服器 IP**
 *   2. 向 LINE Pay 申請成為合作商店，取得 Channel ID & Secret Key
 *      （會產生額外的交易處理費）
 *
 * 測試區可先跳過：申請 LINE Pay 時 Channel ID / Secret 填任意數字即可，
 * 但手機上仍需安裝真正的 LINE Pay App 並完成綁卡才付得了款。
 *
 * 留空的話收銀機的「掃碼收款」會顯示未開通，不影響刷卡。
 *
 * 詳細規格見 docs/payuni-linepay.md
 */
// 測試環境：https://sandbox-api.payuni.com.tw/api/linepay
// 正式環境：https://api.payuni.com.tw/api/linepay
define('PAYUNI_LINEPAY_URL', 'https://api.payuni.com.tw/api/linepay');

/*
 * ── 代理商專用：查詢合作商店狀態 ──────────────────────────────
 *
 * 用來查這個商店代號目前開通了哪些支付工具，以及分期各期數
 *（Inst3/6/9/12/18/24/30）分別是否可用。收銀 App 據此把沒開通的
 * 期數在畫面上以灰色停用，避免收銀員選了之後在客人面前才失敗。
 *
 * 上面的 PAYUNI_HASH_KEY / PAYUNI_HASH_IV **就是代理商的金鑰** ——
 * 這也正是三支交易 API 都要帶 IsPlatForm=1 的原因：那個參數就是在宣告
 * 「我現在以代理商身分串接」。所以這裡只需要補一個 AgentID。
 *
 * ⚠️ 代理商專區有自己的一份 IP 白名單，跟幕後授權那份是分開的。
 *    沒設定的話這支 API 會被擋，但交易 API 仍然正常 —— 兩者互不影響。
 *
 * 留空的話查詢功能會自動停用，收銀機退回「所有期數都可選」的行為，
 * 不會因為查不到狀態就不能收款。
 */
define('PAYUNI_AGENT_ID', '');  // 4 個英文字母全大寫

// 測試環境：https://sandbox-api.payuni.com.tw/api/agent/search_merchant_status
// 正式環境：https://api.payuni.com.tw/api/agent/search_merchant_status
define('PAYUNI_MERCHANT_STATUS_URL', 'https://api.payuni.com.tw/api/agent/search_merchant_status');

// 這個服務對外的網址，用來組出 NotifyURL
define('PUBLIC_BASE_URL', 'https://www.newpay.com.tw/newpaytrade');

// App 呼叫 authorize-direct.php 時要帶的 X-API-Key，防止外部直接呼叫
define('BACKEND_API_KEY', '');

// MySQL 連線設定（ISPConfig 控制台建立資料庫時會給你這些值）
define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');

// 管理介面登入密碼（只存雜湊，不存明碼）。產生方式（在主機上執行）：
//   php -r 'echo password_hash("你的密碼", PASSWORD_DEFAULT), PHP_EOL;'
// 把輸出的整串填在下面，**要用單引號包起來**。
//
// ⚠️ bcrypt 雜湊長得像 $2y$10$xxxx，裡面的 $ 很容易被工具吃掉：
//    - 用雙引號包（PHP 會把 $2y 當變數）→ 前綴消失
//    - 用 sed/perl 取代寫入（$2、$10 被當回溯參照）→ 前綴消失
//    正確做法：手動用編輯器貼上並用單引號包，貼完務必驗證：
//      php -r 'require "config.php"; var_dump(strlen(ADMIN_PASSWORD_HASH) === 60);'
//    長度必須是 60，且開頭是 $2y$ 才正確。
//
// 留空則管理介面無法登入。
define('ADMIN_PASSWORD_HASH', '');
