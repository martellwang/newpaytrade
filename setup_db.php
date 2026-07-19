<?php
/**
 * 一次性資料庫初始化腳本，部署後手動跑一次就好：
 *   php -f setup_db.php
 * 不要放進一般的 HTTP 請求流程裡（.htaccess 已經擋掉外部直接存取）。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rate_limit.php';

$conn = db_connect();
db_create_orders_table_if_not_exists($conn);
echo "orders 資料表已建立（或本來就存在）\n";
db_create_refunds_table_if_not_exists($conn);
echo "refunds 資料表已建立（或本來就存在）\n";
rl_create_table_if_not_exists($conn);
echo "rate_limit_events 資料表已建立（或本來就存在）\n";
