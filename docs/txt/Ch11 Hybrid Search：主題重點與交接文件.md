# Ch11 Hybrid Search：主題重點與交接文件

Oct 7, 2026 · @hank

## 一、本章目標與定位

本章在既有的語意檢索旁加上一條關鍵字檢索，再用 RRF 合併成單一排名，解決「精確字串、編號、條號查不準」的問題。對應教學文件第十階段（Hybrid Search）。

- **只動 Retriever**：ChatService、Context Builder、Citation 對應規則都不改。Hybrid 是「找」的強化，不是「答」的改變。
- **接手前提**（Agent 開工前先確認）：Qdrant Dense 檢索已可用並帶 `score_threshold`；`document_chunks` 已有 `page_start`、`page_end`、`section`；Qdrant Payload 的 `chunk_id` 等於 `document_chunks.id`；`tests/rag-set/` 已有語意題與無答案題。
- **本章不做**：Reranker、Query Rewriting、Payload Filtering 權限。Reranker 依教學順序是下一章。
- **完成標準一句話**：同一組測試題，以 `dense`、`keyword`、`hybrid` 三種模式各跑一次 Recall@K，數字記錄下來，而且 hybrid 在「精確字串題」明顯勝出、在「語意改寫題」不輸 dense。

## 二、核心知識點

語意檢索擅長「意思相近」，關鍵字檢索擅長「字面相同」，兩者的失敗情境剛好互補，所以合併使用。

### Dense 與 Sparse / Keyword 的分工

|  | Dense（bge-m3 + Qdrant） | Keyword（BM25 / FULLTEXT） |
| --- | --- | --- |
| 表示方式 | 1024 維稠密向量，每一維都有值 | 稀疏向量：只有出現過的詞有權重 |
| 擅長 | 換句話說：「假沒休完」找到「特別休假未休畢」 | 精確字串：`BUG-2026-000183`、`INV-887231`、「第 12 條」、人名、產品代號 |
| 弱點 | 編號、數字、罕見專有名詞容易被「語意平均」掉 | 同義詞、口語改寫完全找不到 |
| 分數意義 | Cosine 相似度，0～1，可設門檻 | 詞頻 × 稀有度，沒有固定上限，不同查詢之間不可比較 |

### BM25 的直覺

一個詞在這段出現越多次越相關（TF），但越常見的詞越不重要（IDF），段落越長越要打折（長度正規化）。MySQL InnoDB 的 FULLTEXT 相關度與 Qdrant 的 BM25 Sparse Vector 都是這個家族的做法。

### 為什麼用 RRF，而不是把分數相加

Dense 的 0.78 與 FULLTEXT 的 12.4 尺度不同，直接相加或加權都沒有意義。RRF 只看名次：每筆結果在各清單的名次換算成分數再加總。

```latex
\mathrm{RRF}(d) = \sum_{i \in \{\text{dense},\,\text{keyword}\}} \frac{1}{k + \mathrm{rank}_i(d)}, \quad k = 60
```

只出現在一邊的結果，另一邊不計分。k 越大，名次之間的差距越小；60 是常用預設，本章不調。

小例子（k = 60）：

| Chunk | Dense 名次 | Keyword 名次 | RRF 分數 |
| --- | --- | --- | --- |
| #101 | 1 | 3 | 1/61 + 1/63 = 0.0323 |
| #205 | — | 1 | 1/61 = 0.0164 |
| #088 | 2 | — | 1/62 = 0.0161 |

兩邊都找到的 #101 排第一；只被關鍵字命中的 #205（例如精確編號）仍能排進前面，這就是 Hybrid 要的效果。

### 本章要能說清楚的名詞

Keyword Search、Full-text Search、BM25、TF-IDF、ngram Parser、Sparse Vector、Dense Vector、RRF、Fusion。

## 三、本專案的實作選擇

主線採用 **MySQL FULLTEXT（ngram）+ 應用層 RRF**；Qdrant BM25 Sparse Vector 列為延伸實驗，用同一組測試題比較。

&#91;embedded content: Hybrid 檢索流程 · 三路檢索、RRF 合併、資料不足 Gate\]

三路檢索並行，RRF 只負責排序；是否有資料由 Dense 門檻與精確命中決定，一般關鍵字結果不能單獨讓問題進入 LLM。

