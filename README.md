# newpaytrade

接收金流交易的 API 及交易管理查詢。PAYUNi 統一金流「信用卡幕後授權
（CREDIT）」串接，供 Android POS App 呼叫。

傳統程序式 PHP 寫法（不使用物件導向），相依套件為零，只需要 PHP 內建的
`openssl`、`curl`、`mysqli` 擴充。

## 環境需求

- PHP 8.0+（需 `openssl`、`curl`、`mysqli` 擴充）
- MySQL / MariaDB
- Apache（需支援 `.htaccess`，`AllowOverride All`）

## 安裝

```bash
cp config.sample.php config.php
# 編輯 config.php 填入 PAYUNi 密鑰、API Key、資料庫連線資訊
php -f setup_db.php   # 建立 orders 資料表
```

`config.php` 含密鑰，已由 `.gitignore` 排除，**不要提交進版控**。

## API 端點

| 端點 | 方法 | 說明 |
|---|---|---|
| `authorize-direct.php` | POST | 信用卡幕後授權，需帶 `X-API-Key` header |
| `refund.php` | POST | 交易請退款，需帶 `X-API-Key` header |
| `query.php?merTradeNo=xxx` | GET/POST | 向 PAYUNi 查詢交易真實結果並補正本地狀態 |
| `status.php?merTradeNo=xxx` | GET | 查詢訂單狀態與退款明細，需帶 `X-API-Key` |
| `notify-direct.php` | POST | PAYUNi 背景通知接收（逾時情境的補救機制） |

`authorize-direct.php` 與 `refund.php` 受速率限制保護，超過門檻回
HTTP `429`（見下方「速率限制」）。

## 管理介面

`admin/`，給人看的網頁介面（跟給機器用的 API 分開）：

| 頁面 | 說明 |
|---|---|
| `admin/index.php` | 交易紀錄查詢：日期區間、狀態、訂單編號／交易序號／卡號末四碼 |
| `admin/pending.php` | 進行中交易：一次列出所有尚未定案（`pending`）的交易，不限日期，最舊在前，方便集中補正 |
| `admin/report.php` | 對帳報表：區間總計 + 逐日明細（成功／退款／淨額） |
| `admin/detail.php` | 單筆明細，含退款紀錄 |
| `admin/export.php` | 匯出 CSV（含 UTF-8 BOM，Excel 開啟不亂碼） |

**認證**：獨立的密碼登入（session），不是沿用 `X-API-Key`——那把金鑰是給
App 用的機器憑證，放進網頁表單等於公開。設定方式見 `config.sample.php`
的 `ADMIN_PASSWORD_HASH`。

安全措施：密碼以 `password_hash` 儲存、session cookie 設
`httponly`/`secure`/`samesite=Strict`、登入成功後 `session_regenerate_id`
防 session fixation、表單有 CSRF token、30 分鐘閒置自動登出、登入失敗
15 分鐘內 5 次就鎖（沿用既有的速率限制機制）。

**對帳邏輯**：退款以「退款實際發生日」歸屬，而非原訂單日期——PAYUNi 的
撥款也是這樣結算，兩邊才對得起來。報表會特別標示 `pending` 交易，那是
對帳差異最常見的來源（狀態未定，可能已扣款），要先用 `query.php` 補正。

### authorize-direct.php

請求（`Content-Type: application/json`）：

```json
{
  "merTradeNo": "訂單編號，25字內，[A-Za-z0-9_-]，10分鐘內不可重複",
  "amount": 100,
  "description": "商品說明",
  "cardNumber": "卡號",
  "expiryMonth": "MM",
  "expiryYear": "YY",
  "cvv": "末三碼，NFC 讀卡情境可留空字串"
}
```

回應：

```json
{ "status": "success", "payuniTradeNo": "...", "authCode": "...", "card4No": "1234" }
{ "status": "failed",  "message": "失敗原因" }
{ "status": "pending", "message": "交易處理中", "merTradeNo": "..." }
```

### refund.php

請求：

```json
{
  "merTradeNo": "要退款的訂單編號",
  "amount": 50,
  "closeType": 2
}
```

