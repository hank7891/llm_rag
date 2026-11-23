# Ch15 交接文件：用真實文件重新校準（選舉法規）

> 給 Coding Agent（Claude Code / Codex）。完整背景見 `ch15-教學與執行說明.md`。
> 前置 tag：`ch14-done`　完成 tag：`ch15-done`　分支：`ch15-real-docs`

## 0. 一句話

換上 7 部台灣選舉／罷免／公投法規，**不改任何檢索與生成設定**，量出解析、切段、檢索、門檻在新資料上的真實表現，最後由使用者決定新門檻。

## 1. 已決定事項（不要更動）

- 文件：`laws/manifest.csv` 列的 7 部法規，版本日期 2026-11-23。標準答案一律以 `laws/*.md` 為準，**不得從網路補資料、補題目或補答案**
- 上傳格式：官方 PDF（使用者自行下載上傳）。`laws/*.md` 只作比對與評分依據
- 模型與參數維持第一階段最終線上設定：bge-m3、Qdrant Collection `company_docs_bge_m3`、Hybrid RRF k=60、bge-reranker-v2-m3 候選 20 → Top 5、qwen3:8b num_ctx 8192
- Chunking、System Prompt、Query Rewriting、CitationResolver **都不改**
- 測試集來源：`testset/ch15_election_testset_v1.jsonl`；定稿後放到 `tests/rag-set/v2-election/` 並凍結
- 報告放 `docs/notes/ch15-*.md`；原始數據存 JSON，報告只放摘要表

## 2. 停止點（到了就停，回報後等使用者決定）

| # | 時機 | 要使用者決定的事 | 你要先準備的資料 |
| --- | --- | --- | --- |
| ① | 動手前 | 第一階段的人資文件要保留在知識庫裡，還是先移除（只留選舉法規做校準） | 目前文件清單、Qdrant points 數；**不要自行刪除任何文件** |
| ② | 解析檢查後 | 解析問題是否要在本章修，或改用 .md／.txt 上傳先跑完評估 | coverage 檢查輸出、5 個抽樣 Chunk 與 `laws/*.md` 的逐字比對 |
| ③ | Chunking 量測後 | 「（刪除）」條文、超長條文要不要處理（預設：只記錄，不處理） | Chunk 統計表、被切多段的條文清單 |
| ④ | 測試集轉換後 | 測試集定稿（使用者會增修題目） | 轉換後的檔案、條號對應規則、對不上的題目清單 |
| ⑤ | 門檻掃描後 | 新門檻值 | 掃描表、兩群分數分布、重疊區間內的題目 |

## 3. 工作項目

### 3.1 準備

1. 從 `ch14-done` 開分支 `ch15-real-docs`
2. 用 v1 測試集（第一階段 30 題）跑一次 `rag:eval`，結果存 `docs/notes/ch15-baseline-v1.md`
3. 列出目前知識庫文件與 Qdrant points 數 → **停止點 ①**

### 3.2 解析與覆蓋檢查（使用者上傳且全部 `indexed` 後才開始）

1. 從 MySQL 匯出所有 Chunk 為 JSON：`[{document_name, section, content, page_start, page_end}]`
2. 執行 `python3 scripts/check_article_coverage.py chunks.json laws/expected_articles.json`，輸出原文貼進報告
3. 抽 5 個 Chunk（至少 2 個跨頁條文）與 `laws/*.md` 對應條文逐字比對，列出差異（頁首頁尾、斷行、全形半形、亂碼）
4. → **停止點 ②**

### 3.3 Chunking 量測（只量，不改）

輸出 `docs/notes/ch15-chunking-report.md`：

- Chunk 總數、各法規 Chunk 數
- 字數與實際 Token 數分布（p50／p95／max），Token 數用 bge-m3 實際計算，不用字數推估
- 超過 Reranker 最大長度的 Chunk 清單（會被靜默截斷）
- 被切成多段的條文清單
- section 欄位格式抽樣（章／節／款是否保留、`63-1` 如何儲存）
- 「（刪除）」或少於 20 字的 Chunk 清單
- 是否有以「第 X 款」誤切的情形（注意：公職選罷法第三章第九節底下有「第 一 款 罷免案之提出」這種「款」層級標題，條文內也有「第一款」列舉）
→ **停止點 ③**

### 3.4 測試集轉換

