# 在自己的主機部署

本頁以一台已安裝 Docker Engine 與 Compose 的 Linux 主機為例。需要一個由你管理的網域，例如 `warehouse.example.com`，以及可對外連線的主機。這是自行架設的設定與操作步驟；儲存庫本身沒有提供共用的線上服務。

正式環境使用 Caddy 接收 HTTPS，再轉給 Apache／PHP；MySQL 留在容器的內部網路。只對外開放 80、443，不開放 MySQL 的 3306。原本 `compose.yaml` 的 PHP 開發伺服器只供本機測試；[PHP 官方也不建議把它用在公開網路](https://www.php.net/manual/en/features.commandline.webserver.php)。

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
| `WAREHOUSE_DOMAIN` | 自己的完整網域，不含 `https://` 或路徑 |
| `WAREHOUSE_DB_PASSWORD` | 網站連接 MySQL 用的隨機密碼 |
| `WAREHOUSE_ROOT_PASSWORD` | MySQL 管理密碼，與上一項不同 |

可用密碼管理器產生密碼，或執行兩次 `openssl rand -hex 32`。這些是資料庫密碼，與網站登入密碼分開。不要把填好的設定檔貼到 Issue 或提交到 Git；`.env.production` 已被排除。

將網域的 DNS A 記錄指向主機的公開 IPv4；只有主機確實提供 IPv6 時才加 AAAA 記錄。防火牆放行 TCP 80、443，並確認沒有其他服務占用。Caddy 依這個網域申請及更新憑證，前提是 DNS 與連接埠可從外部連通。[Caddy HTTPS 說明](https://caddyserver.com/docs/automatic-https)

## 2. 啟動空資料庫

以下指令都從專案根目錄執行。`bin/production.sh` 固定使用正式環境設定、專案名稱與資料卷，避免和本機示範版混用。

```sh
./bin/production.sh config --quiet
./bin/production.sh up --build -d db web
./bin/production.sh ps
```

第一次啟動只匯入 `database/01-schema.sql`，建立 9 張表，不匯入 `02-seed.sql`，因此沒有公開的示範帳號。此時尚未啟動對外入口。MySQL 初始化只會作用於空資料卷；之後重啟不會覆蓋資料。[MySQL 映像檔的初始化規則](https://hub.docker.com/_/mysql)

把網站使用的 MySQL 帳號限制為資料讀寫；建表、migration 與備份改由管理帳號執行：

```sh
./bin/production.sh exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot warehouse' <<'SQL'
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'warehouse'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON warehouse.* TO 'warehouse'@'%';
SQL
```

## 3. 建立公司與人員資料

目前網站沒有管理客戶公司、倉庫及租約的頁面，這些基本資料由管理者匯入。貨物與員工的日常維護則在網站操作。

```sh
mkdir -p .local
cp deploy/business-setup.sql.example .local/business-setup.sql
```

編輯 `.local/business-setup.sql`，把客戶、倉庫、地址、員工姓名、儲區容量與時薪換成自己的資料。範例中的時薪 0 只是待填值。此檔只供空資料庫首次設定，執行一次即可；後續新增另一家客戶時另寫 INSERT，不要整份重複匯入。

```sh
./bin/production.sh exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot warehouse' < .local/business-setup.sql
```

指令最後會列出 `client_id`、`hr_employee_id`、`store_employee_id`。下一步請使用實際列出的編號。每個儲區必須指定一位客戶；只有該客戶的貨物能入庫到這個儲區。

## 4. 建立登入帳號

帳號與員工／客戶資料分開。管理者使用 `bin/account.php` 建立登入帳號；不提供公開註冊。帳號可使用英數字、`.`、`_`、`-`，最多 80 字。密碼至少 12 bytes、最多 72 bytes，不接受公開示範密碼。

在 **Bash** 終端機執行下列指令。密碼會隱藏輸入，透過標準輸入送給 PHP，不放在命令列參數或 SQL 裡。請將命令最後的編號換成上一個步驟的結果：

```bash
read -r -s -p '倉儲帳號的新密碼：' APP_PASSWORD; printf '\n'
printf '%s\n' "$APP_PASSWORD" | ./bin/production.sh exec -T web php bin/account.php create warehouse-user employee 2
unset APP_PASSWORD

read -r -s -p '客戶帳號的新密碼：' APP_PASSWORD; printf '\n'
printf '%s\n' "$APP_PASSWORD" | ./bin/production.sh exec -T web php bin/account.php create customer-user client 1
unset APP_PASSWORD

read -r -s -p '人資帳號的新密碼：' APP_PASSWORD; printf '\n'
printf '%s\n' "$APP_PASSWORD" | ./bin/production.sh exec -T web php bin/account.php create people-user hr 1
unset APP_PASSWORD
```

`employee` 與 `hr` 後面填員工編號；`client` 後面填客戶編號。網站帳密以雜湊保存。要重設密碼，改用 `php bin/account.php password warehouse-user`，沿用相同的隱藏輸入方式。修改成功後，該帳號的舊登入狀態會失效。

## 5. 啟用 HTTPS，確認操作

```sh
./bin/production.sh up -d proxy
./bin/production.sh logs --tail=50 proxy
```

開啟 `https://你的網域`，分別登入三種身分。先建立一個測試品項，由客戶申請入庫 10，再由倉儲確認；接著出庫 3，確認餘量為 7，並查看兩筆進出貨紀錄。完整步驟見[使用流程](USAGE.md)。

另外確認 HTTP 會轉到 HTTPS、網址列沒有憑證錯誤，未登入不能開啟工作頁；請求 `/app/bootstrap.php`、`/.env.production`、`/database/01-schema.sql` 應得到 404。Apache 只提供 `public/`，原始設定與資料庫檔案不在網站根目錄內。正式環境的 Session Cookie 固定帶 `Secure`，不依賴訪客傳入的代理標頭。

若要讓組員操作同一批資料，讓他們連到這個 HTTPS 網站，並各自建立帳號。各自在電腦啟動本機版時，資料彼此獨立；GitHub 不會同步 MySQL 的操作紀錄。

## 備份與還原

請把備份視為私人資料；其中包含公司、員工、庫存與密碼雜湊。以下檔案存到被 Git 排除的 `backups/`。另存一份到有存取權限控管的備份位置，並定期在另一個環境測試還原。

```sh
mkdir -p backups
chmod 700 backups
umask 077
./bin/production.sh exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --no-tablespaces --set-gtid-purged=OFF warehouse' > "backups/warehouse-$(date +%Y%m%d-%H%M%S).sql"
```

還原會覆蓋目標資料庫的同名表，先備份目前資料並暫停網站，再把下面檔名換成選定的備份。不要在仍有人操作的網站上直接還原。

```sh
./bin/production.sh stop proxy web
./bin/production.sh exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot warehouse' < backups/你選定的備份.sql
./bin/production.sh up -d web proxy
```

## 更新既有安裝

先備份，再取得程式。舊版只有 8 張資料表時，需要新增申請表；已有 `stock_requests` 的安裝不用重複執行這份 migration。

```sh
git pull --ff-only
./bin/production.sh stop proxy web
# 僅供尚無 stock_requests 的舊版安裝：
./bin/production.sh exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot warehouse' < database/migrations/20261126-stock-requests.sql
./bin/production.sh up --build -d
```

不要重匯 seed 或刪除資料卷來更新。`stop` 與一般的 `down` 都會保留命名資料卷；不要加入會移除資料卷的參數。更新 `.env.production` 的資料庫密碼也不會自動修改既有 MySQL 帳號，需先由管理者完成 MySQL 密碼變更，再同步網站設定。

## 其他 PHP 主機

也可以使用自己的 Apache 或 Nginx／PHP-FPM，加上 MySQL 8.0.16 以上。網站根目錄必須設為專案的 `public/`，PHP 要有 PDO MySQL，並設定 `DB_HOST`、`DB_PORT`、`DB_NAME`、`DB_USER`、`DB_PASSWORD`。完整 HTTPS 環境再設定 `WAREHOUSE_COOKIE_SECURE=1`。同樣先匯入 schema、建基本資料與帳號，正式環境不要匯入示範 seed。只支援靜態檔案的 GitHub Pages 無法執行這個 PHP／MySQL 網站。

## 驗證範圍

2026-11-26 已在 Ubuntu 24.04 amd64 與 arm64 上實際啟動這份設定，完成空資料庫建表、基本資料匯入、CLI 建帳號、資料庫降權、完整收發、重啟保留資料、備份還原及舊版 migration。Apache 與 Caddy 使用 `https://localhost` 測試，會驗證本機 CA 憑證、HTTP 轉址及 Secure Cookie，沒有關閉 TLS 檢查。[完整紀錄與執行連結](DOCKER-VERIFICATION.md)

這次沒有部署到公開網域。正式上線時，仍需在自己的主機確認 DNS、80／443 對外連通，以及公開憑證簽發。其他功能的回歸測試見[測試說明](../tests/README.md)。
