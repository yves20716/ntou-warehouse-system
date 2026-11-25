# Docker 驗證紀錄

2026-11-26（台灣時間）於 GitHub Actions 的 amd64、arm64 主機完成 Docker 測試，兩種架構均通過。測試版本為 `afaa2877ee51a1ccb5f144348d07125582551915`，使用獨立環境與全新資料卷。

[完整執行紀錄](https://github.com/yves20716/ntou-warehouse-system/actions/runs/45702515415)

| 環境 | Docker Engine | Compose | 結果 |
| --- | --- | --- | --- |
| Ubuntu 24.04 amd64 | 28.0.4 | 2.38.2 | 通過 |
| Ubuntu 24.04 arm64 | 28.0.4 | 2.38.2 | 通過 |

執行紀錄包含映像標籤與 digest。專案使用 `php:8.4-cli`、`php:8.4-apache`、`mysql:8.4`、`caddy:2-alpine`；映像更新後應重新執行驗證。

## 本機示範版

依 README 建置並啟動 `compose.yaml`。自動化指令加上 `--wait`，待容器就緒後執行測試。

| 檢查 | 實際結果 |
| --- | --- |
| 從空資料卷初始化 | 建立 9 張資料表、14 個外鍵 |
| 示範資料 | 4 個帳號、2 家客戶、5 個貨物品項、4 批庫存、6 筆進出貨；初始申請為 0 |
| 客戶送出入庫申請 | 申請待處理，尚未增加庫存或進出貨紀錄 |
| 倉儲確認入庫 10 箱 | 建立一個批次、一筆入庫紀錄 |
| 客戶申請出庫 3 箱 | 完成前餘量仍為 10；倉儲確認後剩 7 |
| 同批次紀錄 | 恰有入庫 10、出庫 3 兩筆，資料庫餘量與加總結果均為 7 |
| 重送、超額出庫 | 被拒絕，未重複處理或扣成負數 |
| 取消及未受理 | 保留申請結果與未受理原因，庫存不變 |
| 權限與資料隔離 | 另一位客戶看不到本次貨物、批次、申請與紀錄；跨角色寫入回傳 403 |
| 人資 | 新增員工、修改職務與時薪、刪除無關聯的測試員工均成功 |
| `stop` 後再次 `up` | 九表所有資料的雜湊一致，重新登入後仍查得到 7 箱及兩筆紀錄 |

## HTTPS 部署版

使用 `bin/production.sh` 與 `compose.production.yaml`，測試網域設為 `localhost`。Caddy 產生本機 CA，測試程式以該 CA 驗證 TLS 憑證與主機名稱。

- 初始化建立九張空資料表，示範資料與帳號皆未載入。
- 依部署文件匯入基本資料範本，由 CLI 建立四個測試帳號；密碼隨機產生並僅供該次測試使用。
- 網站資料庫帳號降為 SELECT、INSERT、UPDATE、DELETE；實際嘗試建表會被拒絕。
- Apache 正常提供網站，HTTP 會轉到 HTTPS，登入 Cookie 有 Secure 屬性；設定檔、應用程式與 SQL 路徑回傳 404。
- 新帳號通過收發、權限、人資及重啟檢查。
- 依文件執行 `mysqldump`，另外寫入一筆測試資料，再還原備份；還原後九表雜湊與備份前一致，網站也能重新登入查詢。
- 另外建立舊版八表資料庫，套用申請表 migration 兩次；結果為九張表，原有 6 筆進出貨與庫存總量 158 都保留。

## 重新執行測試

開啟 [Actions 頁面](https://github.com/yves20716/ntou-warehouse-system/actions/workflows/demo.yml)，選 **Run workflow**。有程式或設定變更時也會自動執行；只有 Markdown 文件修改時略過。

測試範圍為 Linux amd64／arm64 容器。Windows／macOS 的 Docker Desktop 安裝、公開網域 DNS 設定與憑證簽發尚未驗證。

本機安裝步驟見 [README](../README.md)；主機與網域設定見 [部署文件](DEPLOYMENT.md)。
