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

// 這個服務對外的網址，用來組出 NotifyURL
define('PUBLIC_BASE_URL', 'https://www.newpay.com.tw/newpaytrade');

// App 呼叫 authorize-direct.php 時要帶的 X-API-Key，防止外部直接呼叫
define('BACKEND_API_KEY', '');

// MySQL 連線設定（ISPConfig 控制台建立資料庫時會給你這些值）
define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');
