# Ch13 Query Rewriting 與 Conversation Memory：學習大綱與交接文件

Oct 9, 2026 · @hank

## 一、本章目標與定位

Ch13 對應教學第十二階段：讓追問在**檢索之前**先被改寫成可獨立理解的問題，並以 Sliding Window 管理對話紀錄。

Ch08～Ch12 都在強化「單一問題」的檢索：Ch11 解決找得到，Ch12 解決排得準。Ch13 處理的是另一種失敗：問題本身缺少主詞。

```
User：公司的特休規定是什麼？
AI：……
User：那兩年年資呢？   ← 直接拿這句去 Embedding，找不到特休段落
```

**本章最重要的一句話：錯誤發生在呼叫 LLM 之前。** 把對話紀錄交給回答用的 LLM 救不了它，因為 Retriever 拿到的是「那兩年年資呢？」，送進 Context 的段落本來就是錯的。

| 章節 | 解決的問題 | 改動位置 |
| --- | --- | --- |
| Ch11 Hybrid Search | 精確編號找不到 | Retriever |
| Ch12 Reranker | 找到了但排名靠後 | Retriever 之後 |
| **Ch13 Query Rewriting** | **問題本身不完整** | **Retriever 之前** |

對照組：Ch08 實驗 4 已記錄「那兩年年資呢？」的失敗現象，本章用它證明改寫的效果。

## 二、學習大綱：核心知識點

完成本章後，你應該能回答一個實務問題：**多一次 LLM 呼叫換來的檢索改善，值不值得？** 粗體為本章核心，其餘了解概念即可。

| 類別 | 知識點 | 說明 |
| --- | --- | --- |
| 對話狀態 | **Stateless API 與 Conversation Memory** | Chat API 不記得上一輪，每次要帶什麼由應用程式決定（Ch01 已實作 Messages） |
|  | Server-side Conversation State | 供應商提供的對話延續功能，不能取代業務層的記憶、權限與保存 |
| 檢索前處理 | **Query Rewriting / Standalone Question** | 先把追問改寫成獨立問題，再拿去檢索 |
|  | **指代與省略** | 「那」「它」「這個」是指代；「那兩年年資呢？」省略了主題。這兩種是改寫要補的東西 |
|  | Query Drift | 改寫是生成任務，會偏題或加料，改寫結果本身也要評估 |
|  | Query Expansion、Multi-query、HyDE | 一題改寫成多題、或先生成假答案再檢索。和本章對照，了解即可 |
| Context 管理 | **Sliding Window** | 只保留最近 N 輪，最簡單也最常用 |
|  | Conversation Summary | 較早的對話壓縮成摘要，適合長對話 |
|  | **Token Budget** | 歷史、RAG Context、回答三者共用 num\_ctx 8192，要分配上限 |
| 對話與引用 | **跨輪的引用編號** | 每一輪的 \[n\] 只對該輪的參考資料有意義，帶進下一輪就會誤導 |
|  | 話題切換（Topic Shift） | 使用者換主題時，改寫不能把舊主題硬塞進新問題 |
| 評估 | **多輪測試集** | 歷史要固定（腳本化），否則每次評估的輸入都不同 |
|  | 改寫前後 Recall@K、MRR | 沿用 Ch08～Ch12 的指標，只是輸入換成改寫後的問題 |
|  | 改寫忠實度 | 人工判讀：有沒有補對、有沒有改變原意、有沒有把上一輪答案帶進來 |
|  | 延遲成本 | 每次追問多一次 LLM 呼叫，地端可能多好幾秒 |
| 工程 | Pipeline Stage | 改寫是 Retriever 前面新增的一段，可獨立開關與降級 |
|  | Graceful Degradation | 改寫失敗就用原問題，問答不中斷（延續 Ch12 的做法） |
| 安全 | 歷史中的 Prompt Injection | 歷史訊息也是外部輸入，不能讓 Client 自行偽造 assistant 訊息 |
| 延伸 | Agentic RAG 的伏筆 | 讓 LLM 自己決定要不要檢索、要檢索什麼，是下一階段 Agent 的起點 |

