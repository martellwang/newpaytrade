<?php
/**
 * 後台清單頁共用的分頁／排序／每頁筆數邏輯。
 *
 * 交易紀錄、經銷商、客戶管理、收銀機、店員、對帳報表 —— 這幾頁的清單
 * 版面完全一樣（序號可排序、分頁上下都有、每頁筆數在「系統設定」調整），
 * 抽成共用函式而不是每頁複製一份。之後要統一調整分頁樣式或行為，
 * 只要改這一個檔案。
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';

/**
 * 解析目前頁面該用的每頁筆數。
 *
 * 優先順序：網址參數 > 系統設定的預設值 > 寫死的保底值。
 * 兩者都必須落在白名單內，不然一律退回保底值 —— 不接受任意數字，
 * 避免 ?perPage=100000 這種請求把整個資料表撈出來。
 */
function admin_resolve_page_size($conn, $settingKey, $allowed = array(25, 50, 100), $fallback = 25) {
    $default = (int) db_get_setting($conn, $settingKey, $fallback);
    if (!in_array($default, $allowed, true)) {
        $default = $fallback;
    }
    $perPage = isset($_GET['perPage']) ? (int) $_GET['perPage'] : $default;
    if (!in_array($perPage, $allowed, true)) {
        $perPage = $default;
    }
    return $perPage;
}

/** 排序方向：desc（預設）或 asc，網址參數以外的值一律視為 desc */
function admin_resolve_sort() {
    return (isset($_GET['sort']) && $_GET['sort'] === 'asc') ? 'asc' : 'desc';
}

function admin_pager_url($qs, $page) {
    return '?' . $qs . '&page=' . $page;
}

/** 分頁列。清單上方與下方各放一份，呼叫同一份函式而不是各自刻一份。 */
function admin_render_pager($page, $totalPages, $qs) {
    if ($totalPages <= 1) {
        return;
    }
    echo '<div class="pager">';
    if ($page > 1) {
        echo '<a class="btn2" href="' . h(admin_pager_url($qs, $page - 1)) . '">上一頁</a>';
    }
    echo '<span class="muted">第 ' . $page . ' / ' . $totalPages . ' 頁</span>';
    if ($page < $totalPages) {
        echo '<a class="btn2" href="' . h(admin_pager_url($qs, $page + 1)) . '">下一頁</a>';
    }
    echo '</div>';
}

/**
 * 每頁筆數切換鈕，放在清單上方。
 *
 * @param array $baseParams 目前的篩選參數（不含 perPage／page），
 *                          切換筆數時要保留篩選條件、跳回第 1 頁。
 */
function admin_render_page_size_switcher($allowed, $current, $baseParams) {
    echo '<div style="display:flex;align-items:center;gap:8px">';
    echo '<span class="muted" style="font-size:13px">每頁筆數</span>';
    foreach ($allowed as $pp) {
        if ($pp === $current) {
            echo '<span class="btn2" style="background:#5a3d99;color:#fff;border-color:#5a3d99">'
                . $pp . '</span>';
        } else {
            $params = $baseParams;
            $params['perPage'] = $pp;
            echo '<a class="btn2" href="' . h(admin_pager_url(http_build_query($params), 1)) . '">'
                . $pp . '</a>';
        }
    }
    echo '</div>';
}

/**
 * 可排序表頭連結（目前只有「序號」欄位用得到）。點了就切換排序方向，
 * 並跳回第 1 頁 —— 換方向後沿用舊頁碼會對不上原本看到的那幾筆。
 */
function admin_sortable_header($label, $sort, $baseParams) {
    $params = $baseParams;
    $params['sort'] = ($sort === 'asc') ? 'desc' : 'asc';
    $url = h(admin_pager_url(http_build_query($params), 1));
    $arrow = ($sort === 'asc') ? '▲' : '▼';
    echo '<a href="' . $url . '" style="color:inherit;text-decoration:none">'
        . h($label) . ' ' . $arrow . '</a>';
}
