# NEXUS WMS 公共倉儲管理系統

海大資料庫系統期末專題，以 PHP 與 MySQL 建置。客戶可建立貨物品項、申請入庫或出庫、查詢處理進度；倉儲員工確認收發後更新庫存，記錄每批貨物的儲區與進出數量。人資透過獨立頁面維護員工資料。

| 用途 | 說明 |
| --- | --- |
| 本機安裝 | 下方「在本機啟動」 |
| 客戶、倉儲與人資操作 | [完整使用流程](docs/USAGE.md) |
| 網路部署 | [網路部署、建帳號與備份](docs/DEPLOYMENT.md) |
| 程式與資料庫測試 | [回歸測試](tests/README.md) |

每次本機安裝會建立獨立資料庫。多人共用庫存資料時，請部署 HTTPS 網站，並連線至同一個服務。

## 在本機啟動

先準備 Git、Docker 與 Docker Compose，確認 Docker 已啟動。macOS、Windows 可使用 Docker Desktop，Linux 可使用 Docker Engine 加 Compose。取得專案後執行：

```sh
git clone https://github.com/yves20716/ntou-warehouse-system.git
cd ntou-warehouse-system
docker compose up --build -d
docker compose ps
```

啟動後，在瀏覽器開啟 `http://localhost:8080`。首次啟動會在新的 MySQL 資料卷建立資料表並載入示範資料。

若 8080 已被占用，在專案根目錄的 `.env` 寫入 `WAREHOUSE_PORT=8081`，再執行相同指令，網址改成 `http://localhost:8081`。`.env` 不會提交到 Git。執行 `docker compose stop` 可關閉服務，`docker compose up -d` 可再次開啟，原資料會保留。

四組示範帳號的密碼都是 `WarehouseDemo!26`。資料庫裡的人名、公司與貨物僅供本機展示。

| 帳號 | 身分 | 可以操作的內容 |
| --- | --- | --- |
| `client` | 海岬選物 | 建立／編輯自己的貨物、提出或取消收發申請、查看庫存與紀錄 |
| `client2` | 山線器材 | 另一家客戶的相同功能，用來比較兩個客戶的可見範圍 |
| `employee` | 倉儲員工 | 管理貨物、處理客戶申請、登記實際收發 |
| `hr` | 人資 | 新增、查詢、修改及刪除員工，設定職務、薪資與主管 |

## 進出貨操作範例

1. 用 `client` 登入「貨物品項」，建立「展示用收納盒」，單位填「箱」，再從該列點「申請入庫」，數量填 10。申請狀態為「待處理」，庫存維持不變。
2. 改用 `employee` 登入，工作台會列出待處理申請。點「處理申請」，儲區選「基隆港倉／A-01」，確認入庫 10 箱。
3. 改用 `client`，在「庫存批次」找到這批貨，點「申請出庫」，填 3。再由 `employee` 確認出庫，庫存剩 7 箱。
4. 點貨物名稱或「明細」，可在側邊面板查看這個批次的入庫 10 箱與出庫 3 箱。`client2` 查不到這批海岬選物的貨物。
5. 客戶可取消尚未處理的申請；倉儲人員無法受理時可填原因，客戶能在明細看到回覆。

出庫申請不會預扣或保留庫存；實際完成時會再次檢查餘量。每筆申請只能處理一次。倉儲員工仍可直接登記現場收發。新增、編輯、申請與批次明細以側邊面板開啟，關閉後保留原清單位置；若有未儲存的修改，會提示確認。

每個儲區由一位客戶承租。出庫數量必須小於或等於該批餘量。庫存更新與進出貨紀錄在同一筆 MySQL 交易中寫入；已有批次或入庫申請的貨物會保留，方便日後回查。儲區容量是參考資料，貨物依「箱」、「台」等各自的單位記錄。

## 已有 PHP 與 MySQL 的環境

使用 PHP 8.1 以上的 CLI、PDO MySQL 與 MySQL 8.0.16 以上。先建立空的 `warehouse` 資料庫，再以可建表的帳號依序匯入兩份 SQL。

```sh
mysql -u root -p warehouse < database/01-schema.sql
mysql -u root -p warehouse < database/02-seed.sql
```

另為網站建立可對該資料庫執行 SELECT、INSERT、UPDATE、DELETE 的專用帳號。將下列設定換成該帳號的連線資料，再從專案根目錄啟動網站。

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

舊版資料庫請保留原資料卷，執行下列 migration 新增收發申請表。更新時勿重新匯入示範資料。

```sh
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -u"$MYSQL_USER" "$MYSQL_DATABASE"' < database/migrations/20261126-stock-requests.sql
```

使用自行安裝的 MySQL 時：

```sh
mysql -u root -p warehouse < database/migrations/20261126-stock-requests.sql
```

全新安裝已包含收發申請表。公開儲存庫提供程式、建表檔與虛構的示範資料；帳密設定、操作紀錄與含個資的報告應存放於私人位置。

## 啟動問題排查

- 網頁連不上：先執行 `docker compose ps` 確認 `db` 已 healthy、`web` 已啟動，再用 `docker compose logs --tail=50 web db` 查看錯誤。首次下載映像與初始化需要一些時間。
- 登入失敗：公開 seed 的密碼是 `WarehouseDemo!26`；已有資料卷會沿用舊資料，不會在更新程式時重設帳密。
- 缺少收發申請表：依上方 migration 更新，保留既有資料卷，勿重新匯入示範資料。
- 跨電腦連線：本機版只接受 loopback 連線。請依 [HTTPS 部署流程](docs/DEPLOYMENT.md) 設定主機與網域。

2026-11-26 已在 Ubuntu 24.04 amd64、arm64 的全新環境完成 Docker 測試。本機示範版與 HTTPS 部署版均通過建表、登入、收發及重啟後資料保留的檢查，詳見 [Docker 驗證紀錄](docs/DOCKER-VERIFICATION.md)。
