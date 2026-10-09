# Ch14 UI 與最終驗收：學習大綱與交接文件

Oct 9, 2026 · @hank

## 一、本章目標與定位

**Ch14 不加新的 RAG 能力，而是把 Ch01～Ch13 做好的資料流接成使用者看得到、能操作的系統，並用證據逐項通過教學文件第二十章的 10 項驗收。** 本章結束時打上 `ch14-done`，整個 POC 第一階段完成。

本章有三個產出：

1. **三個頁面**：`/document`（列表與狀態）、`/document/upload`（上傳）、`/knowledge/chat`（連續追問的問答）
2. **最終驗收報告**：`docs/notes/ch14-acceptance.md`，10 項各附上可重現的證據（指令輸出、測試名稱、截圖）
3. **學習總結**：`docs/notes/final-summary.md`，彙整 14 章的決策、backlog 與下一階段主題

### 承接 Ch13 的兩個實際問題

Ch13 實測地端追問的延遲是 **改寫 p50 29 秒、p95 77 秒，再加回答 10～30 秒**，一次追問約 40～110 秒。這直接決定了本章 UI 的設計重點：

- **進度要看得見**：使用者等 1 分鐘卻看不到任何東西，會以為系統壞了。問答改為串流，分階段回報「理解問題 → 搜尋文件 → 產生回答」。
- **降級要被統計**：改寫逾時會靜默降級成不改寫，Reranker 失敗也會靜默退回原排序。兩者都不報錯，必須在 log 中統計降級率，否則上線後品質悄悄下滑也沒人知道。

### 本章性質和前面不同

前面每一章都在改 Retriever 或 Pipeline，本章只動**應用層與介面層**。檢索、改寫、Citation 的行為一律不改，所以既有的評估結果必須在本章結束時**完全重現**，這是本章最重要的回歸條件。

## 二、學習大綱：核心知識點

完成本章後，你應該能回答一個實務問題：**一個「能跑的 Pipeline」和一個「使用者敢用的系統」之間，差了哪些東西？** 粗體為本章核心，其餘了解概念即可。

| 類別 | 知識點 | 說明 |
| --- | --- | --- |
| 串流 | **SSE（Server-Sent Events）** | 伺服器單向推送事件；格式為 `event:` + `data:` + 空行。Ch02 已用於 Chat，本章用在 RAG 問答 |
|  | **事件協定設計** | 不只串文字：`stage`、`rewrite`、`retrieval`、`delta`、`done`、`error` 各有用途，前端依事件型別更新畫面 |
|  | fetch + ReadableStream vs EventSource | EventSource 只能用 GET，問題要放進 URL；用 POST 帶 JSON 時改用 fetch 讀取串流 |
|  | **串流與後處理的衝突** | 回答是一個字一個字送出的，但 Ch10 的引用檢查要等全文才能做。最後的 `done` 事件要帶回清理後的答案與來源，取代畫面上的暫時文字 |
|  | 取消與中斷 | 使用者關掉頁面時，要停止生成並把這則訊息標記為中斷，不能讓半截回答進入下一輪歷史 |
| 體驗 | **感知延遲（Perceived Latency）** | 總時間不變，但「看得到進度」和「空白等待」的感受完全不同 |
|  | Optimistic UI 與狀態輪詢 | 上傳後立即顯示、背景 Job 進度用輪詢更新 `documents.status` |
|  | 空狀態與錯誤狀態 | 沒有文件、資料不足、改寫降級、服務離線，各自要有明確的畫面 |
| 可觀測性 | **降級率（Degradation Rate）** | 改寫 fallback、Rerank 降級的比例。不統計，靜默降級就等於沒發生 |
|  | 延遲分段（p50 / p95） | 改寫、檢索、重排、回答各自計時，才知道慢在哪裡 |
|  | Structured Log | `rag_query_logs` 已有欄位，本章補統計指令 |
| 安全 | **XSS 與 LLM 輸出** | 模型輸出是不受信任的文字，不能直接當 HTML 插入；文件名稱也一樣 |
|  | CSRF、上傳驗證 | Web 路由的 CSRF、檔案大小與 MIME 白名單、重複檔案（sha256） |
|  | 不信任 Client 的歷史 | 延續 Ch13：前端只送 `conversation_id`，不送對話內容 |
| 驗收 | **以證據驗收** | 每一項都要能重現：指令、測試名稱或截圖，而不是「我試過可以」 |
|  | 回歸基準（Regression Baseline） | 所有評估集在本章結束時重跑，結果必須與 Ch13 相同 |
|  | 能力驗收 vs 品質驗收 | 第二十章 10 項多數是「能力有沒有」；品質（答對率、忠實度）是下一階段 Evaluation 的範圍 |
| 延伸 | 部署前的差距 | 認證權限、多人並行、Ollama 排隊、常駐服務（launchd / Docker），本章只記錄、不實作 |