**本章的學習順序建議**：先跑對照組 A（不改寫），親眼看到失敗；再跑 B（把歷史問題串在一起），看到「有改善但會被舊話題干擾」；最後跑 C（LLM 改寫）。三組放在一起，才看得出改寫解決了什麼、又多付了什麼代價。

## 三、架構與關鍵設計決策

改寫只影響「拿什麼去檢索」，ContextBuilder、Citation、Prompt 的回答規則都不動。Hybrid 的關鍵字檢索與 Reranker 也一律使用改寫後的問題。

&#91;embedded content: Ch13 問答流程 · 改寫位於 Retriever 之前\]

只有「檢索用問題」這一格是新的：第一輪直接沿用原問題，追問才經過改寫；之後的資料不足短路、ContextBuilder 與 Citation 都和 Ch12 相同。

| # | 決策 | 建議 | 理由 |
| --- | --- | --- | --- |
| 1 | 對話紀錄存哪裡 | **Server 端保存**：`conversations`、`conversation_messages` | Client 自帶歷史可以偽造 assistant 訊息（Injection）；Ch14 的 `/knowledge/chat` 也需要接續對話 |
| 2 | 第一輪要不要改寫 | **不改寫**，沒有歷史就直接用原問題 | 省一次 LLM 呼叫，單輪結果和 Ch12 完全一致，回歸測試才有意義 |
| 3 | 歷史帶什麼進改寫 | 使用者問題 + 助理回答文字，**移除 \[n\] 與來源清單** | 編號只對該輪有效；來源清單是程式產生的，不是對話內容 |
| 4 | 改寫失敗怎麼辦 | **降級為原問題**，寫 warning log | 空字串、逾時、過長、輸出像答案（含「資料不足」）都視為失敗 |
| 5 | 回答階段送什麼 | 依規範：最近 N 輪 + RAG Context + **原始問題** | 讓回答保留使用者的語氣與指代；是否附上改寫問題，用實驗 E 決定 |
| 6 | 改寫用哪個模型 | 獨立設定 `rewrite.provider`，預設 Ollama，**think=false、temperature=0** | 改寫是短任務，不需要思考；開思考會多好幾秒，還可能輸出思考內容 |
| 7 | 資料不足的短路 | 改寫後檢索無候選，**仍然不呼叫回答 LLM** | Ch09 的原則不變；只是改寫 LLM 已經被呼叫過，紀錄要分開 |
| 8 | `llm_called` 的意義 | 維持「回答 LLM 是否被呼叫」，另加 `rewrite_called` | 不改既有欄位的意思，評估報告和 log 才能和前幾章比較 |
| 9 | Window 大小 | `window_turns = 3`（一輪 = 一問一答） | 起始值，用實驗 D 調整；三者共用 num\_ctx 8192，歷史不能吃掉 Context |

## 四、評估方式與實驗

本章的主要成果是一份**多輪測試集**，以及三組對照的 Recall@K 與 MRR。所有組別的 Reranker 設定必須相同，並在報告中註明（Ch12 收尾時的提醒）。

### 多輪測試集 `tests/rag-set/conversations.jsonl`

每題包含固定的歷史（使用者問題 + 腳本化的助理回答）、最後的追問，以及預期文件與條號。歷史用腳本寫死，不用模型即時生成，評估才可重現。

| 題型 | 範例 | 考驗 |
| --- | --- | --- |
| 省略 | 特休規定 →「那兩年年資呢？」 | 規範驗收第 4 項，本章主角 |
| 指代 | 事假規定 →「它會扣薪嗎？」 | 把「它」補成事假 |
| 回指較早輪次 | 第 1 輪問特休，第 2 輪問病假，第 3 輪「剛剛第一個問題的遞延呢？」 | 檢驗 Window 大小 |
| 話題切換 | 特休規定 →「識別證遺失怎麼辦？」 | 改寫不能把特休塞進新問題 |
| 本來就完整 | 任意歷史 → 一題完整問題 | 改寫後意思不能變 |
| 精確編號追問 | 前一輪提到某編號 →「那這張單的處理期限呢？」 | 編號必須原樣保留，Ch11 的關鍵字檢索才找得到 |
| 無答案追問 | 特休規定 →「那可以換成現金給家人嗎？」 | 改寫後仍要回答資料不足 |

