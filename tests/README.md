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
```

- `backend_http.py`：檢查登入權限、客戶資料隔離、表單重送、欄位驗證、錯誤後保留輸入及主管關係。使用種子資料中的示範帳號。
- `backend_db.php`：檢查寫入失敗是否回復庫存，以及兩個程序同時出庫、同時修改主管時的結果。
- `dashboard_db.php`：檢查倉庫精確篩選、相似名稱、數字名稱及客戶可見範圍。

前兩項會在 QA 資料庫留下有識別碼的測試資料，方便查驗；第三項會回復整筆測試交易。PHP 測試會拒絕連線至名稱不是 `warehouse_qa` 的資料庫，HTTP 測試只接受本機的 18767 連接埠。
