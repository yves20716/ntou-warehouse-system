# NEXUS WMS 公共倉儲管理系統

海大資料庫系統期末專題。一間倉儲公司替不同客戶保管貨物。倉儲員工登記每批貨存放的儲區及進出數量，客戶可以替自己的貨物建檔、提出入庫或出庫申請，並查看處理進度；倉儲人員確認實際收發後才更新庫存。人資使用獨立頁面維護員工資料。

| 想做的事 | 從這裡開始 |
| --- | --- |
| 在自己的電腦試用 | 下方「在本機啟動」 |
| 了解客戶、倉儲與人資怎麼操作 | [完整使用流程](docs/USAGE.md) |
| 在自己的網域提供共用服務 | [網路部署、建帳號與備份](docs/DEPLOYMENT.md) |
| 檢查程式與資料庫 | [回歸測試](tests/README.md) |

這是可自行架設的 PHP／MySQL 專案。各人的本機版會建立獨立資料庫；若要共同操作同一批庫存，請部署共用的 HTTPS 網站。GitHub 提供程式與初始化資料，不是即時資料庫服務。

## 在本機啟動

先準備 Git、Docker 與 Docker Compose，確認 Docker 已啟動。macOS、Windows 可使用 Docker Desktop，Linux 可使用 Docker Engine 加 Compose。取得專案後執行：

```sh
git clone https://github.com/yves20716/ntou-warehouse-system.git
cd ntou-warehouse-system
docker compose up --build -d
docker compose ps
```

在執行上述指令的電腦開啟 `http://localhost:8080`。這是每位使用者自己的本機入口，不是作者電腦的網址。第一次啟動時，MySQL 會在新的資料卷建表並載入示範資料。

若 8080 已被占用，在專案根目錄的 `.env` 寫入 `WAREHOUSE_PORT=8081`，再執行相同指令，網址改成 `http://localhost:8081`。`.env` 不會提交到 Git。執行 `docker compose stop` 可關閉服務，`docker compose up -d` 可再次開啟，原資料會保留。

四組示範帳號的密碼都是 `WarehouseDemo!26`。資料庫裡的人名、公司與貨物僅供本機展示。

| 帳號 | 身分 | 可以操作的內容 |
| --- | --- | --- |
| `client` | 海岬選物 | 建立／編輯自己的貨物、提出或取消收發申請、查看庫存與紀錄 |
| `client2` | 山線器材 | 另一家客戶的相同功能，用來比較兩個客戶的可見範圍 |
| `employee` | 倉儲員工 | 管理貨物、處理客戶申請、登記實際收發 |
| `hr` | 人資 | 新增、查詢、修改及刪除員工，設定職務、薪資與主管 |

## 走一遍進出貨流程

1. 用 `client` 登入「貨物品項」，建立「展示用收納盒」，單位填「箱」，再從該列點「申請入庫」，數量填 10。此時只有申請，還沒有庫存。
2. 改用 `employee` 登入，工作台會列出待處理申請。點「處理申請」，儲區選「基隆港倉／A-01」，確認入庫 10 箱。
3. 改用 `client`，在「庫存批次」找到這批貨，點「申請出庫」，填 3。再由 `employee` 確認出庫，庫存剩 7 箱。
4. 點貨物名稱或「明細」，可在側邊面板查看這個批次的入庫 10 箱與出庫 3 箱。`client2` 查不到這批海岬選物的貨物。
5. 客戶可取消尚未處理的申請；倉儲人員無法受理時可填原因，客戶能在明細看到回覆。

出庫申請不會預扣或保留庫存；實際完成時會再次檢查餘量。每筆申請只能處理一次。倉儲員工仍可直接登記現場收發。新增、編輯、申請與批次明細以側邊面板開啟，關閉後保留原清單位置；未送出的修改會先提示。

每個儲區由一位客戶承租。出庫數量必須小於或等於該批餘量。庫存更新與進出貨紀錄在同一筆 MySQL 交易中寫入；已有批次或入庫申請的貨物會保留，方便日後回查。儲區容量是參考資料，貨物依「箱」、「台」等各自的單位記錄。

