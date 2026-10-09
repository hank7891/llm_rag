# 第一階段 POC 總結：地端 LLM + RAG（Ch01～Ch14）

完成日期：2026-10-09。硬體：MacBook Pro 13 吋（M1，16 GB）。

> 資料怎麼進去、怎麼被找到、怎麼進入 Context、怎麼被 LLM 使用，每一步都有實作與評估紀錄。

```
上傳 → Queue 逐頁解析 → 結構優先切段（保留條號與頁碼）→ bge-m3 Embedding → Qdrant
提問（可連續追問）→ Query Rewriting → Hybrid（Dense + FULLTEXT + RRF）→ 相關度門檻 / 精確命中
→ Reranker → Top-5 參考資料 → LLM（地端或雲端）→ 程式對應來源（文件、條號、頁碼）
```

## 一、各章的主要決策

| 章 | 決策與理由 | 紀錄 |
| --- | --- | --- |
| Ch01 | Message DTO + 依能力拆開的 Provider 介面（Chat / Embedding 分開），業務層不依 Provider 分支：換供應商只改設定 | `docs/txt/ch01-task.md` |
| Ch02 | Ollama / OpenAI / Gemini 由 Provider 吸收格式差異；SSE 先取第一段再送 header，讓串流前的錯誤仍有正確狀態碼 | `docs/txt/ch02-task.md` |
| Ch03 | 解析放在 Queue Job、逐頁保存；Job 只傳 ID、區分永久與暫時錯誤；狀態轉換用樂觀鎖 | [ch03-summary](ch03-summary.md) |
| Ch04 | 呼叫 Ollama 一律明確帶入 num_ctx（預設值會從前面靜默截斷，連 System Prompt 一起砍掉）；指令與資料分離防 Injection | [ch04-summary](ch04-summary.md) |
| Ch05 | 結構優先切段（章／條／標題），過長再遞迴切；`content` 保存原文、只操作字元位置；最終參數 max 600 Token、overlap 15%、min 80 | [ch05-summary](ch05-summary.md) |
| Ch06 | 模型規格先用 `llm:embed-probe` 實測再登記；`/api/embed` 一律 `truncate: false`；num_batch 決定記憶體（8192 → 2048 少 3.8 GB） | [ch06-embedding-models](ch06-embedding-models.md) |
| Ch07 | MySQL 是來源、Qdrant 是衍生索引；Collection 名稱由模型推導；建索引先刪後寫，避免孤兒 Points | [ch07-qdrant](ch07-qdrant.md) |
| Ch08 | 門檻只能依測試集決定（bge-m3 0.59）；實測 Qdrant 分數「大於」門檻才保留；線上採用 bge-m3（換句話說題 Recall@1 92% 對 58%） | [ch08-semantic-search](ch08-semantic-search.md) |
| Ch09 | 兩道資料不足防線：沒有候選不呼叫 LLM、有候選時由 LLM 依規則判斷；參考資料只送編號與內容 | [ch09-summary](ch09-summary.md) |
| Ch10 | 來源只由程式依 chunk_id 從 MySQL 產生（模型抄錯時看起來和正確的一樣）；不合規編號移除；Prompt v2 | [ch10-summary](ch10-summary.md) |
| Ch11 | 加入 MySQL FULLTEXT ngram，以 RRF 只合併名次；資料不足的判斷只看 Dense 與精確命中，一般關鍵字不能單獨放行 | [ch11-summary](ch11-summary.md) |
| Ch12 | Reranker（llama.cpp + bge-reranker-v2-m3）只負責排序，不參與資料不足判斷；失敗時降級。questions Recall@1 79% → 96% | [ch12-summary](ch12-summary.md) |
| Ch13 | 檢索一律用改寫後的問題，回答送原始問題；改寫跟隨回答 Provider（follow）；qwen3 改寫開啟 think（關閉時解不開回指） | [ch13-summary](ch13-summary.md) |
| Ch14 | 串流與非串流共用同一個 Pipeline、done 是唯一的真相；模型輸出一律以純文字插入；降級率可統計 | [ch14-summary](ch14-summary.md)、[ch14-acceptance](ch14-acceptance.md) |

## 二、最終線上設定