1. 把 `ch15_election_testset_v1.jsonl` 轉成 `rag:eval` 吃的格式，放到 `tests/rag-set/v2-election/`
2. `expected[].article` 用 `63-1` 形式，請寫一個正規化函式對應到系統 section 欄位（可參考 `check_article_coverage.py` 的 `norm()`），**不要改測試集內容**
3. `expected[].doc` 對應到 document_name（注意「公職人員選舉罷免法」是「公職人員選舉罷免法施行細則」的子字串，比對要取最長相符）
4. 評分規則：
   - Recall@k：`expected` 任一命中即算
   - 另記 All@k：`expected` 全部命中（multi_hop 用）
   - `answerable = no`：看是否被門檻擋下
   - `answerable = partial`：檢索要命中所列條文，單獨列表，不納入門檻選值
5. → **停止點 ④**

### 3.5 基準評估

三組設定，除列出的開關外完全相同，門檻**暫不套用**（要看原始分數）：

| 組 | 檢索 | Reranker |
| --- | --- | --- |
| E1 | Dense | 關 |
| E2 | Hybrid | 關 |
| E3 | Hybrid | 開 |

每組輸出（`docs/notes/ch15-eval-report.md`）：

- Recall@1、Recall@5、MRR，總表＋分類別表（8 類）
- **文件層級 Top-1 正確率**（Top-1 的法規名稱是否在 `expected[].doc` 裡）
- 混淆矩陣：期望法規 × Top-1 法規（重點看總統法、公職法、公投法之間）
- exact_ref 類逐題列出：簡稱（選罷法、公投法）與國字條號（第九十條）有沒有被精確詞抽取或 FULLTEXT 命中
- 檢索 p50／p95
- 原始逐題 Top-5（chunk_id、法規、條號、dense 分數、RRF 分數、rerank 分數）存 `docs/notes/ch15-eval-raw-E{1,2,3}.json`

### 3.6 門檻掃描

1. **先從程式確認 Gate 用的是哪個分數**（依 Ch11 設計應為 Dense），在報告寫明檔案與行號
2. 兩群分布：有答案題（`yes`）正確 Chunk 的 Gate 分數；無答案題（`no`）Top-1 的 Gate 分數
3. 掃描 0.40～0.75，間隔 0.01：每個值的「有答案題誤擋數」「無答案題放行數」
4. 標出符合「無答案擋下率 ≥ 90%」的最低誤擋值；兩群重疊時列出重疊區間與題目，**不要自己選定**
5. 舊門檻 0.59 在新資料上擋了誰、放了誰，逐題列出
→ **停止點 ⑤**

### 3.7 端到端抽測

使用者決定門檻後，以 E3＋新門檻，對 F01、F02、D11、H02、H04、H06、G04、C07 共 8 題，Ollama 與 OpenAI 各問一次。每題記錄：檢索到的條號、回答原文、`[n]` 對應的法規與條號、CitationResolver 顯示的來源字串、回答中是否出現文件以外的內容。**只記錄，不評分、不改 Prompt。** 輸出 `docs/notes/ch15-e2e-samples.md`。

### 3.8 收尾

1. 寫 `docs/notes/ch15-summary.md`（格式比照 ch00～ch14 的 summary）
2. 更新 backlog：本章發現但沒修的問題逐條列出
3. 規則補進 CLAUDE.md／AGENTS.md（見第 5 節）
4. commit，打 tag `ch15-done`

## 4. 明確不做

- 不修改 Chunker、Embedding、Reranker、Hybrid、Gate 的程式邏輯（門檻**數值**在停止點 ⑤ 之後由使用者決定才改）
- 不修改 System Prompt、Query Rewriting、CitationResolver
- 不做法規簡稱、國字條號的正規化或同義詞擴充（量出影響即可）
- 不做 Faithfulness 評分、LLM-as-Judge
- 不刪除任何文件或 Collection（包含 qwen3-embedding 舊 Collection）
- 不從網路抓資料、不新增法規、不自行增修測試題
- 不把不同設定或不同測試集的數字排在同一條序列比高低

## 5. 要補進 CLAUDE.md／AGENTS.md 的規則

```
- 評估一律使用 tests/rag-set/ 下已凍結的測試集；不得修改題目或期望答案
- 選舉法規的標準答案以 laws/*.md（2026-11-23 版）為準，不得引用網路或模型記憶
- 任何評估報告須寫明：測試集版本、檢索設定（Dense/Hybrid/Reranker）、門檻值、模型
- 比對法規名稱時取最長相符（主法名稱是施行細則名稱的子字串）
```

## 6. 回報格式

每個停止點回報都用這個結構：

1. **做了什麼**（3～5 行）
2. **結果**（表格，附原始輸出檔路徑）
3. **意外發現**（與預期不同、或看起來正常但可疑的地方）
4. **需要你決定的事**（選項＋各自代價，附你的建議與理由）
5. **下一步**（得到決定後要做什麼）

不要在回報裡寫「應該沒問題」「大致正常」，給數字或給證據。