## 已有 PHP 與 MySQL 的環境

使用 PHP 8.1 以上的 CLI、PDO MySQL 與 MySQL 8.0.16 以上。先建立空的 `warehouse` 資料庫，再以可建表的帳號依序匯入兩份 SQL。

```sh
mysql -u root -p warehouse < database/01-schema.sql
mysql -u root -p warehouse < database/02-seed.sql
```

另為網站建立可對該資料庫執行 SELECT、INSERT、UPDATE、DELETE 的專用帳號。把以下設定中的帳號、密碼換成你建立的值，再從專案根目錄啟動網站。

```sh
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=warehouse
export DB_USER=warehouse DB_PASSWORD='你的本機資料庫密碼'
php -S 127.0.0.1:8080 -t public
```

## 檔案與資料表

| 位置 | 用途 |
| --- | --- |
| `public/index.php` | 登入 |
| `public/client.php` | 客戶貨物建檔、申請與查詢 |
| `public/employee.php` | 貨物、庫存與進出貨 |
| `public/hr.php` | 人事管理 |
| `public/actions.php` | 接收表單、檢查登入身分、執行對應操作 |
| `app/` | 資料庫連線、工作階段、查詢和更新邏輯 |
| `database/01-schema.sql` | 資料表、主外鍵及欄位限制 |
| `database/02-seed.sql` | 空資料庫第一次啟動用的示範資料 |

一座倉庫（`warehouses`）可分成多個儲區（`sections`）。客戶（`clients`）承租儲區並擁有貨物（`goods`）。每次入庫建立一個批次（`inventory`），每筆異動（`movements`）連回批次及承辦員工（`employees`）。登入帳號（`users`）連回對應的客戶或員工，以決定能查閱的資料與可執行的操作。


收發申請使用 `stock_requests`，共 9 張資料表、14 個外鍵。入庫申請連到貨物；出庫申請連到指定批次。完成時連到實際產生的進出貨紀錄與承辦員工。取消及未受理申請也會保留。

## 更新已有的資料庫

若先前已啟動過舊版，保留原資料卷，套用這次新增申請表的 migration；不要重新匯入 seed。

```sh
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -u"$MYSQL_USER" "$MYSQL_DATABASE"' < database/migrations/20261126-stock-requests.sql
```

使用自行安裝的 MySQL 時：

```sh
mysql -u root -p warehouse < database/migrations/20261126-stock-requests.sql
```

首次啟動的空資料庫已包含這張表。各組員的本機資料庫彼此獨立；下載程式不會取得其他人的操作紀錄。GitHub 僅包含程式、建表檔與虛構的示範資料，不包含本機帳密設定或含組員個資的報告。

## 啟動時遇到問題

- 網頁連不上：先執行 `docker compose ps` 確認 `db` 已 healthy、`web` 已啟動，再用 `docker compose logs --tail=50 web db` 查看錯誤。首次下載映像與初始化需要一些時間。
- 登入失敗：公開 seed 的密碼是 `WarehouseDemo!26`；已有資料卷會沿用舊資料，不會在更新程式時重設帳密。
- 換了程式卻沒有新表：依上方 migration 更新；不要重新匯入 seed 或刪掉既有資料卷。
- 想從另一台電腦使用：本機版只綁定 loopback，不是共用服務。請依 [HTTPS 部署流程](docs/DEPLOYMENT.md) 架設，再提供自己的網域。

已於 2026-11-26 在乾淨的 Ubuntu 24.04 amd64、arm64 主機實際執行 Docker 建置與啟動。本機示範版與 HTTPS 部署版都通過建表、登入、收發、重啟保留資料等檢查；完整結果見 [Docker 驗證紀錄](docs/DOCKER-VERIFICATION.md)。組員準備好可運作的 Docker 與 Compose 後，可依本頁步驟建立自己的網站與資料庫。
