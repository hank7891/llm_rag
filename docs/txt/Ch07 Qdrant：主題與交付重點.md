# Ch07 Qdrant：主題與交付重點

Oct 4, 2026 · @hank

## 一、本章目標

把 Ch06 產生的向量存進 Qdrant，並讓 Qdrant 與 MySQL 保持一致。本章結束時，上傳一份文件會自動走完「解析 → 切段 → 向量化 → 寫入 Qdrant」，狀態停在 `indexed`。

對應教學文件第六階段，位於第三篇「向量化與檢索」，接在 Ch06 Embedding 之後、Ch08 Semantic Search 之前。

1. 建立 Collection，名稱帶上模型識別，例如 `company_docs_bge_m3`（size 1024、distance Cosine）
2. 寫入 Points，Payload 包含來源 Metadata 與 `embedding_model`
3. 刪除或更新文件時，依 `document_id` 同步刪除對應的 Points

## 二、核心觀念

**Collection / Point / Payload** Collection 像一張表，Point 是一筆資料，由 ID、Vector、Payload 組成。Vector 用來搜尋，Payload 用來知道資料從哪裡來，也可以拿來過濾。

**MySQL 是來源，Qdrant 是衍生索引** Qdrant 裡的資料都可以從 `document_chunks` 重新算出來，反過來則不行。所以兩邊不一致時，一律以 MySQL 為準，Qdrant 刪掉重建即可。這也決定了刪除的順序與重建指令的設計。

**維度相同不代表模型相同** bge-m3 與 qwen3-embedding:0.6b 都是 1024 維。拿錯模型的向量寫入或搜尋，Qdrant 不會報錯，只會給出看似正常但錯誤的結果。這是 Ch04、Ch05、Ch06 之後第四種「靜默出錯」，要由程式自己把關。

**Distance Metric** 文字 Embedding 通常用 Cosine，只看方向不看長度。Qdrant 在 Cosine Collection 寫入時會先把向量正規化，所以讀回來的向量和寫入的不完全相同，這是正常現象。分數越高越相似，這點在 Ch08 設門檻時會用到。

**ANN / HNSW** 資料量大時，Qdrant 用 HNSW 圖做近似搜尋，用一點精確度換速度。資料量小時，Qdrant 根本不建 HNSW 索引，直接逐筆比對。可以在 Dashboard 看到 `indexed_vectors_count` 是 0，這不是錯誤。

**Payload Index** 依 `document_id` 刪除 Points，本質上是一次過濾操作。常用的過濾欄位要建立 Payload Index，否則資料多了之後會變慢。

## 三、架構

```
[Queue Worker]
ParseDocumentJob → ChunkDocumentJob → IndexDocumentJob（本章新增）

IndexDocumentJob
  → status = indexing
  → 讀取 document_chunks
  → EmbeddingService.embed(texts, inputType = document)
  → 依 document_id 刪除該文件在目前 Collection 的舊 Points
  → Upsert 新 Points（ID = chunk.id，Payload = 來源 Metadata）
  → status = indexed（例外 → failed + error_message）

刪除文件
  → 依 document_id 刪除所有 company_docs_* Collection 中的 Points
  → 成功後才刪除 MySQL 資料
```

## 四、實作重點

**Collection 名稱由模型推導，不另外設定** 設定檔只指定 Embedding 模型，Collection 名稱由「前綴 + 模型名稱轉換」算出來，例如 `bge-m3` → `company_docs_bge_m3`、`qwen3-embedding:0.6b` → `company_docs_qwen3_embedding_0_6b`。模型和 Collection 只有一個來源，就不會出現「模型換了、Collection 忘了換」的情況。

**建立 Collection 時檢查規格** Collection 不存在就建立；已存在就比對 `size` 與 `distance` 是否與模型規格表一致，不一致時直接報錯，不要自動刪除重建。

**寫入與搜尋都比對模型** 寫入時，Payload 的 `embedding_model` 一律由程式帶入實際使用的模型。搜尋時加上 `embedding_model` 的 Payload 過濾條件。即使有人手動把錯誤的資料寫進 Collection，也搜尋不到。

