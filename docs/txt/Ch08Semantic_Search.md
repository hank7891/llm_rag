# Ch08 Agent 任務：Semantic Search 與測試集

> 對應規範：《地端 LLM 與 RAG 基礎實作教學 v3.1》九、第七階段：Semantic Search
> 相關段落：七（Embedding 模型比較時機）、八（多模型索引）、二十（驗收清單）
> 前一章：Ch07 Vector Database（已完成）
> 下一章：完成第一版 RAG（第八階段）

## 0. 開始前必讀

1. 先閱讀 Ch07 的章節文件與既有程式，掌握 Qdrant 存取、Collection 設定、索引 Job、搜尋功能的現行設計。
2. **沿用 Ch07 已有的設計與命名，不要重寫或改變其做法。** 本章只在其上新增功能；Ch07 已有的功能（例如搜尋指令）以擴充方式處理。
3. 若本檔的描述和 Ch07 既有設計衝突，以 Ch07 為準，先停下來回報衝突點，不要自行選擇。

## 1. 本章目標

把「輸入問題、輸出相關 Chunk」的 **Retriever** 完成並驗證品質，**仍然不接 LLM**。本章結束時要能用數字回答：

1. 目前的檢索找得準不準（Recall@K）
2. 文件中沒有答案時，系統能不能正確判斷「資料不足」（Relevance Threshold）

先不接 LLM，是為了把「檢索錯」和「生成錯」分開。

## 2. 核心觀念（實作時需遵守）

- **Top-K 一定有結果**：Qdrant 永遠會回傳 K 筆相對最接近的資料，即使問題與文件無關。「最接近」不等於「足夠相關」。
- **Relevance Threshold**：低於門檻的結果不得進入 Context。本專案使用 Qdrant + Cosine，分數越高越相似，直接在搜尋 API 帶入 `score_threshold`，由 Qdrant 處理比較方向。
- **門檻值不能套用外部數字**：bge-m3 的 Cosine 分數通常偏集中，無關內容也可能拿到不低的分數，必須依自己的測試集分數分布決定。
- **Retriever 是後續強化的對象**：之後的 Hybrid Search、Reranker 都改這一層，介面要先定好。

## 3. 範圍外（請勿實作）

- 串接 LLM、Context Builder、Prompt、Citation（第八、九階段）
- Hybrid Search、Sparse Vector、MySQL FULLTEXT（第十階段）
- Reranker（第十一階段）
- Query Rewriting、多輪對話（第十二階段）

## 4. 前置條件（不符合就回報，不要自行補做）

- Ch07 完成：`company_docs_bge_m3` 已有文件的 Points，可執行基本 Vector Search
- Ch06 已準備第二個候選 Embedding 模型，並已確認其輸出維度、最大輸入長度與 query / document 前綴規則
- 至少一份含答案的文件已完成索引（例如員工管理辦法）

## 5. 執行項目

### 5.1 RetrieverService

```
retrieve(string $query, RetrieveOptions $options): RetrievalResult
  ├── EmbeddingService.embed([$query])   ← 依模型規則決定是否加 query 前綴
  ├── Qdrant search（top_k、score_threshold、payload filter）
  └── 回傳 Chunk 清單（content、score、document_name、section、page_start/end）
```

- 使用 Ch07 既有的 Qdrant 存取層，不另寫一套
- Collection 名稱、top_k、score_threshold 由設定檔讀取，門檻值可依 Collection 分別設定（門檻值依模型而異）
- 回傳結果保留 `score`，評估和除錯都會用到
- 沒有結果通過門檻時，明確回傳「無候選」，不要回傳 null 或空字串混過去。下一章會依此直接回答「資料不足」而不呼叫 LLM

### 5.2 除錯指令

```bash
php artisan rag:search "我的假沒休完怎麼辦？" --k=5 --collection=company_docs_bge_m3
```

- Ch07 若已有搜尋指令，擴充它，不要另建
- 輸出每筆結果的排名、分數、文件、條號、頁碼和內容前 80 字
- 可指定 Collection、K、門檻；可選擇不套用門檻，以觀察原始分數

### 5.3 測試集（`tests/rag-set/`）

準備 20～30 題，建議題型比例：

| 題型 | 數量 | 目的 |
| --- | --- | --- |
| 用詞與文件相同 | 5～8 | 基準題，應該全對 |
| 換句話說（口語、同義詞） | 8～12 | 測語意搜尋真正的價值 |
| 精確編號、專有名詞 | 3～5 | 預期表現差，留給 Hybrid Search 對照 |
| 文件中沒有答案 | 5～6 | 測門檻與「資料不足」 |

