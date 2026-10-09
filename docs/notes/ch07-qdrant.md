# Ch07 Qdrant：實作與實測紀錄

Qdrant 1.19.0（Docker）、bge-m3 / qwen3-embedding:0.6b（Ollama）、Mac 16GB。

## Index Flow

```
上傳 → ParseDocumentJob → ChunkDocumentJob → IndexDocumentJob（三個 Job 自動接續）

IndexDocumentJob → DocumentIndexingService
  chunked / indexed → indexing（條件式 UPDATE）
  → ChunkIndexer
       1. 讀取 document_chunks（依 chunk_index）
       2. EmbeddingService.embed(contents, document)        ← 先產生向量，縮短空窗
       3. CollectionManager.ensure(model)                   ← 不存在就建立，存在就比對規格
       4. 依 document_id 刪除舊 Points（wait=true）          ← 重新切段會換 chunk id
       5. 每 64 筆 upsert（ID = chunk id，wait=true）
  → indexing → indexed（例外 → 重試，用盡 → failed + error_message）

刪除文件：處理中不可刪 → ChunkPurger（所有 company_docs_*）→ 成功才刪 MySQL → 刪檔案
```

## 實測結果

### 自動流程

上傳 `regulation.pdf`（#15）後，Parse → Chunk → Index 三個 Job 依序執行（索引約 0.7 秒），狀態為 `indexed`，11 個 Chunk、11 個 Points。另以 `rag:index --all` 補建 Ch03～Ch05 留下的 9 份文件：126 個 Points，含載入 bge-m3 共 17 秒。

### rag:collections

| Collection | 維度 | distance | points_count | indexed_vectors_count |
| --- | --- | --- | --- | --- |
| company_docs_bge_m3 | 1024 | Cosine | 137 | **0** |
| company_docs_qwen3_embedding_0_6b | 1024 | Cosine | 11 | **0** |

`indexed_vectors_count` 為 0 不是錯誤：Collection 的 `indexing_threshold` 預設為 10,000（KB），向量資料量低於門檻時 Qdrant 不建 HNSW 圖，搜尋時直接逐筆比對（精確，而且在這個規模下夠快）。資料量變大後才會自動建立 HNSW，改用近似搜尋（ANN）換取速度。

### rag:search

「特休沒休完可以延到明年嗎」（相關）：

| 分數 | 檔名 | section |
| --- | --- | --- |
| **0.6773** | 員工管理辦法.pdf | 第十二條（特別休假） |
| 0.6773 | 員工管理辦法-副本.pdf | 第十二條 |
| 0.6342 | 新進員工指南.md | 常見問題（特休何時開始計算） |
| 0.6217 | 員工差勤管理規章（Ch07 驗收）.pdf | 第二章 請假 / 第四條 |
| 0.6217 | 員工差勤管理規章.pdf | 第二章 請假 / 第四條 |

「停車場收費標準」（文件中沒有相關內容）：

| 分數 | 檔名 | 內容 |
| --- | --- | --- |
| **0.5012** | 員工工作規則（長文件）.pdf | 「員工工作規則」（只有文件標題） |
| 0.5012 | 員工工作規則（長文件・修正版）.pdf | 「員工工作規則」 |
| 0.4892 | 員工管理辦法.pdf | 「員工管理辦法」 |
| 0.4892 | 員工管理辦法-副本.pdf | 「員工管理辦法」 |
| 0.4737 | 員工差勤管理規章.pdf | 「員工差勤管理規章」 |

**觀察（給 Ch08）**

1. **相關與無關的最高分只差 0.17**（0.68 vs 0.50）。向量搜尋一定會回傳「最接近」的 5 筆，無關問題也照樣有結果，所以 Ch08 必須設 `score_threshold`；而且差距這麼小，門檻不好設，需要測試集來決定。
2. **無關問題的前 5 名全是「只有文件標題」的 Chunk**（6～8 Token）。很短、很籠統的文字和任何問題都「有點像」。Ch05 刻意保留第一條之前的文字（不丟內容），在檢索時反而成了雜訊；Ch08 要評估是否排除或合併過短的 Chunk。
3. **重複的文件佔用了 Top-5 的名額**：「員工管理辦法」與「副本」、「差勤規章」與「驗收」內容相同、分數完全一樣，相關問題的 5 筆結果實際上只有 3 筆不同的內容。Ch03 的 sha256 可以用來去重。
4. 中段事實所在的「第四條」第二段（含「遞延至次一年度」）排第 4 名，分數 0.62；口語「延到明年」與條文「遞延至次一年度」用詞不同，是 Ch13 Query Rewriting 要處理的問題。

### 重新切段與重新索引

| | Point id | points_count |
| --- | --- | --- |
| 重新切段前 | 233～243 | 137 |
| `rag:chunk 15` 後（自動重建索引） | 244～254（與新的 chunk id 一致） | 137 |

舊 id 的 Points 全部被刪除，`rag:index-check` 一致、無孤兒。

### 第二個模型（不修改狀態）

`rag:index 15 --model=qwen3-embedding:0.6b`：寫入 `company_docs_qwen3_embedding_0_6b` 11 個 Points；文件狀態仍為 `indexed`，`updated_at` 沒有變。以 qwen3 搜尋只會找到 #15（只有它建了 qwen3 的索引）。

### 維度相同、模型不同

手動把一個 **bge-m3 的向量**（內容與問題一字不差：「特休沒休完可以延到明年嗎」）以 `embedding_model: bge-m3` 寫進 qwen3 的 Collection：

- Qdrant **沒有報錯**（兩個模型都是 1024 維）。
- 以 qwen3 的問題向量搜尋、**不加過濾**時，它排在 12 筆中的**最後一名，分數 0.0335**：兩個模型的 1024 個數字意義完全不同，比較起來等於亂數。
- `rag:search`（有 `embedding_model` 過濾）完全找不到它。

### 刪除文件

刪除 #15 後：MySQL、Chunk、上傳檔案都已刪除；兩個 Collection 中 `document_id = 15` 的 Points 都是 0。

**實測發現的 Qdrant 行為**：刪除 Points 後，facet 仍會回傳 `{"value": 15, "count": 0}`（Payload Index 保留了這個值）。`rag:index-check` 一開始因此把它誤判成孤兒；`QdrantClient::facet()` 改為過濾掉 count 為 0 的值。

## 實測確認的其他 Qdrant 行為

| 操作 | 結果 |
| --- | --- |
| 寫入 `[3,0,0,0]` 後讀回 | `[1,0,0,0]`：Cosine Collection 寫入前先正規化 |
| 維度錯誤 | HTTP 400 `Vector dimension error: expected dim: 4, got 2` |
| 重複建立 Collection | HTTP 409 |
| 查詢不存在的 Collection | HTTP 404 |
| 錯誤格式 | `{"status": {"error": "..."}}` |