建議 12～15 題，題目與預期答案須對照原文，先給你確認再寫入。

### 實驗

1. **三組對照（主實驗）**：A 不改寫、B 把歷史使用者問題串在追問前面、C LLM 改寫。比較 Recall@1/5、MRR、無答案題、平均延遲。B 是很好的教學對照：通常比 A 好，但在「話題切換」題會被舊話題拖走。
2. **改寫忠實度**：輸出每題改寫後的問題，人工判讀三件事：有沒有補對、有沒有改變原意、有沒有把上一輪的答案內容帶進問題。
3. **改寫模型對照**：Ollama（qwen3:8b，think 關閉）與 OpenAI 各跑一次 C，比較忠實度與延遲。
4. **Window 大小**：N = 1、3、5 跑「回指較早輪次」題，觀察 N 太小時的失敗。
5. **回答階段的問題**：回答時只送原始問題，或同時附上改寫後問題，用 `rag:eval-answer` 類的端到端流程比較回答品質（可列為選做）。
6. **單輪回歸**：`questions.jsonl`、`hybrid.jsonl`、`reasoning.jsonl` 在相同 Rerank 設定下，結果必須與 Ch12 相同。

## 五、交接給 Agent CLI

以下依序執行：前置作業由你完成，其餘交給 Agent。Agent 在測試集草稿完成後必須先停下來，等你確認題目。

### 5.1 前置作業（你自己做）

- [ ] 確認 `ch12-done` 已 commit 並打 tag
- [ ] `.env` 設定 `RAG_RERANK_ENABLED=false`，llama-server 可以先不開（開發期專心看改寫效果）
- [ ] 執行 `bash scripts/check-env.sh`，Reranker 項目顯示未啟用即可

### 5.2 範圍

**本章做**：對話紀錄保存、Sliding Window、Query Rewriting、降級、多輪測試集與評估指令。

**本章不做**：UI 與 Streaming（Ch14）、Conversation Summary、Multi-query / HyDE、Agent / Tool Calling、調整門檻、Embedding、Hybrid 或 Rerank 參數、修改 Citation 規則。

### 5.3 執行項目

1. **設定**：`config/rag.php` 新增 `conversation` 區塊：`enabled`、`window_turns`（3）、`rewrite.provider`、`rewrite.model`、`rewrite.timeout`、`rewrite.max_chars`、`rewrite.prompt_version`。全部由 `.env` 讀取，並同步固定到 `phpunit.xml`（Ch12 的教訓：測試不能受開發環境 `.env` 影響）。
2. **資料表**：`conversations`（id、created\_at、updated\_at）與 `conversation_messages`（conversation\_id、role、content、rewritten\_question、rewrite\_status、status、created\_at）。助理訊息保存原始回答。
3. **ConversationHistory**：依 conversation\_id 取出最近 N 輪；組歷史時移除回答中的 `[n]` 標記與來源清單。
4. **QueryRewriter**：
   - 沒有歷史時直接回傳原問題，`rewrite_status = skipped`，不呼叫 LLM
   - Prompt 存於 `resources/prompts/`，沿用 Ch10 的版本切換方式，起始內容依規範第十二階段
   - qwen3 帶入 `think: false`，temperature 0
   - 結果驗證：空字串、逾時、超過 `max_chars`、包含「資料不足」，一律降級為原問題，`rewrite_status = fallback`，寫 warning log
