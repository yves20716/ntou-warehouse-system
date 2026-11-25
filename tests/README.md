# 回歸測試

請另建 `warehouse_qa` 資料庫，匯入 `database/01-schema.sql` 和 `database/02-seed.sql`，並使用只具該資料庫權限的帳號。不要連到平常展示或使用中的資料庫。

依主 README 設定資料庫環境變數，將 `DB_NAME` 設為 `warehouse_qa`、`DB_HOST` 設為 `127.0.0.1`，再啟動獨立的測試網站：

```sh
WAREHOUSE_SESSION_NAME=NTOU_WAREHOUSE_QA php -S 127.0.0.1:18767 -t public
```

在另一個載入相同 QA 資料庫環境變數的終端機，從專案根目錄執行：

```sh
python3 tests/backend_http.py http://127.0.0.1:18767
php tests/backend_db.php
php tests/dashboard_db.php
php tests/requests_db.php
python3 tests/requests_http.py http://127.0.0.1:18767
```

- `backend_http.py`：檢查登入權限、客戶資料隔離、表單重送、欄位驗證、錯誤後保留輸入及主管關係。使用種子資料中的示範帳號。
- `backend_db.php`：檢查寫入失敗是否回復庫存，以及兩個程序同時出庫、同時修改主管時的結果。
- `dashboard_db.php`：檢查倉庫精確篩選、相似名稱、數字名稱及客戶可見範圍。
- `requests_db.php`：檢查客戶收發申請的歸屬、取消、未受理原因、交易回復及兩個程序同時處理申請。
- `requests_http.py`：檢查申請表單的角色限制、CSRF、重送保護及客戶之間的資料隔離。

除了 `dashboard_db.php` 會回復整筆測試交易，其餘測試會在 QA 資料庫留下有識別碼的資料，方便查驗。PHP 測試會拒絕連線至名稱不是 `warehouse_qa` 的資料庫，HTTP 測試只接受本機的 18767 連接埠。

既有 QA 資料庫須先由具建表權限的管理帳號執行 `database/migrations/20261126-stock-requests.sql`；測試網站仍使用只具 QA 資料庫讀寫權限的帳號。

不需資料庫的表單回歸檢查：

```sh
php tests/form_tokens.php
```

這項檢查渲染 513 筆待處理申請，確認頁面前後的操作代碼仍有效，不會因產生太多表單而提早失效。
