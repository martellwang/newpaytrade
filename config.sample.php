<?php
// 這個檔案放正式環境的密鑰與設定，不要提交進版控，也不要在這個檔案裡
// echo/var_dump 任何內容（就算被人直接用網址打開，也只會看到空白頁）。
// 部署到主機後，請直接在主機上編輯這個檔案填入正式數值，不要在本機
// 填好正式密鑰後再上傳。

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