**本章的學習順序建議**：先做 `/knowledge/chat` 的非串流版本，確認畫面與資料正確；再換成串流，親身比較兩者的等待感受；最後才做驗收。這樣能分清楚問題出在資料還是出在串流。

## 三、架構：三個頁面與串流問答

**頁面只是既有 Service 的新入口：Controller 呼叫 Ch03 的上傳流程、Ch07 的刪除同步、Ch13 的 `RagAnswerService`，不在介面層寫任何檢索或 Prompt 邏輯。**

### 3.1 頁面與對應的後端能力

| 頁面 | 畫面內容 | 呼叫的既有能力 | 新增的部分 |
| --- | --- | --- | --- |
| `/document` | 文件列表：名稱、狀態、頁數、Chunk 數、更新時間、錯誤訊息；刪除、重新索引 | Ch03 `documents.status`、Ch07 刪除同步、`rag:index` | 狀態輪詢（處理中才輪詢）、刪除確認 |
| `/document/upload` | 選檔、上傳、送出後導回列表 | Ch03 上傳與 Queue Job、sha256 重複檢查 | 大小與 MIME 驗證訊息 |
| `/knowledge/chat` | 對話、Provider 選擇、階段進度、串流回答、來源清單、新對話 | Ch13 `RagAnswerService`、`conversation_id`、Ch10 編號對照表 | 串流端點、事件協定、中斷處理 |

### 3.2 串流事件協定

新增 `POST /api/knowledge/ask/stream`，回應為 SSE。既有的 `POST /api/knowledge/ask`（非串流）**保持不變**，評估指令與舊呼叫方式繼續使用它。

| 事件 | 時機 | data 內容 |
| --- | --- | --- |
| `conversation` | 一開始 | `conversation_id`（新對話時由伺服器建立） |
| `stage` | 每進入一個階段 | `rewriting` / `retrieving` / `generating` |
| `rewrite` | 改寫結束 | `rewritten_question`、`rewrite_status`（skipped / rewritten / unchanged / fallback） |
| `retrieval` | 檢索結束 | 候選數、是否重排、是否降級 |
| `delta` | 回答生成中 | 一小段文字 |
| `done` | 結束 | 完整 `RagAnswer`：清理後的 `answer`、`status`、`references`、`llm_called`、耗時 |
| `error` | 任何階段失敗 | 給使用者看的訊息與錯誤代碼（不含堆疊與內部路徑） |

**資料不足的短路**：沒有候選通過門檻時，直接送出 `done`（`llm_called = false`），不會出現 `generating` 與 `delta`。這是驗收第 3 項在畫面上的證據。

**`done` 是唯一的真相**：`delta` 只是暫時顯示，前端收到 `done` 後一律以其中的 `answer` 取代畫面文字，並用 `references` 產生來源清單。來源永遠由程式依資料產生，不從串流文字中解析。

&#91;embedded content: 串流問答流程 · 3 段處理、1 個門檻判斷\]