5. **RagAnswerService** 擴充：`conversation_id` 可省略；流程為「載入歷史 → 改寫 → 以改寫問題檢索（含 Hybrid 與 Rerank）→ 無候選則短路 → ContextBuilder → 回答 LLM」。回答 LLM 的 Messages = System + 最近 N 輪（已移除編號）+ 帶 `<reference>` 的 User 訊息與**原始問題**。
6. **RagAnswer** 新增欄位：`conversation_id`、`original_question`、`rewritten_question`、`rewrite_status`（skipped / rewritten / fallback）、`rewrite_called`、`rewrite_ms`、`rewrite_usage`。既有 `llm_called` 意義不變。
7. **API**：`POST /api/knowledge/ask` 接受選填的 `conversation_id`，未帶時建立新對話，回應帶回 `conversation_id`。不帶 conversation\_id 的舊呼叫方式必須照常運作。
8. **除錯指令**：`rag:ask` 新增 `--conversation=` 與 `--show-rewrite`；另建 `rag:chat` 在終端機連續提問。
9. **`rag_query_logs`** 新增：conversation\_id、original\_question、rewritten\_question、rewrite\_status、rewrite\_ms。
10. **評估指令 `rag:eval-conversation`**：讀取 `conversations.jsonl`，`--rewrite=none|concat|llm` 對應實驗 A/B/C，`--window=`、`--rewrite-provider=`、`--label=`；輸出每題改寫結果、Recall@1/5、MRR、延遲，並註明 Rerank 設定，結果寫入 `docs/notes/ch13-*.md`。
11. **測試**（Fake ChatProvider / Fake Retriever）至少包含：無歷史不呼叫改寫；改寫失敗降級；Retriever 收到的是改寫後問題；歷史不含 `[n]`；Window 只取 N 輪；改寫後無候選時不呼叫回答 LLM；不帶 conversation\_id 的舊 API 行為不變。

### 5.4 實驗

依第四節執行實驗 1～4 與 6，實驗 5 選做。所有組別使用相同 Rerank 設定；最後開啟 Reranker 再跑一次 C，作為完整系統的結果。

### 5.5 驗收

1. 「公司的特休規定是什麼？」→「那兩年年資呢？」能找到正確段落（規範驗收第 4 項）
2. 三組對照的 Recall@K、MRR、延遲已寫入 `docs/notes/`，並註明 Rerank 設定
3. 第一輪問題不呼叫改寫 LLM；單輪三份測試集的結果與 Ch12 相同
4. 改寫失敗時降級為原問題，問答不中斷，log 有紀錄
5. 送進改寫與回答的歷史不含 `[n]` 與來源清單
6. Window 大小由設定檔決定，實驗 4 有紀錄
7. 無答案追問仍回覆資料不足；改寫後無候選時不呼叫回答 LLM
8. 精確編號追問的改寫結果保留編號原樣
9. 切換回答或改寫的 Provider 時，業務層不需要修改
10. 全部測試與 pint 通過

### 5.6 回報（分兩次）

**第一次（停下來等確認）**：新增與修改的檔案及各 Class 責任；改寫 Prompt 全文；多輪測試集草稿（含預期答案與原文依據）。

**第二次**：三組對照比較表、改寫忠實度判讀表（每題改寫結果）、改寫模型對照、Window 實驗、單輪回歸結果，以及「多一次 LLM 呼叫值不值得」的判斷。

### 5.7 收尾（你確認後）

- `docs/notes/ch13-summary.md`
- `CLAUDE.md` 與 `AGENTS.md` 補上規則：檢索一律使用改寫後問題；歷史不得帶入引用編號；改寫 Prompt 修改後必須重跑 `rag:eval-conversation`
- commit 與 tag `ch13-done` 由你執行

### 5.8 可以直接貼給 Agent 的 Prompt

