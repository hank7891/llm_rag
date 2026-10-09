# 待解決清單

實作過程中發現、但刻意延後處理的項目。處理完畢就從清單移除，並在 commit 說明中註明。

## 待處理

| # | 項目 | 發現於 | 影響 | 處理方向 |
| --- | --- | --- | --- | --- |
| 1 | 串流中途被使用者關閉時，不會記錄 Token 用量 | Ch02、Ch14 | 成本統計會少算被中斷的請求。Ch14 起使用者可在問答頁按「停止」或關閉頁面：`ChatService` 在最後一段（帶 usage）才記錄用量，中斷時沒有最後一段，雲端 Provider 的成本統計會偏低；`rag_query_logs` 中 interrupted 的紀錄 input / output tokens 也是 null | `ChatService::loggedStream()` 以 `finally` 補記一筆「未完成」：輸入以送出的訊息估算、輸出以已收到的文字估算並標記為估計值；或改用供應商的用量查詢 API 對帳 |
| 2 | 文件可能永久停在 `parsing` | Ch03 | Job 的 `failed()` 寫入資料庫時剛好失敗、或 Worker 被強制終止（SIGKILL）時，文件停在 parsing，UI 也無法重新處理 | 加一個排程指令：找出停在 parsing 超過 Job timeout 且 Queue 裡已沒有對應 Job 的文件，標記為 failed（Codex 審查提出） |
| 3 | `OLLAMA_TRUNCATE` 預設改為 false | Ch04 | 目前為了觀察截斷維持 Ollama 預設的靜默截斷；實際問答時截斷會連 System Prompt 一起砍掉，回答看起來像「文件裡沒有」 | Ch05 之後的 RAG 章節送出的內容可控，改為 false，讓超過上限直接報錯 |
| 5 | 重複文件佔用檢索名額 | Ch07 | 內容相同的文件（sha256 相同）分數完全一樣，Top-5 只有 3 筆不同內容 | Ch08 檢索結果依 sha256 或 chunk 內容去重，或上傳時阻擋重複 |
| 6 | 重建索引時文件短暫搜尋不到 | Ch07 | `ChunkIndexer` 先刪舊 Points 再 upsert（向量已先算好，空窗約為一次 HTTP 寫入）；這段期間搜尋不到該文件 | 需要不中斷時，改為先寫新 Points 再刪「document_id 相同但 chunk_id 不在新清單內」的舊 Points |
| 7 | qwen3-embedding 的 Instruct 前綴改用中文任務描述 | Ch08 | 目前使用模型說明的英文預設（web search query）；換句話說題 Recall@1 只有 58%，可能部分來自前綴不符合任務 | 改為描述本系統任務的中文 Instruct，以 `rag:eval --model=qwen3-embedding:0.6b` 重新比較 |
| 8 | 門檻 0.59 為暫定值 | Ch08 | 測試集只有 76 個 Chunk、24 題有答案題，且 #14 含大量標題正確、內容重複的填充條文；文件變多後無關內容拿到高分的機會也變多 | 放入真實文件後重跑 `rag:eval`（可用 `--threshold=` 掃描）重新校準 |
| 9 | 依實際問答紀錄重新校準門檻 | Ch08 | 門檻 0.59 只依測試集決定；Ch09 已建立 `rag_query_logs`，記錄每次提問的 Top-1 分數（含被門檻擋下的分數）與是否通過 | 實際問答累積後，以 `source_key != eval` 的紀錄觀察分數分布，重新校準門檻 |
| 10 | 為「資料中未提及」標引用 | Ch09 | Ch10 已處理過度引用（gpt-4.1-mini r01 四筆 → 兩筆）與 `[資料不足]` 格式錯誤；剩下 qwen3:8b 為「未提及是否影響年終獎金」標 [2][3][4]，平均引用數不降反升 | 觀察實際問答是否常見；需要時在 Prompt 補充「沒有依據的句子不要標引用」，修改後重跑 `rag:eval-answer` |
| 11 | qwen3:8b 偶爾輸出簡體字 | Ch09 | 30 題中 1 次（「携带的身份证件」），實驗中 1 次（「费率」）；System Prompt 已要求繁體中文 | 先記錄；若要修，只調整 Prompt，不加轉換後處理，修改前先與使用者確認 |
| 12 | `insufficient_by_llm` 仍是字串判斷 | Ch09 | Ch10 改為「以資料不足開頭」並以 Prompt v2 要求拒答只回「資料不足」，43 題中已無誤判；但換句話說的拒答（「無法回答」）仍判斷不到 | 需要更可靠時，改為要求模型輸出結構化欄位（例如 sufficient: true/false） |
| 13 | 回答結果有隨機性（回答 LLM 未設定 temperature） | Ch10、Ch13 | 同一題同一 Prompt 每次結果不同（qwen3:8b r01：Ch09 錯、Ch10 v1 對、v2 錯），單題差異無法歸因。Ch13 `rag:chat` 實例：「公司的特休規定是什麼？」→「那兩年年資呢？」兩次實跑，改寫與檢索都相同（管理辦法第十二條第 1 名），回答一次是「十日」（正確）、一次是「服務滿一年以上未滿二年者，給予七日」（錯誤）；不只影響評估，正式問答也會給出不同結論。Ch13 的改寫已固定 temperature 0 | 回答 LLM 固定 temperature（例如 0）；評估時或同一設定重複執行取多數，比較 Prompt 前後才有意義 |
| 14 | Qdrant BM25 Sparse Vector（Hybrid 方案 B） | Ch11 | 本章主線採 MySQL FULLTEXT + 應用層 RRF；Qdrant 1.19 可在伺服器端產生 `qdrant/bm25` 稀疏向量並以 prefetch + fusion 合併，但中文斷詞效果未實測 | 先以 5～10 題中文問題實測斷詞，再用同一份測試集與主線比較 |
| 15 | 專有名詞（人名、單位名稱）在 Hybrid 仍找不到 | Ch11 | 抽不出精確詞、Dense 分數不夠時，資料不足的判斷不看一般關鍵字，`allow` 也救不回來（p01～p04 所有模式皆無候選） | 評估以專有名詞清單（人名、單位）擴充精確詞，或為一般關鍵字設計可靠的通過條件；需同時觀察無答案題 |
| 16 | 日期類精確字串不在精確詞規則內 | Ch11 | q24「115 年 3 月 18 日」在所有模式都被擋下 | 評估加入日期格式的抽取規則（正規化為統一寫法），並確認不會讓「10 天」這類一般數字被當成精確詞 |
| 17 | 以 `rag:eval-answer` 端到端比較 rerank on / off 的回答品質 | Ch12 | Reranker 讓 Recall@1 由 79% 升到 96%，但 Recall@5 不變（LLM 拿到的段落集合相同、只有順序改變）；排序對回答品質的影響（Lost in the Middle、引用的第一筆是否正確）未驗證 | 同一份測試集、同一個 Provider，以 `RAG_RERANK_ENABLED=true / false` 各跑一次 `rag:eval-answer`（建議同時固定 temperature，見 #13），比較答對率（人工判讀）、引用命中率與平均引用數 |
| 18 | llama-server 需要手動啟動 | Ch12 | 沒有啟動時檢索靜靜地降級，品質退回 Ch11 | 需要長期使用時，以 launchd（LaunchAgent）或 Docker（需確認 arm64 與 GPU）常駐，並在 `rag:index-check` 類的檢查指令中加入服務狀態 |
| 19 | 長回答時 `history_budget_chars` 比 `window_turns` 先截斷 | Ch13 | `rag:chat` 實測（conversation 4）：每輪回答約 400～600 字時，第 5 輪的 4 輪歷史約 1,700 字，超過 1,500 字預算，Window 5 只保留 3 輪，最舊的一輪整輪被丟掉；只記在 `conversation.history` log，回指被丟掉的輪次時會靜默失敗 | 完整保留使用者問題（通常很短、指代多半指向它），只截短助理回答；或改為依輪數平均分配預算。修改後重跑 `rag:eval-conversation`，並用長回答的對話實測保留輪數 |
| 20 | 重新載入問答頁後無法恢復對話 | Ch14 | 為避免「保留 conversation_id 卻看不到先前訊息、下一題被當成追問改寫」，`/knowledge/chat` 重新載入即開新對話；使用者無法回到先前的對話 | 新增讀取對話訊息的端點（只回傳 server 端保存的內容，不接受 Client 傳入歷史），頁面載入時依 conversation_id 恢復畫面；interrupted 的輪次要標示出來 |
| 21 | 回答以 Markdown 顯示 | Ch14 | 目前以純文字顯示（保留換行），模型輸出的清單與粗體符號會原樣出現；純文字沒有 XSS 風險 | 引入 Markdown 轉換時必須先經過消毒（sanitize，例如 DOMPurify），並加測試：含 `<script>`、`onerror` 的文件與回答不會被執行 |