格式使用 JSONL：

```json
{"id": "q01", "type": "exact", "question": "特休沒休完可以延到明年嗎？", "expected": [{"document": "員工管理辦法.pdf", "section": "第 12 條"}]}
{"id": "q21", "type": "no_answer", "question": "公司有提供員工宿舍嗎？", "expected": []}
```

- **預期答案不要用 chunk_id 標記**。調整 Chunk Size 或重建索引後 chunk_id 會改變，測試集就失效。改用「文件 + 條號」或頁碼判定是否命中。
- 題目請先依已索引的文件產生草稿並列出，**由使用者確認後再定稿**。

### 5.4 評估指令

```bash
php artisan rag:eval --collection=company_docs_bge_m3
```

輸出：

- Recall@1、@3、@5、@10（計算時不套用門檻）
- 依題型分組的 Recall
- 有答案題的 Top-1 分數分布（最小值、平均值）
- 無答案題的 Top-1 分數分布（最大值、平均值）
- 套用目前門檻時，無答案題是否回傳「無候選」、有答案題是否被誤判為無候選
- 每題明細
- 結果寫入 `docs/notes/`，檔名含 Collection 與日期，方便之後比較

### 5.5 決定門檻值

把兩組分數分布放在一起看：

```
有答案題 Top-1 分數：  ──────────■■■■■■■■■■──
無答案題 Top-1 分數：  ────■■■■■■────────────
                              ↑ 門檻放在兩群之間
```

- 兩群重疊時，門檻設高會把有答案的題目誤判為「資料不足」，設低則會讓無關內容混進 Context
- 第一版建議**寧可偏高**：答「資料不足」比答錯對企業內部系統的傷害小
- 決定的數值寫入設定檔；重疊情形與取捨理由寫入 `docs/notes/`

### 5.6 Embedding 模型比較

1. 第二個候選模型建立獨立 Collection（例如 `company_docs_<模型B>`），沿用 Ch07 的命名規則與建立方式，維度依該模型設定
2. 用同一批 Chunk 重建索引，Payload 記錄 `embedding_model`
3. 確認 query / document 前綴已依模型規則處理（前綴漏加是比較結果失真最常見的原因）
4. 用同一份測試集執行 `rag:eval`
5. 比較：Recall@K、無答案題分數分布、Embedding 速度、記憶體使用量
6. 每個模型各自決定門檻值
7. 整理比較結果，**由使用者決定**線上採用的模型，再更新設定檔中的線上 Collection

## 6. 建議實驗（結果記錄在 `docs/notes/`）

1. **Top-K 的影響**：K=1、3、5、10 的 Recall 變化，觀察 K 增加到什麼程度後 Recall 趨緩（Reranker 階段「先取 Top 20 再重排」的伏筆）
2. **口語 vs 正式用詞**：「我的假沒休完怎麼辦？」對照「年度特別休假未休畢」，記錄分數差距
3. **精確編號題**：記錄失敗案例，留到 Hybrid Search 階段對照
4. **追問題**：直接拿「那兩年年資呢？」去搜，記錄找不到的現象，留到 Query Rewriting 階段對照（只觀察，不實作改寫）

## 7. 驗收

1. `rag:search` 可輸入問題並顯示 Top-K 結果、分數與來源，可切換是否套用門檻
2. 無關問題（例如「公司有提供員工宿舍嗎？」）套用門檻後回傳「無候選」
3. 有答案的口語問題（例如「我的假沒休完怎麼辦？」）能找到正確條文且通過門檻
4. 測試集 20～30 題，包含無答案題，預期答案不依賴 chunk_id，經使用者確認定稿
5. `rag:eval` 可輸出 Recall@K 與分數分布，結果存進 `docs/notes/`
6. 門檻值依測試集分數分布決定，並記錄理由
7. 至少兩個 Embedding 模型各自建立 Collection、重建索引，並以同一測試集完成比較
8. 門檻比較方向已依 Qdrant + Cosine 驗證（`score_threshold`）
9. 第 6 節四項實驗已有紀錄
10. 若有 Feature / Unit Test，全部通過

## 8. 完成後回報

1. 新增與修改的檔案清單，以及每個 Class 的責任
2. 和 Ch07 既有設計的銜接方式；若有衝突，列出並說明
3. 兩個模型的比較表（Recall@K、無答案題、速度、記憶體使用量）
4. 建議的門檻值與理由
5. 四項實驗的觀察結果
6. 說明 Top-K 與 Relevance Threshold 各自解決什麼問題

使用者確認後 commit，並打上 tag `ch08-done`。
