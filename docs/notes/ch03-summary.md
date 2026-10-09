# Ch03 總結：上傳、Queue 與逐頁解析

## 成果

```
POST /document/upload
  → 驗證（副檔名 + finfo 實際 MIME + 兩者一致 + 大小 ≤ 8MB）
  → 私有 Disk 以隨機檔名存檔 → documents（status_key = uploaded）→ dispatch → 立即回應

[queue:listen]
ParseDocumentJob（只帶 document_id）
  → uploaded → parsing（條件式 UPDATE）
  → 依 MIME + 副檔名選 Parser → 逐頁抽文字 → TextNormalizer → 掃描檔偵測
  → Transaction：刪除舊頁面 → 寫入新頁面
  → parsing → parsed（永久性錯誤 → fail()；暫時性錯誤 → 重試，用盡後 failed()）
```

頁面：`/document` 列表（狀態、失敗原因、重複檔案提示、重新處理，處理中自動更新）、`/document/upload`、`/document/{id}` 逐頁檢視。

## Queue 觀念

| 觀念 | 本章的做法 | 為什麼 |
| --- | --- | --- |
| Job 只傳 ID | `new ParseDocumentJob($documentId)` | Job 會被序列化存進 `jobs` 表；傳 Model 會在執行時拿到派送當下的舊資料 |
| Worker 是常駐 process | 開發用 `queue:listen`（每個 Job 重新載入程式碼） | `queue:work` 記憶體裡是舊程式碼，改 Job 後必須重啟 |
| 永久性 vs 暫時性錯誤 | 壞檔、掃描檔 → `fail()` 不重試；DB 中斷 → 重試 3 次（10 / 30 秒） | 壞檔重試幾次都一樣，只會浪費資源 |
| 逾時的三層關係 | pdftotext 60 秒 < Job 80 秒 < retry_after 90 秒，`failOnTimeout = true` | Job 超過 retry_after 會被另一個 Worker 重跑；未設 failOnTimeout 時 Worker 逾時會直接結束且不呼叫 `failed()` |
| 冪等 | 狀態檢查 → Transaction 先刪後寫 → unique(document_id, page_number) | Queue 一定會發生重試，三道防線確保結果和跑一次相同 |
| 樂觀鎖 | `UPDATE … WHERE status_key = 讀到的狀態`，影響 0 筆即視為被搶先 | 先讀再 save() 時，較晚的失敗會覆寫掉已完成的結果（反向驗證證實） |

MySQL 的陷阱：UPDATE 的值與原本相同時回報影響 0 筆，因此 Job 重試時的 `parsing → parsing` 不能當作狀態轉換，改由 Service 判斷「已是 parsing 即為重試」。

## Normalization 的決策

| 決策 | 理由 |
| --- | --- |
| 只轉全形英數字，英數字之間的「－」轉「-」；不用 NFKC | NFKC 會連中文全形標點一起轉換，改動正文 |
| 頁首頁尾：頁首、頁尾分開統計，只看每頁前後 2 個非空行，過半頁面出現才刪，少於 3 頁不做 | 保守原則：寧可漏刪，不可誤刪正文 |
| 只對「頁碼格式」的行遮罩數字 | 對所有行遮罩時，「第1條」「第2條」與每頁開頭的正文都被誤刪（反向驗證有 6 個測試失敗） |
| 只對 PDF 接回硬換行；句末標點（含英文 .?!）、條文標題、清單前後不接 | TXT / Markdown 的換行是作者刻意的；沒有標點的段落邊界無法從純文字判斷，不確定就保留 |
| 空白頁保留為空字串；只移除 pdftotext 最後一個 `\f` 產生的空元素 | 刪掉空白頁會讓後面的頁碼全部錯位，Ch09 Citation 會指錯頁 |
| 編碼：先檢查 UTF-8，再試 Big5（CP950） | Big5 的位元組規則寬鬆，許多 UTF-8 中文也「剛好」是合法 Big5，順序顛倒會把 UTF-8 誤轉成亂碼 |

Normalizer 的規則草案經 Codex 審查後修改：頁碼遮罩範圍、英文句末標點、結構行保護三處。

## 抽查時的實測觀察

- 測試用 PDF 使用 **CID 字型**（`Identity-H`），正是教學文件警告 PHP 套件會抽出亂碼的格式；pdftotext 解析正常。
- pdftotext 會把「第 1 頁 / 共 3 頁」輸出成 `第1頁/共3頁`，也會把「第十二條　特別休假」拆成兩行；`-layout` 模式不拆，但會補大量對齊用的空白，因此採用預設模式。
- `file` 指令把 Big5 誤判為 `iso-8859-1`：編碼偵測本身不可靠。
- 偽裝成 PDF 的純文字檔，finfo 判斷為 `text/plain`，在上傳時就被擋下；**真的 PDF 但內容損毀**的檔案能通過上傳，才會在解析時失敗。所以壞檔有兩道防線。
- 掃描型 PDF 抽出 0 字，以「平均每頁字數 < 20」判定為疑似掃描檔。
- 工具陷阱：macOS 的 `sed` 不認得 `\f`；tinker 的 `echo` 會壓掉空行；檢查空白與換行時要用 JSON 輸出。
- 框架陷阱：`FormRequest::messages()`、`Command::ask()`、PHPUnit 的 `TestCase::run()` 都是既有方法，不可拿來當自訂方法名稱。
- Laravel 13 的 session 以陣列保存驗證錯誤（`default.messages.file`），不是 `ViewErrorBag` 物件。

## 留給後續章節

- Ch05 Chunking：條文標題（第 X 條）與 Markdown 標題已保留，可作為切分依據；頁碼從 `document_pages.page_number` 取得。
- 待解決清單（`docs/notes/backlog.md`）：文件可能永久停在 parsing 的修復機制。