資料不足的題目在門檻判斷處就送出 done，畫面上不會出現任何生成過程；只有通過門檻的題目才會進入回答並送出 delta。

## 四、實作重點與常見陷阱

**本章的陷阱大多不在 AI，而在 HTTP 與瀏覽器：串流被緩衝、開發伺服器被佔住、模型輸出被當成 HTML。** 和前面各章一樣，多數不會報錯。

### 4.1 串流不要複製一份 Pipeline

最容易犯的錯，是為了串流另外寫一份「改寫 → 檢索 → 回答」。兩份流程遲早不一致，評估測的是非串流版本，使用者用的卻是串流版本。

做法：`RagAnswerService` 抽出共用的階段流程，加一個可選的進度回呼（例如 `RagProgressListener`），非串流版本傳空的 Listener 即可。回答階段在有 Listener 時改呼叫 Ch02 的 `ChatService.stream()`，最後組出的 `RagAnswer` 與非串流版本**欄位、規則完全相同**。

### 4.2 SSE 的五個靜默失敗

| 陷阱 | 症狀 | 處理 |
| --- | --- | --- |
| **輸出緩衝** | 回答最後才一次出現，看起來不是串流 | 每個事件後 flush；回應標頭帶 `Cache-Control: no-cache`、`X-Accel-Buffering: no` |
| **開發伺服器被佔住** | 問答進行中，`/document` 的狀態輪詢卡住 | `php artisan serve` 預設單一 worker，一條 SSE 會佔住它；設定 `PHP_CLI_SERVER_WORKERS`（例如 4） |
| **執行時間上限** | 長的追問在 60 秒左右被切斷 | 串流端點明確設定時間上限，需大於「改寫逾時 + 回答逾時」 |
| **Session 鎖** | 同一使用者的其他請求在問答期間排隊 | 串流開始前釋放 Session（或此端點不使用 Session） |
| **客戶端中斷** | 使用者關頁面後，模型仍在背景生成 | 偵測連線中斷後停止讀取；訊息 `status` 標為 interrupted，不進入下一輪歷史 |

### 4.3 引用編號在串流中的處理

Ch10 的規則是「LLM 只輸出編號，程式對應來源，不合規標記要清掉」。串流時畫面會先出現原始文字，可能包含 `[7]` 這種超出範圍的編號。

- 串流中：`[n]` 先顯示為一般文字，不做成連結
- 收到 `done`：以清理後的 `answer` 取代全文，再把有效的 `[n]` 轉成可點擊的來源標記
- 資料不足時：不顯示來源區塊（沿用 Ch10「整段回答為資料不足才判定不足」的規則）

### 4.4 XSS：LLM 輸出是不受信任的輸入

文件內容可能夾帶 HTML 或 script，模型也可能把它照抄進回答。**回答、文件名稱、改寫後的問題，一律以純文字插入 DOM（`textContent`），不使用 `innerHTML`。** 若要支援 Markdown，必須先經過消毒（sanitize）再渲染，並加一個測試：上傳含 `<script>` 的文件，詢問後畫面不會執行它。

### 4.5 降級統計

新增 `rag:stats` 指令，從 `rag_query_logs` 統計指定期間的：

- `rewrite_status` 分布與 **fallback 比例**
- Rerank 降級比例
- `insufficient_no_candidates` / `insufficient_by_llm` / `answered` 分布
- 各階段耗時 p50 / p95（改寫、檢索、重排、回答、總計）

只做統計與輸出，**不做告警**。告警屬於下一階段的 Observability。

### 4.6 Provider 選擇的範圍

`/knowledge/chat` 的 Provider 下拉選單只列出 config 中已設定、可用於 RAG 回答的 Chat Provider。依 Ch13 的 follow 規則，改寫會跟著回答 Provider 走，所以**切換 Provider 只應在新對話時生效**，避免同一段對話中途換了改寫模型。畫面上要讓使用者看得出目前用哪一家（資料是否外送）。

