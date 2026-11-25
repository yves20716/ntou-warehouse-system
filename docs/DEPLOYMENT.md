# 部署到 Linux 主機

部署前須準備一台已安裝 Docker Engine 與 Compose、可對外連線的 Linux 主機，以及可管理 DNS 的網域，例如 `warehouse.example.com`。

正式環境由 Caddy 接收 HTTPS 請求，再轉交 Apache／PHP。對外開放 TCP 80、443；MySQL 的 3306 僅供容器內部連線。`compose.yaml` 使用的 PHP 開發伺服器限本機測試，詳見 [PHP 官方說明](https://www.php.net/manual/en/features.commandline.webserver.php)。

## 1. 取得程式與設定網域

```sh
git clone https://github.com/yves20716/ntou-warehouse-system.git
cd ntou-warehouse-system
cp .env.production.example .env.production
chmod 600 .env.production
```

用文字編輯器開啟 `.env.production`，填入三項設定：

| 設定 | 填入內容 |
| --- | --- |
| `WAREHOUSE_DOMAIN` | 完整網域名稱，不含 `https://` 或路徑 |
| `WAREHOUSE_DB_PASSWORD` | 網站連接 MySQL 用的隨機密碼 |
| `WAREHOUSE_ROOT_PASSWORD` | MySQL 管理密碼，與上一項不同 |

使用密碼管理器，或執行兩次 `openssl rand -hex 32` 產生資料庫密碼。資料庫與網站登入密碼分開設定。`.env.production` 已由 Git 排除，填妥後應保留在主機上，禁止上傳至 Issue 或 Git。

將網域的 DNS A 記錄指向主機的公開 IPv4；只有主機確實提供 IPv6 時才加 AAAA 記錄。防火牆放行 TCP 80、443，並確認沒有其他服務占用。Caddy 依這個網域申請及更新憑證，前提是 DNS 與連接埠可從外部連通。[Caddy HTTPS 說明](https://caddyserver.com/docs/automatic-https)

## 2. 啟動空資料庫

以下指令都從專案根目錄執行。`bin/production.sh` 固定使用正式環境設定、專案名稱與資料卷，避免和本機示範版混用。

```sh
./bin/production.sh config --quiet
./bin/production.sh up --build -d db web
./bin/production.sh ps
```

首次啟動會從 `database/01-schema.sql` 建立 11 張空表。正式環境略過 `02-seed.sql`，管理員帳號由主機管理者建立；一般帳號可在網站建立，再由本人透過一次性連結啟用。HTTPS 入口於步驟 5 啟用。MySQL 只在空資料卷執行初始化，重啟會保留既有資料。[MySQL 映像檔的初始化規則](https://hub.docker.com/_/mysql)

把網站使用的 MySQL 帳號限制為資料讀寫；建表、migration 與備份改由管理帳號執行：

```sh
./bin/production.sh exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot warehouse' <<'SQL'
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'warehouse'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON warehouse.* TO 'warehouse'@'%';
SQL
```

## 3. 建立公司與人員資料

客戶公司、倉庫及租約資料由管理者匯入；貨物與員工的日常維護可在網站操作。

```sh
mkdir -p .local
cp deploy/business-setup.sql.example .local/business-setup.sql
```

編輯 `.local/business-setup.sql`，填入客戶、倉庫、地址、員工姓名與儲區容量。月薪預設為 NULL，可於人員資料補填。此檔限空資料庫首次匯入；後續新增客戶時，另寫 INSERT，避免重複匯入整份檔案。

```sh
./bin/production.sh exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot warehouse' < .local/business-setup.sql
```

指令最後會列出 `client_id`、`admin_employee_id`、`store_employee_id`。下一步請使用實際列出的編號。每個儲區必須指定一位客戶；只有該客戶的貨物能入庫到這個儲區。

## 4. 建立第一個管理員

主機管理者透過 `bin/account.php` 建立管理員，關聯既有員工編號。帳號可使用英數字、`.`、`_`、`-`，最多 80 字。密碼至少 12 個字元、最多 72 個 UTF-8 bytes，禁止使用公開示範密碼。

在 **Bash** 終端機執行下列指令，將最後的 `1` 換成上一步列出的 `admin_employee_id`。密碼以隱藏輸入讀取，再經標準輸入傳給 PHP：

```bash
read -r -s -p '管理員帳號的新密碼：' APP_PASSWORD; printf '\n'
printf '%s\n' "$APP_PASSWORD" | ./bin/production.sh exec -T web php bin/account.php create people-user admin 1
unset APP_PASSWORD
```

網站可建立 `employee`、`client` 帳號，並管理一般帳號的啟停與重設需求。`admin` 帳號及其人員資料由主機管理者維護。

## 5. 啟用 HTTPS，確認操作

```sh
./bin/production.sh up -d proxy
./bin/production.sh logs --tail=50 proxy
```

開啟 `https://你的網域`，用剛建立的管理員登入「帳號管理」。分別建立一個倉儲員工帳號及客戶帳號，選取對應的員工／客戶。新帳號會顯示「待啟用」。

主機管理者確認帳號與接收者身分後，執行下列指令，將帳號及網域換成實際設定：

```sh
./bin/production.sh exec -T web php bin/account.php invite warehouse-user --base-url https://warehouse.example.com
./bin/production.sh exec -T web php bin/account.php invite customer-user --base-url https://warehouse.example.com
```

指令會輸出一次性連結，有效 30 分鐘。透過私人管道交給帳號本人，由本人開啟連結並設定密碼。連結使用一次後失效；重發會使舊連結失效。請勿將連結貼到 Issue、公開文件或截圖。

啟用後，分別登入倉儲與客戶帳號。由客戶建立測試品項、申請入庫 10，再由倉儲確認；接著出庫 3，核對餘量為 7，並查看兩筆進出貨紀錄。完整步驟見[使用流程](USAGE.md)。

另外確認 HTTP 會轉到 HTTPS、網址列沒有憑證錯誤，未登入不能開啟工作頁；請求 `/app/bootstrap.php`、`/.env.production`、`/database/01-schema.sql` 應得到 404。Apache 只提供 `public/`，原始設定與資料庫檔案不在網站根目錄內。正式環境的 Session Cookie 固定帶 `Secure`，不依賴訪客傳入的代理標頭。

多人共同作業時，為每位使用者建立帳號，並連線至同一個 HTTPS 網站。獨立架設的本機版各自保存 MySQL 資料，彼此不會同步。

## 帳號停用與密碼重設

網站管理員可停用一般帳號；停用會使既有登入失效。員工離職時，其所屬帳號一併停用。復職後須在帳號管理另行恢復，收發與管理紀錄持續保留。

需要重設密碼時，網站管理員在帳號明細按「申請重設密碼」。主機管理者核對本人後，以相同的 `invite` 指令產生連結。已啟用帳號使用重設流程；待啟用帳號使用首次啟用流程。停用帳號無法產生連結。

重設需求或新連結發行後，舊登入失效，由本人完成密碼設定後再登入。網站管理頁不提供密碼或一次性連結，也不能建立其他管理員、變更既有帳號角色、改綁公司或代登入。

主機管理者需要復原管理員密碼時，可使用 `php bin/account.php password people-user`，沿用前述隱藏輸入方式。此指令具有主機管理權限，密碼變更後會撤銷既有登入與未用連結。

## 備份與還原

備份包含公司、員工、庫存、密碼雜湊、憑證雜湊與管理紀錄，須限制存取權限。下列指令將檔案存至已由 Git 排除的 `backups/`。請另存一份至有權限控管的備份位置，並定期在獨立環境測試還原。

```sh
mkdir -p backups
chmod 700 backups
umask 077
./bin/production.sh exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --no-tablespaces --set-gtid-purged=OFF warehouse' > "backups/warehouse-$(date +%Y%m%d-%H%M%S).sql"
```

還原會覆蓋目標資料庫的同名表。先備份目前資料、暫停網站，確認無人操作後，再將下列檔名換成選定的備份並執行：

```sh
./bin/production.sh stop proxy web
./bin/production.sh exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot warehouse' < backups/你選定的備份.sql
./bin/production.sh up -d web proxy
```

## 更新既有安裝

先備份，再取得程式。只有 8 張表的舊版須先新增收發申請表；接著執行帳號管理 migration。請使用具建表及 CREATE ROUTINE 權限的資料庫管理帳號，網站帳號仍維持資料讀寫權限。

```sh
git pull --ff-only
./bin/production.sh stop proxy web
# 僅供尚無 stock_requests 的舊版安裝：
./bin/production.sh exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot warehouse' < database/migrations/20261126-stock-requests.sql
./bin/production.sh exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot warehouse' < database/migrations/20261126-account-management.sql
./bin/production.sh up --build -d
```

帳號管理 migration 將 `hr` 角色改為 `admin`，保留原帳號名稱、密碼及業務資料；執行完成後移除臨時 procedure。既有員工的月薪先留空，原時薪保留供核對，不自動換算。新版 seed 的月薪為虛構示範數值，升級時不會套用到既有人員。

更新時須保留資料卷，禁止重新匯入 seed。`stop` 與一般的 `down` 都會保留命名資料卷，執行時請省略移除資料卷的參數。變更資料庫密碼時，先由管理者修改既有 MySQL 帳號，再同步更新 `.env.production`。

## 其他 PHP 主機

手動部署可使用 Apache 或 Nginx／PHP-FPM，搭配 MySQL 8.0.16 以上。網站根目錄必須設為專案的 `public/`，PHP 須啟用 PDO MySQL，並設定 `DB_HOST`、`DB_PORT`、`DB_NAME`、`DB_USER`、`DB_PASSWORD`。完成 HTTPS 設定後，將 `WAREHOUSE_COOKIE_SECURE` 設為 `1`。依序匯入 schema、建立基本資料與帳號；正式環境禁止匯入示範 seed。GitHub Pages 僅支援靜態網站，無法執行此系統所需的 PHP 與 MySQL。

## 驗證範圍

2026-11-26 的 Ubuntu 24.04 amd64 與 arm64 測試涵蓋空資料庫建表、基本資料匯入、CLI 建帳號、一次性啟用及重設、帳號啟停與登入失效、限制資料庫權限、完整收發、重啟後資料保留、備份還原及舊版 migration。Apache 與 Caddy 以 `https://localhost` 驗證本機 CA 憑證、HTTP 轉址及 Secure Cookie，全程啟用 TLS 檢查。[測試紀錄](DOCKER-VERIFICATION.md)

公開網域部署尚未驗證。上線前須確認主機的 DNS、TCP 80／443 對外連線及公開憑證簽發。功能回歸測試見[測試說明](../tests/README.md)。