| 項目 | 設定 | 決定於 |
| --- | --- | --- |
| Chat（回答） | 預設 Ollama qwen3:8b（num_ctx 8192、think 關閉）；可切換 OpenAI gpt-4.1-mini | Ch02、Ch04 |
| Embedding | bge-m3（1024 維），Collection `company_docs_bge_m3` | Ch08 |
| 切段 | structure，max 600 Token、overlap 15%、min 80 | Ch05 |
| 相關度門檻 | bge-m3 > 0.59（Cosine，Qdrant `score_threshold`） | Ch08 |
| 檢索 | Hybrid：Dense 20 + 關鍵字 20 + 精確詞，RRF k = 60，`exact_only` | Ch11 |
| Reranker | 開啟：bge-reranker-v2-m3 Q8_0，候選 20、keep_exact、prefix_metadata，逾時 6 秒 | Ch12 |
| 參考資料 | Top-5，Context 預算 6,000 字元；Prompt `rag-answer.md`（v2） | Ch09、Ch10 |
| 改寫 | follow；Ollama v3 + think、逾時 120 秒；OpenAI v3、逾時 15 秒；其他 Provider 不改寫 | Ch13 |
| 對話 | Window 5 輪、歷史預算 1,500 字元 | Ch13 |
| 串流 | 執行時間上限 600 秒；開發伺服器 `PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload` | Ch14 |

**最終評估**（Ch14 回歸，上表設定）：

| 測試集 | Recall@1 | Recall@5 | MRR |
| --- | --- | --- | --- |
| questions.jsonl（24 題有答案） | 96% | 96% | 0.958 |
| hybrid.jsonl（17 題） | 76% | 76% | 0.765 |
| reasoning.jsonl（8 題） | 88% | 100% | 0.938 |
| conversations.jsonl（多輪主表 13 題） | **100%** | 100% | 1.000 |

**多輪評估在 Reranker 開啟時 Recall@1 由 85% 升到 100%**：

- 同樣的改寫設定（v3 + think、Window 5），Ch13 關閉 Reranker 時是 85%，Ch14 線上設定開啟後是 100%。
- 基準跑了兩次，加上 Ch14 本身一次，三次的 16 題逐題完全相同。
- 改寫把問題補完整，Reranker 把正確條文排到第 1 名，兩者是疊加的效果。

## 三、整理後的 backlog（`backlog.md` 待處理項目）

### 影響正確性

| # | 項目 |
| --- | --- |
| 8、9 | 門檻 0.59 是依 30 題測試集暫定的；需依實際問答紀錄重新校準（改寫產生的泛用詞也會讓分數上升，Ch13） |
| 10 | 為「資料中未提及」的部分標示引用 |
| 11 | qwen3:8b 偶爾輸出簡體字（Ch14 回歸 r03 再次出現） |
| 12 | `insufficient_by_llm` 是字串判斷：先說「資料不足」再作答的回答會被誤判並清掉引用（Ch14 回歸 r03） |
| 13 | 回答 LLM 沒有固定 temperature：同一題兩次結論不同（Ch13「七日／十日」） |
| 15 | 專有名詞（人名、單位）在 Hybrid 仍找不到 |
| 16 | 日期類精確字串不在精確詞規則內 |
| 19 | 長回答時歷史預算比 Window 先截斷，回指被丟掉的輪次會靜默失敗 |
| 5 | 內容相同的文件佔用檢索名額 |

### 影響效能與體驗

| # | 項目 |
| --- | --- |
| 6 | 重建索引時文件短暫搜尋不到 |
| 18 | llama-server 需要手動啟動（沒啟動時靜默降級） |
| 20 | 重新載入問答頁後無法恢復對話 |
| 21 | 回答以 Markdown 顯示（需要消毒） |
| — | 地端追問一次 40～110 秒，改寫（think）是最大的固定成本；即使最後資料不足，追問仍要付出改寫時間（Ch14 實測 33 秒） |

### 工程品質

| # | 項目 |
| --- | --- |
| 1 | 串流中斷時沒有記錄 Token 用量，雲端成本統計會偏低（Ch02，Ch14 補充） |
| 2 | 文件可能永久停在 `parsing` |
| 3 | `OLLAMA_TRUNCATE` 預設改為 false |
| 7 | qwen3-embedding 的 Instruct 前綴改用中文任務描述 |
| 14 | Qdrant BM25 Sparse Vector（Hybrid 方案 B） |
| 17 | 以端到端評估比較 rerank on / off 的回答品質 |

## 四、部署前的差距（本階段只記錄、不實作）