|  | A. MySQL FULLTEXT ngram（主線） | B. Qdrant Sparse Vector BM25（延伸） |
| --- | --- | --- |
| 中文分詞 | ngram parser 以固定長度切字，中文不需要分詞器 | 依 tokenizer 設定；中文斷詞效果需實測 |
| 合併位置 | Laravel 內自己寫 RRF，看得到每一步 | Qdrant Query API 以 prefetch + fusion 在伺服器端合併 |
| 資料同步 | Chunk 本來就在 MySQL，刪文件時自動一起消失 | Sparse 與 Dense 同在一個 Point，跟著既有同步流程 |
| 環境需求 | MySQL 8.4 已就緒，加一個索引即可 | Collection 需新增 sparse vector 設定並重建索引 |
| 學習價值 | RRF、ngram、分數尺度問題都親手處理 | 了解向量資料庫原生 Hybrid 的做法 |

選 A 為主線的理由：本章的學習重點是 RRF 本身，自己實作才看得到兩份清單如何合併；而且 ngram 對中文最可預期，不必先處理斷詞問題。

B 的背景：Qdrant 從 v1.15.2 起可在伺服器端把文字轉成 `qdrant/bm25` 稀疏向量（[Go 套件說明](https://pkg.go.dev/github.com/harsh04/bm25@v0.1.1)），Sparse Vector 需設定 `modifier: idf` 才會套用 BM25 的 IDF 權重（[Qdrant 全文檢索文件](https://qdrant.tech/documentation/search/text-search/full-text-search/index.md)）。專案目前是 Qdrant 1.19，版本足夠；中文能否正確斷詞必須先以 5～10 題實測再決定是否採用。

bge-m3 模型本身也能輸出 sparse 權重，但 Ollama 的 `/api/embed` 只回傳 dense 向量，若要用需另架服務，本章不採用。

## 四、關鍵設計決策

最大的風險是「資料不足」失效：中文 ngram 很容易因為「怎麼」「規定」這類常見二字詞命中大量段落，若關鍵字結果無條件進入 Context，無答案題會開始硬湊答案。以下六點是 Agent 實作時必須遵守的決策。

### 1. 資料不足的判斷（Gate）

- Dense 清單照舊套用 Qdrant `score_threshold`，只有通過門檻的 Chunk 算「Dense 命中」。
- 從問題中以規則抽出 **精確詞**：英數編號（如 `BUG-2026-000183`）、條號（第 N 條）、引號內字串。精確詞以 BOOLEAN MODE 片語查詢命中的 Chunk 算「精確命中」。
- **Dense 命中與精確命中都為空 → 直接回覆「資料不足」，不呼叫 LLM**，與既有行為一致。
- 一般關鍵字（NATURAL LANGUAGE MODE）的結果 **只參與排名，不單獨決定有無資料**。

### 2. 只被一般關鍵字找到的 Chunk 能否進 Context

做成設定 `keyword_only_policy`，兩種都要能跑測試集比較：

| 值 | 行為 | 取捨 |
| --- | --- | --- |
| `exact_only`（預設） | 只有精確命中才能以「僅關鍵字」身分進 Context | 無答案題最安全；人名等抽不出規則的詞可能漏掉 |
| `allow` | 一般關鍵字結果也可經 RRF 進入 Top-K | 召回較高；需觀察無答案題是否退步 |

### 3. 合併鍵與資料來源

- 兩份清單以 `document_chunks.id`（即 Qdrant Payload 的 `chunk_id`）對齊。
- 合併後的內容與 Metadata 一律依 ID 從 MySQL 取回，MySQL 是唯一事實來源；Citation 規則不變。
- 只取 `documents.status = indexed` 的 Chunk，避免檢索到索引進行中的文件。

### 4. 正規化必須兩邊一致

- 新增欄位 `document_chunks.search_text`，FULLTEXT 索引建在這欄，而不是 `content`。`content` 保持原文給 LLM 與 Citation 使用。
- `search_text` = 沿用 Ch02 Normalizer（全形轉半形、清多餘空白）＋ 移除中文與英數之間的空白 ＋ 英文轉小寫。
- **問題送進 FULLTEXT 前，必須經過同一個函式**。否則文件寫「第 12 條」、使用者打「第12條」，ngram 切出來的詞對不上。

### 5. 候選數量（全部放設定檔）

| 設定 | 預設 | 說明 |
| --- | --- | --- |
| `dense_candidates` | 20 | Dense 取前 N 筆（套門檻後） |
| `keyword_candidates` | 20 | 關鍵字取前 N 筆 |
| `rrf_k` | 60 | RRF 常數 |
| `final_top_k` | 5 | 合併後送進 Context 的筆數 |
| `retrieval_mode` | `hybrid` | `dense` / `keyword` / `hybrid`，評估時切換用 |

### 6. 保留除錯資訊

每筆結果都帶 `dense_rank`、`dense_score`、`keyword_rank`、`keyword_score`、`exact_match`、`rrf_score`。評估指令要能印出來，之後 Reranker 章節也會用到這些欄位比較排名變化。

## 五、測試集擴充與評估

測試集要新增「精確字串題」，否則看不出 Hybrid 的價值；三種模式用同一組題目跑，結果存檔比較。

### 新增題型（每類至少 5 題，加到 `tests/rag-set/`）

| 題型 | 範例 | 預期 |
| --- | --- | --- |
| 條號題 | 「第12條在講什麼？」（刻意不加空白） | keyword / hybrid 找到，dense 可能排不前 |
| 編號 / 代碼題 | 文件內實際出現的表單編號、專案代號 | 只有 keyword / hybrid 能穩定找到 |
| 專有名詞題 | 文件內的系統名稱、人名、單位名稱 | keyword 較穩；用來比較 `keyword_only_policy` |
| 語意改寫題（沿用） | 「我的假沒休完怎麼辦？」 | hybrid 不可比 dense 差 |
| 無答案題（沿用＋新增） | 含常見二字詞但文件沒有答案的問題 | 三種模式都必須回「資料不足」 |

編號題必須用文件中真實存在的字串；若現有文件沒有編號，補一份含編號的測試文件（例如假的表單清單 TXT）一起上傳索引。

### 評估指令輸出

- 每種模式記錄 Recall@5、Recall@20、無答案題正確率，依題型分組。
- 結果寫入 `docs/notes/ch11-eval.md`（或 CSV），附上當次設定值（`rrf_k`、候選數、`keyword_only_policy`、門檻）。
- 至少跑四組：`dense`、`keyword`、`hybrid + exact_only`、`hybrid + allow`。

## 六、交接給 Agent CLI

下方 Prompt 可直接貼給 Agent；任務清單照順序做，每一步完成就 commit，最後打 tag `ch11-done`。

### Agent Prompt

```
本章實作 Hybrid Search（Dense + Keyword + RRF），只修改檢索層，不要改動 ChatService、Context Builder 與 Citation 對應規則。

1. MySQL：在 docker compose 的 MySQL 啟動參數明確設定 --ngram_token_size=2 與 --innodb_ft_enable_stopword=OFF。新增 document_chunks.search_text 欄位，建立 FULLTEXT 索引 WITH PARSER ngram。
2. 建立 SearchTextNormalizer：沿用既有 Normalizer，另外移除中文與英數之間的空白、英文轉小寫。索引寫入 search_text 與查詢前都必須呼叫同一個函式。寫 Artisan 指令回填既有 Chunk 的 search_text。
3. 建立 RetrieverInterface，把既有向量檢索包成 DenseRetriever，新增 KeywordRetriever（NATURAL LANGUAGE MODE 取前 N 筆）與 ExactTermExtractor（以規則抽出英數編號、第 N 條、引號字串，用 BOOLEAN MODE 片語查詢）。
4. 建立 HybridRetriever：以 chunk id 合併，RRF k=60；依「Dense 命中或精確命中」判斷是否資料不足；支援 keyword_only_policy = exact_only | allow。合併後依 ID 從 MySQL 取回內容與 Metadata，只取 status = indexed 的文件。
5. 所有參數放 config/rag.php 並可由 .env 覆寫：retrieval_mode、dense_candidates、keyword_candidates、rrf_k、final_top_k、keyword_only_policy。
6. 每筆結果保留 dense_rank、dense_score、keyword_rank、keyword_score、exact_match、rrf_score。
7. 擴充評估指令：可指定 retrieval_mode 與 keyword_only_policy，依題型輸出 Recall@5、Recall@20 與無答案題正確率，寫入 docs/notes/ch11-eval.md。

完成後說明：RRF 為何不需要統一分數尺度、資料不足的判斷流程，以及四組評估結果的差異。
```

### 任務清單

- [ ] MySQL 啟動參數加入 `ngram_token_size=2`、`innodb_ft_enable_stopword=OFF`，重啟後以 `SHOW VARIABLES` 確認
- [ ] Migration：`search_text` 欄位 + FULLTEXT ngram 索引
- [ ] SearchTextNormalizer + 回填既有 Chunk 的 Artisan 指令；索引流程寫入新 Chunk 時同步產生 `search_text`
- [ ] RetrieverInterface、DenseRetriever（包裝既有邏輯）、KeywordRetriever、ExactTermExtractor
- [ ] HybridRetriever + RRF + 資料不足 Gate + `keyword_only_policy`
- [ ] `config/rag.php` 參數化，Service Container 依 `retrieval_mode` 綁定 Retriever
- [ ] 單元測試：RRF 計分、精確詞抽取、正規化（「第 12 條」與「第12條」結果相同）、編號查詢（含連字號）
- [ ] 測試集新增條號、編號、專有名詞、無答案題，必要時補一份含編號的測試文件
- [ ] 跑四組評估並寫入 `docs/notes/ch11-eval.md`
- [ ] 撰寫 `docs/notes/ch11-summary.md`（沿用 Ch00 篇章總結格式）
- [ ] commit 並打上 tag `ch11-done`

### 驗收

1. 「第12條」與「第 12 條」查詢結果相同，且能命中該條文的 Chunk。
2. 文件中的編號（含連字號）以 hybrid 模式查詢時排在前 3 名，dense 模式則記錄實際名次作對照。
3. 語意改寫題的 Recall@5，hybrid 不低於 dense。
4. 無答案題在 hybrid 模式下仍全部回覆「資料不足」，而且沒有呼叫 LLM。
5. 刪除文件後，keyword 與 dense 兩條路都不再回傳該文件的 Chunk。
6. 切換 `retrieval_mode` 只需改 `.env`，Controller 與問答流程不動。

## 七、常見陷阱

多數陷阱都不會報錯，只會讓結果「靜靜地變差」，所以每一項都要有對應的測試題。

| 陷阱 | 現象 | 處理 |
| --- | --- | --- |
| 預設停用詞 | ngram 會排除含停用詞的詞元，`a`、`i` 等單字母停用詞會讓部分英數編號搜不到 | 建立索引前關閉停用詞（`innodb_ft_enable_stopword=OFF`） |
| 改了 `ngram_token_size` 沒重建索引 | 參數已變但索引仍是舊切法 | 此參數只在啟動時讀取；改完要重啟 MySQL 並重建 FULLTEXT 索引 |
| BOOLEAN MODE 的 `-` | `BUG-2026-000183` 被解讀成「排除 2026」 | 精確詞一律包在雙引號內做片語查詢；轉義由程式處理，不拼字串 |
| 空白讓 ngram 對不上 | ngram 會略過含空白的詞元，「第 12 條」與「第12條」切出不同的詞 | 查詢與索引都經過 SearchTextNormalizer（第四節第 4 點） |
| 單字查詢 | 只輸入一個中文字（例如「假」）可能搜不到 | 預期行為，交給 Dense 處理，不另外修 |
| 直接比較分數 | 拿 FULLTEXT 分數跟 Cosine 設同一個門檻 | 尺度不同，只用名次（RRF）；門檻只用在 Dense |
| 常見詞命中太多 | 無答案題開始被硬湊答案 | 資料不足 Gate 不看一般關鍵字結果（第四節第 1 點） |
| 刪除同步漏一邊 | 關鍵字路徑仍找到已刪除文件 | Chunk 隨文件刪除；查詢時再過濾 `status = indexed` |

## 八、下一章

下一章依教學順序進入 Reranker：Hybrid 先取 Top 20，再由 Cross-encoder 重新排序挑出 Top 5。本章保留的 `rrf_score` 與各路名次，會直接用來比較 Rerank 前後的排名變化。

## 參考資料

- [Qdrant：Full-text Search / BM25](https://qdrant.tech/documentation/search/text-search/full-text-search/index.md)
- [Qdrant Edge：BM25](https://qdrant.tech/documentation/edge/edge-bm25/)
- [bm25 Go 套件說明（Qdrant v1.15.2 伺服器端 BM25）](https://pkg.go.dev/github.com/harsh04/bm25@v0.1.1)
- 《地端 LLM 與 RAG 基礎實作教學 v3.1》第十階段、第十一階段
- 《Ch00 環境與專案骨架：篇章總結》