## 五、交接給 Agent CLI

**前置作業由你完成，其餘交給 Agent；Agent 在介面完成、開始最終驗收前必須先停下來，等你操作過畫面再繼續。** 驗收需要你親自在瀏覽器操作與截圖，Agent 無法代替。

### 5.1 前置作業（你自己做）

- [ ] 確認 `ch13-done` 已 commit 並打 tag，`git status` 乾淨
- [ ] 確認 Ch13 收尾已完成：hook 指令改為 `$CLAUDE_PROJECT_DIR` 絕對路徑，並實測從子目錄寫入 `.env` 會被擋下
- [ ] 啟動 llama-server（`.env` 目前為 `RAG_RERANK_ENABLED=true`，最終驗收要用線上設定）
- [ ] 執行 `bash scripts/check-env.sh`，全部通過
- [ ] 確認 OpenAI API Key 可用（驗收第 1 項要切換雲端）
- [ ] 準備一份**驗收用的新文件**（不在測試集內），用來實測上傳、問答、刪除的完整流程

### 5.2 範圍

**本章做**：三個頁面、串流問答端點與事件協定、RagAnswerService 的進度回呼重構、`rag:stats`、XSS 防護、10 項最終驗收報告、全部評估集回歸、學習總結。

**本章不做**：調整檢索、門檻、Embedding、Hybrid、Rerank、改寫或 Citation 的任何參數與規則；處理 backlog 項目；登入與權限；多人並行與部署（launchd、Laravel 進 Docker）；告警；前端框架（Livewire、Vue、React）。

### 5.3 執行項目

1. **RagAnswerService 重構**：抽出共用階段流程，加入可選的 `RagProgressListener`（stage、rewrite、retrieval、delta）。非串流的 `answer()` 傳入空 Listener，**行為與輸出必須與 Ch13 完全相同**。
2. **串流端點** `POST /api/knowledge/ask/stream`：依第三節的事件協定輸出 SSE。標頭帶 `Content-Type: text/event-stream`、`Cache-Control: no-cache`、`X-Accel-Buffering: no`；每個事件後 flush；時間上限大於「該 Provider 的改寫逾時 + 回答逾時」；開始串流前釋放 Session。
3. **中斷處理**：偵測客戶端斷線後停止讀取 LLM 串流；該則助理訊息 `status = interrupted`，`ConversationHistory` 不得載入它。
4. **`done` 事件**：內容與非串流版本的 `RagAnswer` 相同（清理後的 answer、references、status、llm\_called、耗時、rewrite 欄位）。資料不足短路時只送 `conversation`、`stage`、`rewrite`、`retrieval`、`done`，不呼叫 ChatService。
5. **`/document`**：列表欄位見 3.1；狀態為 uploaded / parsing / parsed / indexing 時才輪詢（例如每 3 秒），全部完成就停止；failed 顯示 `error_message`；刪除需確認，走 Ch07 的同步刪除流程；提供「重新索引」。
6. **`/document/upload`**：沿用 Ch03 上傳流程；顯示大小與類型限制；sha256 重複時顯示既有文件名稱；成功後導回 `/document`。
7. **`/knowledge/chat`**：
   - Provider 下拉選單，只在新對話生效；畫面標示目前 Provider 是地端或雲端
   - 階段進度：「理解問題 → 搜尋文件 → 產生回答」，顯示已等待秒數
   - 改寫後的問題以小字顯示（可收合）；`fallback` 時提示「以原問題搜尋」
   - 收到 `done` 後以清理後的 answer 取代暫時文字，`[n]` 轉成來源標記，下方列出「檔名　條號　第 N 頁」
   - 資料不足以獨立樣式顯示，不顯示來源區塊
   - 「新對話」按鈕；`conversation_id` 存在頁面狀態（可選 sessionStorage，讀寫包 try/catch）
   - 串流進行中可按「停止」
