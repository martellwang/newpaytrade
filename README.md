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
| `status.php?merTradeNo=xxx` | GET | 查詢訂單狀態，需帶 `X-API-Key` header |
| `notify-direct.php` | POST | PAYUNi 背景通知接收（逾時情境的補救機制） |

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

`pending` 代表 60 秒內沒收到銀行回應，**不可當作失敗**，要等背景通知或
稍後查詢。

## 實作要點

以下幾點是實際串接時踩過、值得記錄的：

- **加密是 AES-256-GCM**（不是 CBC）。`EncryptInfo` 組法為
  `hex(base64(密文) + ":::" + base64(authTag))`，`HashInfo` 為
  `SHA256(HashKey + EncryptInfo + HashIV)` 直接字串相接後取大寫。
- **CVC 要能留空，最外層必須帶 `IsPlatForm=1`**（跟 `MerID` / `Version` /
  `EncryptInfo` / `HashInfo` 同層）。這個參數沒有寫在技術文件裡，是
  PAYUNi 客服另外提供的。沒帶的話，空 CVC 會被拒絕
  （`CREDIT02023 未有信用卡末三碼`），NFC 晶片交易情境會過不了。
- **幕後授權有 IP 白名單**，要在 PAYUNi 後台把伺服器對外 IP 加進去，
  否則收到 `CREDIT03010 不提供此IP幕後交易，<IP>`。換伺服器記得更新。
- **回應要看兩層**：最外層 `Status` 是請求層級結果（簽章、格式、IP 等），
  解密 `EncryptInfo` 後的 `TradeStatus` 才是真正的付款狀態
  （`1`=已付款 / `2`=付款失敗 / `3`=付款取消 / `8`=訂單暫緩確認），
  兩者都要檢查。
- **錯誤訊息在加密內容裡**：外層的 `Message` 常常是空的，實際失敗原因要
  解密 `EncryptInfo` 才看得到。
- **X-API-Key 用 `$_SERVER['HTTP_X_API_KEY']` 讀取**，不要用
  `getallheaders()['X-API-Key']`——回傳的 key 大小寫正規化方式不保證跟
  原始 header 名稱一致（Apache 上實測會變成 `X-Api-Key`）。

## 安全注意事項

- 卡號會經過這支 API，PCI-DSS 稽核範圍較高（通常為 SAQ D），建議上線前
  找資安顧問審查。
- 卡號與 CVV 不得寫入任何 log。
- `config.php`、`db.php`、`payuni_crypto.php`、`setup_db.php` 已由
  `.htaccess` 擋掉外部直接存取。
- 目前只有 API Key 驗證，**尚未實作 rate limiting**，建議加上以避免被
  當作測卡（carding）工具濫用。