```
Ch13 Query Rewriting 與 Conversation Memory（對應規範第十二階段、Agent Prompt 7）

加入多輪對話 RAG：檢索前先以 LLM 將追問改寫為獨立問題，對話紀錄採 Sliding Window 保留最近 N 輪。
請依交接文件第 5.2～5.7 節執行，重點如下：

1. 對話紀錄存在 server 端（conversations、conversation_messages），API 的 conversation_id 為選填，舊呼叫方式不變。
2. 沒有歷史時不呼叫改寫 LLM；改寫失敗一律降級為原問題並寫 warning log。
3. 檢索（Dense、Keyword、Rerank）使用改寫後問題；回答 LLM 收到最近 N 輪歷史、參考資料與原始問題。
4. 歷史送進改寫與回答前，移除 [n] 標記與來源清單。
5. 既有 llm_called 意義不變，另加 rewrite_called、rewrite_status。
6. 新設定全部同步固定到 phpunit.xml。
7. 建立 rag:eval-conversation，支援 --rewrite=none|concat|llm。

本章不做：UI、Streaming、Conversation Summary、Multi-query、HyDE、調整檢索參數、修改 Citation 規則。

先完成程式、改寫 Prompt 與多輪測試集草稿（12～15 題，含預期答案與原文依據），回報後停下來，等我確認題目再執行評估。
```

## 六、常見陷阱

最容易被忽略的是第 1 和第 4 項：兩者都不會報錯，只會讓檢索結果悄悄變差。

1. **改寫把上一輪的答案帶進問題**：歷史含有助理回答，模型可能改寫成「年資兩年、特休可遞延的員工……」，檢索就被拉回上一輪的段落。忠實度判讀要特別看這一點。
2. **話題切換被舊話題污染**：使用者已經換主題，改寫卻硬補上特休。對照組 B（串接歷史）最容易出現，C 也要檢查。
3. **本來完整的問題被改壞**：改寫應該是「必要時才補」，不是每題都重寫。
4. **簡體字外洩到改寫結果**：bge-m3 大多還能處理，但 Ch11 的 MySQL ngram 全文檢索比對的是字元，簡體字直接找不到。改寫 Prompt 要明確要求繁體中文，評估時也要檢查。
5. **qwen3 預設開啟思考**：改寫會慢好幾秒，輸出也可能混入思考內容。一定要帶 `think: false`。
6. **歷史帶入引用編號**：上一輪的 \[2\] 和這一輪的 \[2\] 是不同段落，模型可能沿用舊編號，Ch10 的引用驗證就會失準。
7. **評估用即時生成的歷史**：每次跑出來的助理回答不同，改寫輸入就不同，結果無法比較。歷史要腳本化。
8. **兩組 Rerank 設定不同**：分不清改善來自改寫還是 Reranker。
9. **Token 預算被歷史吃掉**：歷史、參考資料、回答共用 num\_ctx 8192，Window 太大時參考資料會被 ContextBuilder 捨去，`dropped_chunks` 要一起看。
10. **新設定沒有固定在 phpunit.xml**：Ch12 已經發生過一次，這章新增的設定一樣要固定。

## 七、需要你決策的地方與下一章

交給 Agent 前，請先確認以下四點；不改的話就照建議執行。

| # | 決策 | 建議 | 另一個選項 |
| --- | --- | --- | --- |
| 1 | 對話紀錄存哪裡 | Server 端資料表 | Client 每次帶完整 Messages（沿用 Ch02 `/api/ai/chat` 的格式，較簡單，但可被偽造，Ch14 要再補） |
| 2 | 回答階段送原始問題或改寫問題 | 依規範送原始問題，實驗 5 再決定 | 同時附上改寫後問題，讓回答 LLM 也看到補完的主題 |
| 3 | 改寫模型 | 預設 Ollama，實驗 3 和 OpenAI 對照 | 跟著回答的 Provider 走（雲端回答時改寫也走雲端） |
| 4 | 是否做對照組 B（串接歷史） | 做，成本很低，教學價值高 | 只做 A 與 C |

### 下一章

Ch14 UI 與最終驗收：建立 `/document`、`/document/upload`、`/knowledge/chat` 三個頁面，`/knowledge/chat` 直接使用本章的 conversation\_id；並逐項確認規範第二十節的驗收清單。本章的 Server 端對話紀錄，就是 Ch14 聊天頁的資料來源。