8. **前端安全**：所有模型輸出、文件名稱、改寫問題一律以 `textContent` 插入；Web 路由啟用 CSRF；前端只送 `conversation_id`，不送歷史訊息。
9. **`rag:stats {--since=} {--until=}`**：依 4.5 輸出降級率、狀態分布與各階段 p50 / p95。
10. **開發環境**：`.env.example` 與 `CLAUDE.md` 補上 `PHP_CLI_SERVER_WORKERS` 的說明與建議值；`CLAUDE.md` 新增規則：「串流與非串流必須共用同一個 Pipeline；模型輸出不得以 HTML 插入」。

### 5.4 測試（使用 Fake Provider，不呼叫真實服務）

- 串流事件順序：正常回答為 conversation → stage(rewriting) → rewrite → stage(retrieving) → retrieval → stage(generating) → delta… → done
- 資料不足短路：沒有 `delta`、沒有 `generating`，`done.llm_called = false`，FakeChatProvider 未被呼叫
- 同一題的串流 `done` 與非串流 `answer()` 結果相同（answer、references、status）
- 串流中出現超出範圍的 `[7]`，`done.answer` 已清除
- 中斷後該訊息為 interrupted，下一輪的 ConversationHistory 不包含它
- 錯誤事件不洩漏例外訊息、堆疊或檔案路徑
- 上傳：超過大小、不允許的 MIME、重複 sha256 各有明確錯誤
- 刪除文件後，Qdrant 中該 `document_id` 的 Points 為 0（沿用 Ch07 測試方式）
- `rag:stats`：以固定的 log 資料驗證比例與 p50 / p95 計算
- 所有新測試沿用 Ch12 的規則：會影響行為的設定固定在 `phpunit.xml`，不受 `.env` 影響

### 5.5 最終驗收對照（教學文件第二十章）

每一項都要在 `docs/notes/ch14-acceptance.md` 附上**可重現的證據**。標「你」的項目需要你在瀏覽器操作並截圖。

| # | 驗收項目 | 證據 | 誰做 |
| --- | --- | --- | --- |
| 1 | 同一問題可切換地端與雲端模型回答 | 同一題分別以 Ollama、OpenAI 新對話提問的截圖；`rag_query_logs` 兩筆紀錄的 provider 與 model | 你 + Agent |
| 2 | 有答案的問題能正確標示文件、條號與頁碼 | 驗收用新文件的問答截圖；Ch10 引用指標的最終重跑結果 | 你 + Agent |
| 3 | 沒有答案時回答資料不足，未通過門檻時不呼叫 LLM | 無答案題的 SSE 事件紀錄（無 delta、`llm_called = false`）；對應測試名稱 | Agent |
| 4 | 追問能找到正確段落 | 畫面上「特休規定 → 那兩年年資呢？」的截圖，含改寫後問題與來源；`rag:eval-conversation` 重跑結果 | 你 + Agent |
| 5 | 刪除文件後問答不再引用 | 在 `/document` 刪除驗收用文件 → `rag:index-check` 一致 → 再問同一題的截圖 | 你 + Agent |
| 6 | 測試集 Recall@K 有記錄並可比較 | 列出 Ch08～Ch13 的評估檔，加上本章重跑的總表 | Agent |
| 7 | Provider 切換不需修改業務邏輯 | `ch13-done..HEAD` 中業務層沒有依 Provider 名稱分支（grep 結果）；以 Fake Provider 切換的測試 | Agent |
| 8 | 至少比較兩個 Embedding 模型 | Ch08 的比較紀錄；Qdrant 中兩個 Collection 的名稱與 Point 數 | Agent |
| 9 | 只支援 Chat 的 Provider 不實作 Embedding 介面 | `AnthropicProvider` 的類別宣告；Ch06 的 UnknownEmbeddingProviderException 測試 | Agent |
| 10 | 門檻比較方向已依 Vector DB 與 Distance Metric 驗證 | Ch08 的門檻紀錄；Retriever 使用 Qdrant `score_threshold`、Collection 為 Cosine 的程式位置 | Agent |