- `amount` 可省略，省略時為**全額**退款
- `closeType` 可省略，預設 `2`（退款）。`1`=請款、`-1`=取消請款、
  `-2`=取消退款。平台預設自動請款，一般不需自己發動 `1`

回應：

```json
{
  "status": "success", "message": "...", "merTradeNo": "...",
  "payuniTradeNo": "...", "closeType": 2,
  "amount": 50, "totalRefunded": 50, "orderAmount": 100
}
```

官方文件載明的業務限制（程式無法自行判斷，呼叫端要注意）：

- 一次付清、國外卡：可全額退款，也可**部分**退款
- 分期付款、銀聯卡：**僅能全額**退款
- 請款期限：授權成功後 3 天內（平台預設為自動請款）
- **退款期限：請款完成後 180 天內**

`pending` 代表 60 秒內沒收到銀行回應，**不可當作失敗**，要等背景通知或
用 `query.php` 查詢。

### query.php

用 `?merTradeNo=xxx` 或 `?tradeNo=xxx`（PAYUNi 的 UNi 序號）擇一查詢。

主要用途是處理 `pending`：授權逾時後我們並不知道銀行到底有沒有扣款，
這支去 PAYUNi 問出真實結果，並**自動補正本地訂單狀態**（只在本地狀態與
查詢結果不一致時才更新）。

```json
{
  "status": "success",
  "merTradeNo": "...", "payuniTradeNo": "...",
  "tradeStatus": "1", "tradeStatusText": "已付款",
  "localStatus": "success", "localStatusUpdated": true,
  "dataSource": "A", "dataComplete": true,
  "authCode": "...", "card4No": "1234",
  "closeStatus": "2", "closeAmt": "100",
  "refundStatus": null, "refundAmt": null, "remainAmt": "100"
}
```

- `tradeStatus`：`0`=取號成功 `9`=未付款 `1`=已付款 `2`=付款失敗
  `3`=付款取消 `4`=交易逾期 `8`=訂單待確認
- `dataComplete: false`（`DataSource=B`）代表 PAYUNi 端還在處理、資料不
  完整，**這時不會拿來補正本地狀態**，官方建議 10 分鐘後再查一次
- `remainAmt` 是 PAYUNi 端的剩餘可退款金額，**比本地累加的數字權威**，
  退款前建議以這個為準

## 實作要點

以下幾點是實際串接時踩過、值得記錄的：

- **加密是 AES-256-GCM**（不是 CBC）。`EncryptInfo` 組法為
  `hex(base64(密文) + ":::" + base64(authTag))`，`HashInfo` 為
  `SHA256(HashKey + EncryptInfo + HashIV)` 直接字串相接後取大寫。
- **`IsPlatForm=1` 是必要的**（跟 `MerID` / `Version` / `EncryptInfo` /
  `HashInfo` 同層）。這個參數沒有寫在技術文件裡，是 PAYUNi 客服另外提供
  的，平台／代理商類型的商店帳號兩支 API 都要帶：
  - 授權（`/api/credit`）沒帶的話，空 CVC 會被拒絕
    （`CREDIT02023 未有信用卡末三碼`），NFC 晶片交易情境會過不了。
  - 退款（`/api/trade/close`）沒帶的話會收到 `DEF01007 Hash比對不符合`。
    **這個錯誤訊息會誤導人去查簽章公式**，實際上公式是對的，是平台端用
    錯的金鑰情境驗證。
- **各支 API 的 `Version` 都不一樣**：幕後授權 `1.3`、交易請退款 `1.0`、
  交易查詢 `2.0`。照抄會出錯。
- **交易查詢的結果包在 `Result` 陣列裡**，不是放在解密後的最外層（因為
  同一個端點也支援多筆查詢）。單筆查詢要取 `Result[0]`，直接讀最外層會
  拿到一片空值而且不會報錯。
- **幕後授權有 IP 白名單**，要在 PAYUNi 後台把伺服器對外 IP 加進去，
  否則收到 `CREDIT03010 不提供此IP幕後交易，<IP>`。換伺服器記得更新。
- **回應要看兩層**：最外層 `Status` 是請求層級結果（簽章、格式、IP 等），
  解密 `EncryptInfo` 後的 `TradeStatus` 才是真正的付款狀態
  （`1`=已付款 / `2`=付款失敗 / `3`=付款取消 / `8`=訂單暫緩確認），
  兩者都要檢查。