**Point ID 用 `document_chunks.id`，但要先刪後寫** Qdrant 只接受正整數或 UUID 當 ID，直接沿用 chunk id 最簡單。但 Ch05 的 `ChunkDocumentJob` 每次重切都會刪除舊 Chunk、產生新 id，只做 Upsert 會留下舊 id 的孤兒 Points。所以每次索引都要先依 `document_id` 刪除，再寫入。

**刪除文件時清掉所有模型的 Collection** Ch08 會有兩個模型的 Collection 並存。刪除文件只清目前使用的 Collection，另一個就會殘留。順序是先刪 Qdrant、成功後再刪 MySQL；Qdrant 失敗就中止，避免 MySQL 已刪、Qdrant 還留著的狀況。

**用 HTTP Client 直接呼叫 Qdrant REST API** 不引入 Qdrant SDK。本章只用到五、六個端點，用 Laravel HTTP Client 寫一個薄的 `QdrantClient`，每個請求長什麼樣子都看得到，測試也能直接用 `Http::fake()`。

**寫入時帶 `wait=true`** Qdrant 預設非同步寫入，回應時資料可能還沒落地。帶上 `wait=true` 才能保證 Job 把狀態改成 `indexed` 時，資料真的已經可以搜尋。

## 五、本章的決策

| 決策點 | 建議 | 理由 |
| --- | --- | --- |
| Ch06 留下的「Embedding 是否併入索引 Job」 | 新增獨立的 `IndexDocumentJob`，由 `ChunkDocumentJob` 成功後 Dispatch | 失敗時可以只重跑索引，不必重新切段 |
| 非目前模型的索引 | `rag:index --model=` 寫入該模型的 Collection，但不改 `documents.status` | status 只代表線上使用的 Collection；Ch08 建第二個模型的索引時不會干擾 |
| Payload 是否存 `content` | 存，依教學文件 | Ch08 用指令檢查搜尋結果時可以直接看內容；代價是資料重複，以 MySQL 為準 |
| 是否抽象成 `VectorStoreInterface` | 不抽象，Qdrant 相關程式集中在 `app/Rag/VectorStore/` | 目前只有一種向量資料庫，介面只有一個實作看不出好壞（Ch01 學到的） |
| 重新索引時的空窗 | 先刪後寫，接受短暫查不到 | POC 可接受；正式環境可改用 Collection Alias 切換 |

## 六、明確不做

`score_threshold`、測試集、Recall@K、兩個模型的比較（都在 Ch08）。RAG 回答與 Citation（Ch09、Ch10）。Sparse Vector 與 Hybrid Search（Ch11）。本章的搜尋指令只用來確認資料寫入正確。

## 七、驗收

- 上傳一份文件後，狀態依序走到 `indexed`，Point 數量等於該文件的 `document_chunks` 筆數
- Dashboard 中任選一個 Point，Payload 的檔名、條號、頁碼與 MySQL 一致
- 同一份文件重新切段、重新索引後，Point 數量不變，沒有孤兒 Points
- 刪除文件後，所有 `company_docs_*` Collection 中都找不到該文件的 Points
- 模型設定與 Collection 規格不符時，明確報錯，而不是靜默寫入
- `rag:index-check` 回報 MySQL 與 Qdrant 的筆數一致

## 八、進入前確認

- [ ] Ch06 已收尾：`git tag` 有 `ch06-done`，`git status` 乾淨
- [ ] `docker compose ps` 中 qdrant 為 running，`curl localhost:6333` 回傳版本資訊
- [ ] http://localhost:6333/dashboard 可以開啟，目前沒有任何 Collection
- [ ] Queue Worker 已啟動（`php artisan queue:listen`）

## 九、交給 CLI Agent 的指令

