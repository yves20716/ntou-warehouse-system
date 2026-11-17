# NEXUS WMS 公共倉儲管理系統

海大資料庫系統期末專題。一間倉儲公司替不同客戶保管貨物。倉儲員工登記每批貨存放的儲區及進出數量，客戶登入後可以查自己有哪些貨、放在哪裡，以及最近一次進出貨是什麼時候。人資使用獨立頁面維護員工資料。

## 在本機啟動

安裝 Docker 與 Docker Compose 後，在專案目錄執行

```sh
docker compose up --build -d
```

開啟 [本機網站](http://127.0.0.1:8080)。第一次啟動時，MySQL 會在新的資料卷建表並載入示範資料。若 8080 已有其他服務，可用 `WAREHOUSE_PORT=8081 docker compose up --build -d`，再開啟 8081。執行 `docker compose stop` 可關閉服務並保留資料。

四組示範帳號的密碼都是 `WarehouseDemo!26`。資料庫裡的人名、公司與貨物僅供本機展示。

| 帳號 | 身分 | 可以操作的內容 |
| --- | --- | --- |
| `client` | 海岬選物 | 查詢自己的貨物、各批庫存及進出貨紀錄 |
| `client2` | 山線器材 | 查詢另一家客戶的資料，可用來比較兩個客戶的可見範圍 |
| `employee` | 倉儲員工 | 新增、查詢、修改及刪除貨物，登記入庫與出庫 |
| `hr` | 人資 | 新增、查詢、修改及刪除員工，設定職務、薪資與主管 |

## 走一遍進出貨流程

1. 用 `employee` 登入「貨物管理」，新增海岬選物的貨物，例如「展示用收納盒」，計量單位填「箱」。
2. 選取剛建立的貨物，在海岬選物承租的 A-01 儲區入庫 10 箱。系統會建立一個庫存批次並記下承辦員工。
3. 從同一批出庫 3 箱。查詢庫存可看到剩餘 7 箱，在異動紀錄可查到入庫 10 箱及出庫 3 箱。
4. 登出後改用 `client` 查詢這批貨，再用 `client2` 查詢；貨物只出現在海岬選物的帳號裡。

每個儲區由一位客戶承租。出庫數量必須小於或等於該批餘量。庫存更新與進出貨紀錄在同一筆 MySQL 交易中寫入；已有批次的貨物會保留，方便日後回查。儲區容量是參考資料，貨物依「箱」、「台」等各自的單位記錄。

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
| `public/client.php` | 客戶查詢 |
| `public/employee.php` | 貨物、庫存與進出貨 |
| `public/hr.php` | 人事管理 |
| `public/actions.php` | 接收表單、檢查登入身分、執行對應操作 |
| `app/` | 資料庫連線、工作階段、查詢和更新邏輯 |
| `database/01-schema.sql` | 資料表、主外鍵及欄位限制 |
| `database/02-seed.sql` | 空資料庫第一次啟動用的示範資料 |

一座倉庫（`warehouses`）可分成多個儲區（`sections`）。客戶（`clients`）承租儲區並擁有貨物（`goods`）。每次入庫建立一個批次（`inventory`），每筆異動（`movements`）連回批次及承辦員工（`employees`）。登入帳號（`users`）連回對應的客戶或員工，以決定能查閱的資料與可執行的操作。