- **錯誤訊息在加密內容裡**：外層的 `Message` 常常是空的，實際失敗原因要
  解密 `EncryptInfo` 才看得到。但有些情境（例如 `DEF01007`）連
  `EncryptInfo` 都解不開，只剩下代碼——所以 `payuni_error_codes.php`
  收錄了官方文件的完整錯誤代碼對照表當退路，訊息解析優先序為：
  解密後的 `Message` → 外層 `Message` → 對照表 → 原始代碼。
- **授權失敗時看 `ResCodeMsg` 而不是 `Message`**：`Message` 只會給籠統的
  「授權失敗」，`ResCodeMsg` 才有銀行給的具體原因（例如
  「授權失敗_無此發卡行(No such issuer)」）。
- **X-API-Key 用 `$_SERVER['HTTP_X_API_KEY']` 讀取**，不要用
  `getallheaders()['X-API-Key']`——回傳的 key 大小寫正規化方式不保證跟
  原始 header 名稱一致（Apache 上實測會變成 `X-Api-Key`）。

## 安全注意事項

- 卡號會經過這支 API，PCI-DSS 稽核範圍較高（通常為 SAQ D），建議上線前
  找資安顧問審查。
- 卡號與 CVV 不得寫入任何 log。
- `.htaccess` 採**白名單**制：只放行四個真正的 API 端點，其餘一律擋掉。
  用白名單而非黑名單，是因為編輯器會產生 `config.php~`、`.bak` 這類備份
  檔（含密鑰），黑名單很容易漏掉而導致密鑰從網頁直接被讀走。
  ⚠️ 在主機上編輯 `config.php` 後，記得順手刪掉編輯器留下的備份檔。
## 速率限制（rate limiting）

`rate_limit.php`。存在的理由：`BACKEND_API_KEY` 放在 APK 裡，反組譯就能
取得；一旦外流，攻擊者可以無限次呼叫 `authorize-direct.php`，把大量外流
卡號丟進來測哪些還有效（回 `success` 就是有效卡）。每筆都用我們的商店
代號送出，後果是手續費、爭議款，最壞是被 PAYUNi 風控停權。

三道防線（門檻定義在 `rate_limit.php` 開頭，可依實際營運調整）：

| 防線 | 預設門檻 | 目的 |
|---|---|---|
| 單一 IP 請求頻率 | 20 次 / 分鐘 | 擋高速自動化呼叫 |
| 單一 IP **失敗**次數 | 10 次 / 10 分鐘 | 最有效——測卡失敗率極高，換卡也躲不掉 |
| 同一張卡嘗試次數 | 5 次 / 小時 | 同卡反覆試也是測卡特徵 |

實作要點：

- **檢查點在送去 PAYUNi 之前**，被擋的請求不會消耗商店額度、不產生手續費，
  也不會被 PAYUNi 風控記為異常。
- **卡號絕不落地**：卡片維度用 `HMAC-SHA256(卡號, HashKey)` 當識別碼。
  刻意用 HMAC 而非單純 SHA256——卡號空間小（BIN 前六碼可枚舉），單純雜湊
  可被暴力反推，加上只有我們知道的金鑰才安全。
- **不信任 `X-Forwarded-For`**：那個 header 由呼叫端自己送，攻擊者每次帶
  不同假值就能完全繞過限制。若日後前面架了 CDN／反向代理，必須改成只信任
  已知代理來源送來的 XFF。
- **計數只算 `attempt` 事件**：一次請求記 1 筆 attempt，失敗再記 1 筆
  failed。若兩者混算，一筆失敗請求會被算成兩次，門檻等於腰斬（實測踩過）。
- **資料庫故障時放行**：這是可用性與安全性的取捨——DB 故障是短暫的，硬擋
  會讓門市完全無法收款。放行時會寫 error_log。
- 舊事件以約 1% 機率順帶清理（保留 7 天），不需另設排程。

回應訊息刻意模糊（不說是哪一道防線、剩幾次），避免攻擊者反推門檻調整節奏；
詳細原因只寫進 error_log。