## 暫不處理（有需求時再評估）

| 項目 | 發現於 | 目前狀況 | 何時需要處理 |
| --- | --- | --- | --- |
| API 沒有驗證機制 | Ch02 | 系統只在內部使用，不對外開放 | 有對外開放需求時，連同資料權限一起整理 |
| 錯誤訊息回傳上游細節 | Ch02 | 內部使用，保留細節方便除錯；API Key 已遮蔽 | 對外開放時改為只回傳錯誤類型，細節留在 log |
| Anthropic Provider | Ch02 | 設定與程式皆已移除 | 需要時依 OpenAI / Gemini Provider 的模式新增 |
| qwen3 思考內容不顯示 | Ch02 | `think` 預設關閉；開啟時思考內容直接捨棄 | 需要顯示推理過程時，在 `ChatResult` / `StreamChunk` 加欄位 |
| Provider 每次呼叫都建立新實例與連線 | Ch01 | 實測雲端兩輪約 3 秒，看不出連線成本 | 呼叫量變大或延遲明顯時，改為 singleton 或 Laravel Manager 模式 |
| Gemini 改用 Interactions API | Ch02 | 目前使用 generateContent，官方錯誤訊息推薦較新的 Interactions API | generateContent 被停用或需要新功能時，只需修改 `GeminiProvider` |