```
進入 Ch07：Qdrant。
本章把 Ch06 的向量寫入 Qdrant，並讓 Qdrant 與 MySQL 保持一致。
不做 score_threshold、測試集、Recall@K、模型比較（屬於 Ch08），不做 RAG 回答與 Citation。

【前置確認】
1. 確認 git 狀態乾淨、tag ch06-done 存在，否則先停下來回報。
2. 確認 Qdrant 可連線（GET {QDRANT 位址}/），並回報版本。連不上時停下來回報，不要修改 compose.yaml。
3. 先閱讀既有程式再動手：DocumentStatus 的狀態轉換、ChunkDocumentJob、EmbeddingService、
   Embedding 模型規格表所在的設定檔、Qdrant 連線位址在 .env 中的變數名稱。
   沿用既有命名與設定檔，不要另外建立重複的設定。
4. 延續先前的決定：不開分支；共用設定用 LLM_ 前綴，Provider 專屬設定用該 Provider 前綴。

【實作範圍】
5. 建立 app/Rag/VectorStore/：
   - QdrantClient：以 Laravel HTTP Client 呼叫 Qdrant REST API，不引入 SDK。
     只實作本章需要的操作：collection 查詢／建立、payload index 建立、
     upsert points、依 filter 刪除 points、依 filter 計數、search、列出 collections。
     寫入與刪除一律帶 wait=true。HTTP 錯誤轉成明確例外，訊息包含 Qdrant 回傳的錯誤內容。
   - CollectionResolver：由 Embedding 模型名稱推導 Collection 名稱
       名稱 = 前綴 + "_" + 模型名稱（非英數字一律轉成底線，轉小寫）
       例：bge-m3 → company_docs_bge_m3；qwen3-embedding:0.6b → company_docs_qwen3_embedding_0_6b
     前綴從設定讀取（RAG_COLLECTION_PREFIX，預設 company_docs）。不要另設 Collection 名稱的設定。
   - CollectionManager：ensure(model)
       不存在 → 依模型規格表的 dimension 建立，distance 為 Cosine，
                並建立 payload index：document_id（integer）、embedding_model（keyword）
       已存在 → 比對 size 與 distance，不一致時丟出例外，不得自動刪除重建
   - ChunkIndexer：index(Document, ?string model)
       a. model 未指定時使用目前設定的 Embedding 模型
       b. ensure Collection
       c. 讀取該文件的 document_chunks，依 chunk_index 排序
       d. 呼叫 EmbeddingService（inputType = document, model = 指定模型）
       e. 依 document_id 刪除該 Collection 中的舊 Points
          （重新切段會產生新的 chunk id，只 upsert 會留下孤兒 Points）
       f. 分批 upsert，Point ID = document_chunks.id
          Payload：document_id、document_name、chunk_id、chunk_index、page_start、page_end、
                  section、chunk_strategy、embedding_model、content
          embedding_model 一律由程式帶入實際使用的模型，不得來自外部輸入
       g. 回傳寫入筆數
   - ChunkPurger：purge(document_id)
       列出所有以前綴開頭的 Collection，逐一依 document_id 刪除 Points

6. IndexDocumentJob：
   - ChunkDocumentJob 成功後 Dispatch；只傳 document_id
   - status 依既有狀態機轉為 indexing → indexed；例外時為 failed 並寫入 error_message
   - 若現有狀態轉換不允許這條路徑，調整狀態機並在回報中說明
   - 設定 tries、timeout；在 failed() 中寫入失敗狀態
   - 只索引到目前設定的模型

7. 刪除文件流程：先呼叫 ChunkPurger，成功後才刪除 MySQL 資料。
   Qdrant 刪除失敗時中止整個刪除並顯示錯誤，不得只刪 MySQL。
   若既有程式沒有刪除文件的功能，在 /document 列表加上刪除按鈕。

8. 搜尋（只用於驗證寫入）：
   - VectorSearcher：search(string query, int limit, ?string model)
     以 inputType = query 產生向量，搜尋該模型的 Collection，
     並加上 embedding_model = 該模型 的 payload filter。
     不設 score_threshold，回傳分數與 payload。

9. Artisan 指令：
   - rag:index {document?} {--all} {--model=}
       重建指定文件或全部文件的索引。指定 --model 且不是目前設定的模型時，
       只寫入該模型的 Collection，不修改 documents.status。
   - rag:collections：列出所有 company_docs_* Collection 的名稱、維度、distance、
       points_count、indexed_vectors_count
   - rag:search {query} {--model=} {--limit=5}：以表格列出分數、檔名、section、頁碼、內容開頭
   - rag:index-check {--model=}：逐份文件比對 document_chunks 筆數與 Qdrant 的 Points 數，
       列出不一致的文件；另列出 Qdrant 中 document_id 已不存在於 MySQL 的孤兒 Points

10. 設定：Qdrant 相關設定放進既有的 RAG 設定檔（Ch05 chunking 所在），
    包含 url（沿用既有 .env 變數）、collection_prefix、upsert_batch_size（預設 64）、timeout。
    同步更新 .env.example。

【測試】
11. Unit Test：
    - CollectionResolver 的名稱轉換（含冒號、點、連字號）
    - CollectionManager：已存在且規格不符時丟出例外，不會呼叫刪除
    - ChunkIndexer：先刪後寫的順序、Point ID 與 Payload 內容、分批、embedding_model 正確
    - ChunkPurger 會對每一個前綴相符的 Collection 送出刪除
    - VectorSearcher 的請求帶有 embedding_model filter
    以上使用 Http::fake() 與假的 Embedding Provider（既有的沿用，沒有就建立在 tests/ 下）。
12. Feature Test：IndexDocumentJob 成功與失敗時的狀態；刪除文件時 Qdrant 失敗則 MySQL 不刪。
13. 整合測試（標記為獨立 group，預設不執行）：對真實 Qdrant 使用前綴 test_company_docs，
    執行 建立 → 寫入 → 計數 → 重新索引 → 刪除，結束時刪除測試用 Collection。

【實測】
14. 上傳一份真實規章，等待狀態變成 indexed 後依序執行：
    rag:collections、rag:index-check、
    rag:search "特休沒休完可以延到明年嗎"、rag:search "停車場收費標準"
    再對同一份文件執行 rag:chunk 重新切段與 rag:index，確認 Point 數量正確、沒有孤兒 Points。
15. 執行 rag:index {document} --model=qwen3-embedding:0.6b，
    確認寫入 company_docs_qwen3_embedding_0_6b，且 documents.status 沒有變化。
16. 刪除該文件，再執行 rag:collections 與 rag:index-check，確認兩個 Collection 都已清空該文件。
17. 實測結束後執行 ollama stop 卸載 Embedding 模型。
    把觀察寫進 docs/notes/ch07-qdrant.md。

【完成回報】做完以上內容就停止，不要進入 Ch08，不要 commit。
請依序回報：
A. 新增與修改的檔案清單，每個檔案一句話說明責任
B. Index Flow：從 ChunkDocumentJob 完成到 status = indexed 的完整流程
C. 為什麼索引前要先依 document_id 刪除，而不是只做 upsert
D. 為什麼 Collection 名稱由模型推導，以及搜尋時為什麼還要加 embedding_model filter
E. 為什麼刪除文件時先刪 Qdrant，再刪 MySQL
F. 狀態機是否有調整，調整了什麼
G. rag:collections 的輸出，indexed_vectors_count 是多少，為什麼
H. 兩次 rag:search 的結果：相關與無關問題的最高分各是多少，這對 Ch08 的門檻代表什麼
I. 重新索引與刪除後 rag:index-check 的結果
J. 測試執行結果（含整合測試是否實際跑過）
```

## 十、親自驗收

Agent 回報後，以下幾點建議自己確認，不要只看回報內容：

| 檢查項目 | 怎麼看 |
| --- | --- |
| Payload 與原文一致 | Dashboard 任選兩三個 Point，對照 PDF 的條號與頁碼，至少挑一個跨頁條文 |
| 沒有孤兒 Points | 重新切段並索引後，`points_count` 等於 `document_chunks` 筆數 |
| 用錯模型會被擋下 | 暫時把 Embedding 模型改成 qwen3-embedding，執行 `rag:search`，應只搜尋 qwen3 的 Collection，不會混到 bge-m3 的資料 |
| 刪除真的同步 | 刪除文件後，在 Dashboard 用 `document_id` 過濾，兩個 Collection 都查不到 |
| 無關問題的分數 | 「停車場收費標準」的最高分若不低，正好說明 Ch08 必須設門檻 |

- [ ] 以上五項確認無誤
- [ ] 撰寫 `docs/notes/ch07-summary.md`
- [ ] 請 Agent commit 並打上 tag `ch07-done`