### 5.6 本章驗收

1. 三個頁面可完成「上傳 → 處理完成 → 問答與追問 → 刪除」的完整流程
2. 串流與非串流對同一題的 `done` 結果相同
3. 資料不足時畫面上沒有任何生成過程，log 中 `llm_called = false`
4. 含 `<script>` 的文件，問答後畫面不會執行它
5. 問答進行中，`/document` 仍可正常載入與輪詢
6. `rag:stats` 能輸出改寫 fallback 比例與各階段 p50 / p95
7. **回歸**：`questions.jsonl`、`hybrid.jsonl`、`reasoning.jsonl` 在相同設定下的檢索結果與 Ch13 完全相同；涉及 LLM 生成的評估若有差異，逐題列出並說明
8. `ch14-acceptance.md` 的 10 項全部附上證據；未通過的項目寫明原因，不得省略
9. 全部測試與 pint 通過

### 5.7 可以直接貼給 Agent

```
進入 Ch14：UI 與最終驗收。
本章只建立介面層與驗收，不修改檢索、門檻、Embedding、Hybrid、Rerank、改寫或 Citation 的任何參數與規則，也不處理 backlog。

【前置確認】
1. 確認 git 狀態乾淨、tag ch13-done 存在，否則停下來回報。
2. 執行 bash scripts/check-env.sh，有失敗項目就停下來回報，不要自行修改 .env。
3. 建立分支 ch14。

【實作範圍】
4. 依交接文件 5.3 第 1～10 項實作。重點：
   - 串流與非串流共用同一個 Pipeline，不可複製一份流程
   - done 事件是唯一的真相：前端以 done.answer 取代暫時文字，來源只從 done.references 產生
   - 資料不足短路時不得呼叫 ChatService
   - 模型輸出、文件名稱、改寫問題一律用 textContent 插入
   - 前端使用 Laravel 預設的 Blade + Vite，只用原生 JS，不引入 Livewire、Vue、React

【測試】
5. 依交接文件 5.4 撰寫測試，全部使用 Fake Provider。

【第一個停止點】
6. 頁面與測試完成後停下來回報，不要開始驗收。回報：
   A. 新增與修改的檔案，每個一句話說明責任
   B. RagAnswerService 重構前後的差異，以及如何確認非串流行為不變
   C. 串流端點的事件範例（正常回答、資料不足各一段實際輸出）
   D. 本機啟動方式（含 PHP_CLI_SERVER_WORKERS 與 queue 指令），讓我在瀏覽器操作

【第二階段：等我確認畫面後才執行】
7. 回歸：以 Ch13 的線上設定重跑 questions.jsonl、hybrid.jsonl、reasoning.jsonl 與 rag:eval-conversation，和 Ch13 結果比對，寫入 docs/notes/ch14-regression.md。
8. 依交接文件 5.5 撰寫 docs/notes/ch14-acceptance.md。標示「你」的項目留下欄位，等我提供截圖；其餘由你補上證據（指令與輸出、測試名稱、程式位置）。
9. 執行 rag:stats，把 Ch13 以來的降級率與各階段 p50 / p95 寫進驗收報告。
10. 撰寫 docs/notes/ch14-summary.md（沿用 Ch00 篇章總結格式），以及 docs/notes/final-summary.md：
    - 14 章的主要決策與理由（一章一行，連到各章 summary）
    - 最終線上設定（Embedding、門檻、Hybrid、Rerank、改寫、Window、Prompt 版本）
    - 整理後的 backlog，依「影響正確性 / 影響效能 / 工程品質」分類
    - 下一階段主題：Structured Output、Tool Calling、Agent、MCP、完整 Evaluation（Faithfulness）、Observability、Guardrails、Prompt Caching
11. 更新 CLAUDE.md 與 AGENTS.md：串流與非串流共用 Pipeline；模型輸出不得以 HTML 插入；PHP_CLI_SERVER_WORKERS 說明。

完成後回報，commit 與 tag ch14-done 由我執行。
```

