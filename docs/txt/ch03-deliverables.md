# Ch03 上傳、Queue 與逐頁解析：交付重點

> 對應教學文件：第二階段（文件上傳與解析）
> 前置章節：Ch00 環境與專案骨架（poppler、MySQL 8.4 已就緒）

## 本章目標

把文件收進系統、在背景解析，並**逐頁**保存乾淨的文字，作為 Ch04 Chunking 與 Ch09 Citation 的基礎。本章不涉及任何 AI 功能。

```
POST /document/upload
  → 驗證（副檔名 + 實際 MIME + 大小）
  → 存檔（私有 Disk）
  → documents（status = uploaded）
  → dispatch(ParseDocumentJob) → 立即回應

[Queue Worker]
ParseDocumentJob
  → status = parsing
  → 依 MIME 選擇 Parser → 逐頁抽取 → Normalize
  → Transaction：刪除舊 pages → 寫入新 pages
  → status = parsed（例外 → failed + error_message）
```

## 一、程式交付

### 資料表（Migration）

- [ ] `documents`：`name`、`mime_type`、`path`、`size`、`sha256`、`page_count`、`status`、`error_message`、timestamps
- [ ] `document_pages`：`document_id`、`page_number`、`content`（`MEDIUMTEXT`），建立 unique(`document_id`, `page_number`)
- [ ] `jobs`、`failed_jobs`（Queue 使用 database driver）

### 核心類別

- [ ] `DocumentStatus` Enum：`uploaded` / `parsing` / `parsed` / `indexing` / `indexed` / `failed`，並定義合法的狀態轉換
- [ ] `DocumentParserInterface`：`parse(string $path): ParsedPage[]`
  - [ ] `TxtParser`：偵測編碼（UTF-8 / UTF-8 BOM / Big5）並轉為 UTF-8，視為第 1 頁
  - [ ] `MarkdownParser`：視為第 1 頁，保留標題標記供 Ch04 使用
  - [ ] `PdfParser`：以 Symfony Process 呼叫 `pdftotext`，依 `\f` 分頁
- [ ] `TextNormalizer`
  - [ ] 全形英數字轉半形，**保留中文標點**（不可直接套用 NFKC）
  - [ ] 清除多餘空白；接回中文行尾的硬換行；保留段落間的空行
  - [ ] 移除在多數頁面重複出現的頁首頁尾（例如「公司機密」、頁碼）
- [ ] `ParseDocumentJob`
  - [ ] 只傳 `document_id`，在 Job 內查詢
  - [ ] 以 Transaction 先刪除舊頁面再寫入新頁面，確保冪等
  - [ ] 設定 `tries`、`timeout`
  - [ ] 在 `failed()` 中寫入 `failed` 狀態與錯誤原因
- [ ] 掃描型 PDF 偵測：頁面字數低於門檻時標示警告或設為 `failed`（例如「疑似掃描檔」）

### 頁面

- [ ] `/document`：文件列表，顯示處理狀態，失敗的文件可重新處理
- [ ] `/document/upload`：上傳頁，驗證副檔名、實際 MIME 與檔案大小
- [ ] 逐頁檢視頁：抽查解析結果

### 設定

- [ ] `.env` 新增 `QUEUE_CONNECTION=database`、`PDFTOTEXT_PATH`（Homebrew 預設為 `/opt/homebrew/bin/pdftotext`）
- [ ] 同步更新 `.env.example`

## 二、測試交付

- [ ] `tests/fixtures/` 樣本檔
  - [ ] 中文文字型 PDF（多頁）
  - [ ] UTF-8 TXT、Big5 TXT
  - [ ] Markdown
  - [ ] 壞檔（副檔名是 `.pdf`，內容不是 PDF）
  - [ ] 掃描型 PDF（可選）
- [ ] Feature Test：上傳後有 dispatch Job（`Queue::fake()`、`Storage::fake()`）
- [ ] Unit Test：每個 Parser 的頁數與內容正確；Normalizer 不會改動中文標點
- [ ] Job Test：重複執行不會產生重複頁面；發生例外時狀態為 `failed`

## 三、驗收

- [ ] 上傳中文 PDF 後，逐頁檢視**每一頁**都沒有亂碼，頁數與原檔一致
- [ ] 狀態依序從 `uploaded`、`parsing` 走到 `parsed`
- [ ] 壞檔的狀態為 `failed`，並顯示可讀的錯誤原因
- [ ] 失敗的文件可以重新處理，重跑後頁面不重複
- [ ] 頁首頁尾已被移除
- [ ] 沒有啟動 Worker 時，文件停在 `uploaded`；啟動後會自動處理

## 四、文件與收尾

- [ ] `CLAUDE.md` 與 `AGENTS.md` 補上規則
  - 開發時需另開終端機執行 `php artisan queue:listen`
  - 修改 Job 後需重啟 Worker（Worker 是常駐 process，記憶體裡是舊程式碼）
- [ ] `docs/notes/ch03-summary.md`：篇章總結，包含 Queue 觀念、Normalization 的決策、抽查時的實測觀察
- [ ] commit 並打上 tag `ch03-done`

## 五、明確不做

Chunking（Ch04）、Embedding、Qdrant、OCR、表格結構、docx。這些要寫進給 Agent 的 Prompt 裡，避免它順手做過頭。

## 附：本章新知識點

| 類別 | 名詞 |
| --- | --- |
| Queue | Job、Dispatch、Worker（常駐 process）、Driver、tries / backoff / timeout、failed_jobs、Idempotency |
| 狀態管理 | State Machine、PHP Enum |
| 檔案 | Storage Disk、MIME 偵測（finfo）、Content Hash（sha256） |
| 文字處理 | 編碼偵測（UTF-8 / BOM / Big5）、Normalization、頁首頁尾移除、硬換行處理 |
| PDF | pdftotext、`\f` 分頁、`-layout` 模式、文字型與掃描型 PDF |
| 測試 | `Queue::fake()`、`Storage::fake()`、Fixtures |
