# Ch14 總結：UI 與最終驗收

## 成果

```
/document            文件列表：狀態、頁數、Chunk 數、錯誤訊息；處理中才輪詢（/document/statuses）；刪除、重新處理、重新索引
/document/upload     上傳（Ch03 流程）；內容與既有文件相同時提示既有文件名稱
/knowledge/chat      問答：Provider 選擇（地端／雲端標示、只在新對話生效）、階段進度與等待秒數、
                     串流回答、改寫後問題（可展開）、來源清單、資料不足樣式、停止、新對話

POST /api/knowledge/ask/stream（SSE）
  conversation → stage(rewriting) → rewrite → stage(retrieving) → retrieval
  → [沒有候選：直接 done]
  → stage(generating) → delta… → done（與非串流 /api/knowledge/ask 的回應相同）
  任何階段失敗 → error（只有錯誤類型與固定訊息）
```

- **共用 Pipeline**：
  - `RagAnswerService::answer()` 加上可選的 `RagProgressListener`。
  - 有 Listener 時回答改用 `ChatService::stream()`，最後組回與 `chat()` 相同的 `ChatResult`，之後的資料不足判斷、Citation、來源產生完全共用。
  - 非串流不傳 Listener，616 個既有測試沒有修改斷言就全部通過。
- **中斷**：
  - 呼叫端離開時（`connection_aborted()`），停止讀取 LLM 串流，回答狀態為 `interrupted`。
  - 這則回答會保存，但不進入下一輪的對話歷史。
- **Provider 鎖定**：
  - `conversations.provider` 記下建立對話時的回答 Provider。
  - 接續對話時沒帶 provider 就沿用它；帶了不同的 Provider 回 422。
- **可觀測性**：
  - `rag_query_logs` 新增 `reranked`、`rerank_degraded`、`rerank_ms`。
  - `rag:stats` 輸出改寫 fallback 率、Rerank 降級率、回答狀態分布、各階段 p50 / p95。
- **安全**：
  - 模型輸出、文件名稱、改寫問題一律以 `textContent` 插入。
  - 修正列表頁刪除確認的 XSS（Ch03 起就存在）。
  - CORS 只允許 `APP_URL`。
  - 錯誤事件不帶上游訊息與內部路徑。
- **驗收**：教學文件第二十章 10 項全部通過，見 `ch14-acceptance.md`。
- **回歸**：三份測試集的檢索名次與判斷、多輪評估 16 題的改寫與名次，都與 `ch13-done` 相同，見 `ch14-regression.md`。

## 學到的重點

### 一個「能跑的 Pipeline」和「使用者敢用的系統」差在哪裡

1. **看得見進度**：
   - 地端追問一次約 40～110 秒（改寫 20～80 秒 + 回答 10～30 秒）。
   - 串流加上分階段進度與等待秒數後，總時間不變，但使用者知道系統在做什麼。
   - 問答頁保留「串流顯示」開關，可以直接比較兩種等待感受。
2. **降級要被統計**：
   - 改寫逾時、Reranker 失敗都會靜默降級，問答照常完成。
   - `rag:stats` 把比例算出來。Ch14 期間改寫 fallback 0 / 5、Rerank 降級 0 / 9。
3. **畫面與系統狀態要一致**：
   - 原本問答頁把 `conversation_id` 存在 sessionStorage。到 `/document` 刪除文件再回來時，畫面是空的，但下一題被當成追問並經過改寫（截圖 10：「改寫 34816 ms」）。
   - 使用者看到的是新對話，系統以為是第 N 輪。
   - 改成重新載入就是新對話；「從伺服器恢復對話內容」列入 backlog #20。
4. **安全問題多半不在 AI**：
   - 刪除確認把檔名放進 `onsubmit` 的 JS 字串。Blade 雖然有跳脫，但 HTML 實體會在 JS 執行前被還原。
   - CORS 預設 `*`，讓任何網站都能借用同仁的瀏覽器讀取知識庫的回答。
   - 兩個都不會報錯。

### 資料不足的短路只省回答時間

- 驗收時「公司有提供員工宿舍嗎？」是對話中的第 3 題：沒有候選、沒有呼叫回答模型，但整題仍花 33 秒，其中改寫 32,263 ms。
- **資料不足的短路只省下回答的時間，追問時的改寫成本仍在**。
- 判斷「沒有候選」需要先檢索，而檢索必須用改寫後的問題，所以改寫無法省略。
- think 開啟時，改寫是地端追問最大的固定成本。即使是完整的問題（h08～h10），改寫也要 20 秒以上，模型要先思考才能判斷「不需要改寫」。

### 開發環境的靜默失敗

- `PHP_CLI_SERVER_WORKERS=4 php artisan serve` 單獨使用**沒有效果**：Laravel 在沒有 `--no-reload` 時只印一行警告，然後開單一 worker。
- 實測時一條串流佔住唯一的 worker，`/document` 等了 18.4 秒，正好等到串流結束；加上 `--no-reload` 後是 0.07 秒。
- CLAUDE.md 原本的寫法也是錯的，已修正。

### 測試也會「靜默通過」

- CORS 的測試一開始以 `config('cors.allowed_origins')[0]` 當預期值。設定改回 `*` 時，預期值也跟著變成 `*`，測試照樣通過。
- 改成以 `app.url` 當預期值後，反向驗證才會失敗。
- 預期值不能從受測的設定本身取得。

### 驗收要用證據，而且要檢查證據本身

- 截圖 04 有回答與來源，但「檢索用問題」是收合的，看不到改寫後的問題，不符合「含改寫後問題」的要求。
- 逐張檢查截圖才發現，請使用者補拍（重拍後符合）。
- hook 的實測也是同樣的道理：
  - 修正前，從專案根目錄寫入 `.env.*` 會被擋下，從子目錄則不會（hook 用相對路徑，找不到腳本時 exit 127，不阻擋）。只測一種情況，就會誤以為保護有效。
  - 改為 `$CLAUDE_PROJECT_DIR` 絕對路徑後，兩種情況都被擋下。

## 本章的設計決定

| 決定 | 理由 |
| --- | --- |
| Blade + Vite + 原生 JS | 學習重點是事件協定與串流，框架會把 SSE 細節藏起來 |
| fetch + ReadableStream（POST） | EventSource 只能 GET，問題會出現在 URL 與伺服器 log |
| 串流端點放在 `/api`、無 Session | 沒有 Session 就沒有 Session 鎖；API 沒有 Cookie 驗證，CSRF 沒有東西可保護。跨站讀取由 CORS 限制 |
| 回答以純文字顯示（保留換行） | 沒有 XSS 風險；Markdown 需要消毒，列入 backlog #21 |
| Provider 清單只列有改寫設定的（ollama、openai） | 沒有改寫設定的 Provider（gemini）追問一律不改寫，連續追問會失準 |
| 同一段對話不可換 Provider | 改寫跟隨回答 Provider，換了等於換改寫模型與資料外送對象 |
| 錯誤事件只送類型與固定訊息 | 上游訊息可能含內部位址與路徑 |
| 不處理 backlog | 本章的回歸條件是「結果與 Ch13 相同」 |

## 限制與後續

- 「含 `<script>` 的文件問答後不執行」與「問答中開 `/document`」沒有在瀏覽器實測。前者以程式與測試驗證，後者以 curl 實測。
- 對話只存在問答頁的記憶體中，重新載入後無法回到先前的對話（backlog #20）。
- 中斷的串流沒有記錄 Token 用量（backlog #1，Ch14 補充）。
- CORS 只能防止跨站讀取回應，不能阻擋盲送請求。根本解法是加入登入，見 final-summary。
