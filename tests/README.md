# 回歸測試

## Docker 安裝驗證

[GitHub Actions](https://github.com/yves20716/ntou-warehouse-system/actions/workflows/demo.yml) 會在乾淨的 Ubuntu 24.04 amd64 與 arm64 主機上，分別驗證本機示範版與正式部署版。也可從該頁按 **Run workflow** 重新執行。

檢查內容包括空資料卷建表、示範資料筆數、四個帳號登入、客戶申請入庫 10／倉儲確認、出庫 3 後餘量為 7、取消及未受理、客戶隔離、人員資料新增修改刪除，以及關閉再啟動後資料是否完整保留。帳號檢查涵蓋待啟用、停用、密碼重設、憑證單次使用與角色限制。正式部署版另外測 Apache、Caddy 本機 HTTPS 憑證、Secure Cookie、私人檔案無法存取、CLI 建帳號、限制資料庫權限、備份還原及舊版 migration。

`docker_verify.py` 負責啟動與檢查容器，僅允許在測試結束後銷毀的 GitHub Actions 主機上執行。`docker_smoke.py` 透過 HTTP 表單測試操作，會新增有識別碼的貨物、申請、批次與紀錄，須使用獨立測試環境。

```sh
mkdir -p .local
python3 tests/docker_smoke.py --base http://127.0.0.1:8080 --mode seeded --output .local/docker-seeded.json
# 關閉再啟動容器後，只讀取上一次的測試資料：
python3 tests/docker_smoke.py --base http://127.0.0.1:8080 --mode seeded --output .local/docker-seeded.json --verify-only
```

HTTPS 測試使用 `https://localhost` 與 Caddy 本機 CA，全程啟用憑證檢查。公開網域部署須另外確認 DNS、TCP 80／443 對外連線及公開憑證簽發。

## 獨立 PHP／MySQL 測試

建立獨立的 `warehouse_qa` 資料庫，匯入 `database/01-schema.sql` 與 `database/02-seed.sql`，並使用僅具該資料庫權限的帳號。測試資料庫須與展示及正式環境分開。

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
python3 tests/account_http.py http://127.0.0.1:18767
```

- `backend_http.py`：檢查登入權限、客戶資料隔離、表單重送、欄位驗證、錯誤後保留輸入及主管關係。使用種子資料中的示範帳號。
- `backend_db.php`：檢查寫入失敗是否回復庫存，以及兩個程序同時出庫、同時修改主管時的結果。
- `dashboard_db.php`：檢查倉庫精確篩選、相似名稱、數字名稱及客戶可見範圍。
- `requests_db.php`：檢查客戶收發申請的歸屬、取消、未受理原因、交易回復及兩個程序同時處理申請。
- `requests_http.py`：檢查申請表單的角色限制、CSRF、重送保護及客戶之間的資料隔離。
- `deployment_http.py`：檢查 CLI 建立三種登入帳號、拒絕重複或無效關聯，以及改密碼後舊登入狀態是否失效。使用獨立隨機密碼，在 QA 留下有識別碼的帳號；最後一個可選參數可指定 PHP 執行檔。

- `account_http.py`：檢查一般帳號建立與啟用、禁止建立管理員或更換既有身分、憑證重發／過期／單次使用，以及停用、離職、要求重設後的登入失效。也核對管理紀錄保留操作人與對象，且沒有密碼或完整憑證。最後一個可選參數可指定 PHP 執行檔。

`dashboard_db.php` 會回復整筆測試交易，其餘測試會在 QA 資料庫保留有識別碼的資料。PHP 測試僅接受名稱為 `warehouse_qa` 的資料庫，HTTP 測試僅接受本機的 18767 連接埠。

既有 QA 資料庫須先由具建表及 CREATE ROUTINE 權限的管理帳號依序套用 migration：尚無申請表時執行 `database/migrations/20261126-stock-requests.sql`，再執行 `database/migrations/20261126-account-management.sql`。測試網站仍使用只具 QA 資料庫讀寫權限的帳號。

表單代碼檢查可直接執行，無須連線至資料庫：

```sh
php tests/form_tokens.php
```

`form_tokens.php` 會產生含 513 筆待處理申請的頁面，確認第一筆至最後一筆表單的操作代碼皆有效。