## 六、需要你決策的地方

**交給 Agent 前請先看這五點；第 5.7 節的指令已依「建議」欄寫好，不同意的直接改指令即可。**

| # | 問題 | 建議 | 理由 |
| --- | --- | --- | --- |
| 1 | 前端技術 | Laravel 預設的 Blade + Vite，原生 JS | 本章的學習重點是事件協定與串流，框架會把 SSE 的細節藏起來；Livewire 的請求模型也不適合長時間串流 |
| 2 | 串流的讀取方式 | fetch + ReadableStream（POST） | EventSource 只能 GET，問題會出現在 URL 與伺服器 log 中；POST 也能沿用 CSRF |
| 3 | 最終驗收的線上設定 | 沿用 Ch12、Ch13 的決定：Rerank 開啟、改寫 follow、Window 5 | 驗收要測「實際會上線的設定」，不是開發中為了方便關掉的設定 |
| 4 | 回答顯示格式 | 先用純文字（保留換行），Markdown 列入 backlog | 純文字天然沒有 XSS 風險；要支援 Markdown 必須多一個消毒步驟，屬於可以延後的強化 |
| 5 | 是否處理 backlog | 本章不處理，只在 final-summary 分類整理 | 本章的回歸條件是「結果與 Ch13 相同」，任何 backlog 修正都會破壞這個基準 |

另外有一件事需要你先確認：**驗收第 1 項要用 OpenAI**，代表驗收用文件的內容會送到雲端。請選一份可以外送的文件當驗收用文件；若手上都是不能外送的內部文件，可以改用自己編寫的假規章。

## 七、收尾與下一階段

**`ch14-done` 代表第一階段 POC 完成：資料怎麼進去、怎麼被找到、怎麼進入 Context、怎麼被 LLM 使用，每一步都有實作與評估紀錄。**

### 7.1 收尾步驟（你自己做）

```bash
# 1. 把截圖放進 docs/notes/ch14-screenshots/，在 ch14-acceptance.md 補上連結
# 2. 確認 10 項驗收都有證據，未通過的有寫原因
# 3. 暫存文件與筆記
git add docs/notes/
# 4. commit 與 tag
git commit -m "feat: 實作 Ch14 UI 與最終驗收：三個頁面、RAG 串流問答、降級統計"
git tag ch14-done
# 5.（選做）標記第一階段完成
git tag v0.1-poc
```

### 7.2 下一階段的入口

教學文件把以下主題列為下一階段。每一個都能從本專案已經遇到的問題接上：

| 主題 | 本專案中的起點 |
| --- | --- |
| 完整 Evaluation（Faithfulness） | Ch10 只檢查引用格式，沒有檢查回答內容是否真的出自引用段落 |
| Observability | 本章的 `rag:stats` 只是事後統計，還沒有追蹤單次請求的完整鏈路與告警 |
| Structured Output | Ch13 的改寫結果要靠字串規則驗證，結構化輸出可以讓驗證更可靠 |
| Tool Calling / Agent | Ch13 是「固定先改寫再檢索」；Agentic RAG 讓模型自己決定要不要檢索、檢索什麼 |
| MCP | 把本專案的檢索包成 MCP Server，讓其他 Agent 工具也能使用公司文件 |
| Guardrails | Ch04 起的「參考資料不是指令」只靠 Prompt；Guardrails 是在 Prompt 之外加上檢查 |
| Prompt Caching | 雲端 Provider 每次重送相同的 System Prompt，可降低成本與延遲 |

建議的下一步是 **Evaluation**：後面每一個主題都會改變回答行為，沒有回答品質的評估，就無法判斷改動是變好還是變壞。這和 Ch08 先建測試集、Ch11 起「先有基準再改動」是同一個原則。
