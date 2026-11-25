# 回歸測試

## Docker 安裝驗證

[GitHub Actions](https://github.com/yves20716/ntou-warehouse-system/actions/workflows/demo.yml) 會在乾淨的 Ubuntu 24.04 amd64 與 arm64 主機上，分別驗證本機示範版與正式部署版。也可從該頁按 **Run workflow** 重新執行。

檢查內容包括空資料卷建表、示範資料筆數、四個帳號登入、客戶申請入庫 10／倉儲確認、出庫 3 後餘量為 7、取消及未受理、客戶隔離、人資新增修改刪除，以及關閉再啟動後資料是否完整保留。正式部署版另外測 Apache、Caddy 本機 HTTPS 憑證、Secure Cookie、私人檔案無法存取、CLI 建帳號、限制資料庫權限、備份還原及舊版 migration。

`docker_verify.py` 負責啟動與檢查容器，僅允許在可拋棄的 GitHub Actions 主機上執行。`docker_smoke.py` 則透過實際 HTTP 表單測試操作；它會新增有識別碼的貨物、申請、批次與紀錄，**只在另外建立的測試環境使用**。

```sh
python3 tests/docker_smoke.py --base http://127.0.0.1:8080 --mode seeded --output .local/docker-seeded.json
# 關閉再啟動容器後，只讀取上一次的測試資料：
python3 tests/docker_smoke.py --base http://127.0.0.1:8080 --mode seeded --output .local/docker-seeded.json --verify-only
```

正式部署測試使用 `https://localhost` 和 Caddy 的本機 CA，驗證過程不關閉憑證檢查。這能測試容器的 HTTPS 設定；自己的公開網域仍須完成 DNS、80／443 連通與公開憑證簽發。

## 獨立 PHP／MySQL 測試

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
python3 tests/deployment_http.py http://127.0.0.1:18767
```

- `backend_http.py`：檢查登入權限、客戶資料隔離、表單重送、欄位驗證、錯誤後保留輸入及主管關係。使用種子資料中的示範帳號。
- `backend_db.php`：檢查寫入失敗是否回復庫存，以及兩個程序同時出庫、同時修改主管時的結果。
- `dashboard_db.php`：檢查倉庫精確篩選、相似名稱、數字名稱及客戶可見範圍。
- `requests_db.php`：檢查客戶收發申請的歸屬、取消、未受理原因、交易回復及兩個程序同時處理申請。
- `requests_http.py`：檢查申請表單的角色限制、CSRF、重送保護及客戶之間的資料隔離。
- `deployment_http.py`：檢查 CLI 建立三種登入帳號、拒絕重複或無效關聯，以及改密碼後舊登入狀態是否失效。使用獨立隨機密碼，在 QA 留下有識別碼的帳號；最後一個可選參數可指定 PHP 執行檔。

除了 `dashboard_db.php` 會回復整筆測試交易，其餘測試會在 QA 資料庫留下有識別碼的資料，方便查驗。PHP 測試會拒絕連線至名稱不是 `warehouse_qa` 的資料庫，HTTP 測試只接受本機的 18767 連接埠。

既有 QA 資料庫須先由具建表權限的管理帳號執行 `database/migrations/20261126-stock-requests.sql`；測試網站仍使用只具 QA 資料庫讀寫權限的帳號。

不需資料庫的表單回歸檢查：

```sh
php tests/form_tokens.php
```

這項檢查渲染 513 筆待處理申請，確認頁面前後的操作代碼仍有效，不會因產生太多表單而提早失效。