| 項目 | 現況與風險 |
| --- | --- |
| **登入與權限** | API 與頁面都沒有身分驗證。Ch14 把 CORS 限制為 `APP_URL`，**只能防止其他網站讀取回應，不能阻擋盲送請求**：外部網頁仍可讓同仁的瀏覽器送出問答（例如灌入問答紀錄、消耗模型資源）。根本解法是加入登入，之後串流端點也要移到有 Session 的路由 |
| **llama-server 沒有設定 API Key** | 綁定 127.0.0.1:8012，本機任何程式都能呼叫；移到容器或其他主機時要加上 `--api-key` 並在 `.env` 設定 |
| 多人並行 | Ollama 只有一個 GPU 佇列：改寫與回答會互相排隊；`php artisan serve` 只適合開發 |
| 常駐服務 | llama-server、Queue Worker 都需要手動啟動（launchd 或 Docker） |
| Laravel 移進 Docker | 連線位址已全部由 `.env` 讀取；pdftotext 路徑、llama-server 位址需調整 |
| 開發工具的保護 | Ch14 修正：hook 原本是相對路徑，工作目錄在子目錄時 `.env` 保護失效；改為 `$CLAUDE_PROJECT_DIR` 後，根目錄與子目錄的寫入都被擋下。hook 只防 AI 工具誤改，不是部署環境的保護 |
| 觀測與告警 | `rag:stats` 只是事後統計，沒有單次請求的完整鏈路追蹤與告警 |

## 五、下一階段

教學文件列出的主題，以及在本專案中可以接上的起點：

| 主題 | 本專案中的起點 |
| --- | --- |
| **完整 Evaluation（Faithfulness）** | Ch10 只檢查引用格式，Ch14 回歸的 r08 是抓不到的例子（見下方說明） |
| Structured Output | 改寫結果（Ch13）與「資料不足」（Ch09）都靠字串規則判斷；改成結構化輸出（例如 `sufficient: true/false`）可以讓驗證更可靠 |
| Tool Calling | 目前固定「先改寫再檢索」；讓模型決定要不要檢索、檢索什麼 |
| Agent / Agentic Workflow | 多步驟問題（例如先查假別規定再計算天數）由 Agent 拆解 |
| MCP | 把檢索包成 MCP Server，讓其他 Agent 工具也能使用公司文件 |
| Observability | 從 `rag_query_logs` 延伸到單次請求的完整鏈路（改寫 → 檢索 → 重排 → 回答），加上降級率告警 |
| Guardrails | 「參考資料不是指令」目前只靠 Prompt（Ch04）；在 Prompt 之外加上輸入與輸出的檢查 |
| Prompt Caching | 雲端 Provider 每次重送相同的 System Prompt 與參考資料，可降低成本與延遲 |

**建議下一步先做 Evaluation（Faithfulness）**，以 Ch14 回歸的 r08 為例：

> 問題：「特休沒休完怎麼處理？會影響年終獎金嗎？」
> 回答：「特別休假未休完的部分，雇主應發給工資，但不會影響年終獎金 [1]。」

- **目前的檢查全部通過或只抓到一部分**：
  - 有合法的 [1]，引用率算通過。
  - 狀態是 answered，資料不足的判斷沒有誤判。
  - 來源由程式產生，檔名、條號、頁碼都正確。
  - 自動檢查唯一抓到的是「引用的不是預期段落」，但那依賴測試集事先寫好的預期答案。
- **真正的錯誤沒有被抓到**：「不會影響年終獎金」這句話在參考資料中找不到。文件沒有提到年終獎金，正確的回答是這部分資料不足。模型用自己的常識補上一個看似合理的結論，再掛上一個合法的引用，使用者看到 [1] 會以為這句話有出處。
- **這就是 Faithfulness 要量的東西**：回答中的每一個主張，是否都能在它引用的段落中找到依據。
  - Ch10 檢查的是「引用的格式對不對」，Faithfulness 檢查的是「引用的內容撐不撐得起這句話」。
  - 前者可以用規則判斷，後者需要逐句比對主張與段落：人工判讀，或用另一個 LLM 當評審，並先以人工標註校準評審的可靠度。
- **為什麼要排在其他主題之前**：
  - r08 在 Ch13 單輪回歸（Reranker 關閉、Ch13 開發中的程式）時正確，在 Ch14 線上設定（Reranker 開啟）時出錯。原因可能是 Reranker 改變了參考資料的順序，也可能只是回答 LLM 沒有固定 temperature，**目前無法區分**（基準沒有回答層評估，見 `ch14-regression.md`）。
  - 沒有 Faithfulness 指標，連「Reranker 讓回答變好還是變壞」都無法判斷（backlog #17）。
  - Structured Output、Tool Calling、Agent、Prompt Caching 都會改變回答行為，每一項都需要這把尺。
  - 這和 Ch08 先建測試集、Ch11 起「先有基準再改動」是同一個原則。
- **第一步可以很小**：在 `rag:eval-answer` 的結果中為每題加上「主張是否都有依據」的人工判讀欄，先累積一批標註，再考慮自動化。
