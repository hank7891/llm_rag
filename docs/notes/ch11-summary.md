# Ch11 總結：Hybrid Search（Dense + Keyword + RRF）

## 成果

```
RetrieverService（業務層唯一入口，依 rag.retrieval.mode 選擇 dense / keyword / hybrid）
  ├── Dense：bge-m3 → Qdrant（score_threshold 0.59，候選 20）
  ├── 一般關鍵字：SearchTextNormalizer(問題) → MySQL FULLTEXT ngram，NATURAL LANGUAGE MODE（候選 20）
  └── 精確詞：ExactTermExtractor（條號、英數編號、引號字串）→ BOOLEAN MODE 片語查詢
  → 資料不足的判斷：Dense 命中或精確命中，兩者都沒有 → 無候選（一般關鍵字命中再多也不算）
  → RRF（k = 60）合併三份清單的名次；keyword_only_policy 決定「只被一般關鍵字找到」的段落能否進入
  → 依 id 從 MySQL 取回內容與 Metadata（只取 status = indexed），取 Top-K
```

RagAnswerService、Context Builder、Citation 都沒有改：Hybrid 是「找」的強化，不是「答」的改變。評估結果見 `ch11-eval.md`。

## 為什麼用 RRF

- Dense 的 Cosine（0～1）與 FULLTEXT 的相關度（沒有上限、隨查詢變動）尺度不同，相加或設同一個門檻都沒有意義。
- RRF 只看名次：每份清單的名次換算成 1 / (k + 名次) 再加總；只出現在一份清單的結果，另一份不計分。兩邊都找到的排前面，只被精確詞命中的編號也能排進前段。
- 門檻只用在 Dense；關鍵字分數只用來排序。

## 資料不足的判斷不能被關鍵字破壞

- 中文 ngram 很容易被「規定」「人資部門」這類常見二字詞命中；一般關鍵字結果若能讓問題進入 LLM，無答案題就會開始被硬湊答案。
- 能讓問題通過的只有兩種：Dense 通過門檻，或精確命中（片語查詢有結果）。
- 代價：人名、單位名稱抽不出精確詞，Dense 分數又不夠時會被判為無候選（專有名詞題 p01～p04 在所有模式都是如此），`keyword_only_policy = allow` 也救不回來。

## 正規化必須兩邊一致

`SearchTextNormalizer` 在寫入 `document_chunks.search_text` 與查詢前都必須呼叫：

| 規則 | 原因 |
| --- | --- |
| 全形英數轉半形（沿用 Ch03） | ＨＲ－２０２６ 與 HR-2026 相同 |
| 中文數字條號轉阿拉伯數字：第十二條 → 第12條、第三條之一 → 第3條之1 | 文件多用中文數字，使用者常打「第12條」；ngram 的「12」對不上「十二」（實測片語查詢 0 筆） |
| 移除中文字與中文標點旁的空白 | 「第 12 條」與「第12條」切出的詞元才相同（ngram 略過含空白的詞元） |
| 英文轉小寫 | |
| 移除英數字之間的連字號與底線：HRIS-3 → hris3 | `ngram_token_size = 2` 時，連字號切出的 1 字片段（「3」）不進索引，片語查詢整個查不到（Ch11 評估時發現） |

`content` 保持原文，給 LLM 與 Citation 使用。

## 陷阱（大多不報錯，只會靜靜地變差）

| 陷阱 | 處理 |
| --- | --- |
| 預設停用詞讓含單字母的英數編號搜不到 | `innodb_ft_enable_stopword=OFF`（compose.yaml），只在啟動時讀取，改了要重啟並重建索引 |
| BOOLEAN MODE 的 `-` 被解讀成排除 | 精確詞一律包在雙引號內做片語查詢，由程式組字串 |
| 條號的中文數字、空白對不上 | SearchTextNormalizer |
| 編號中 1 字的片段（HRIS-3 的 3） | 移除英數字之間的連字號 |
| **InnoDB FULLTEXT 看不到尚未提交的資料** | DatabaseTransactions 的測試查不到交易中新增的 Chunk：一般測試以 mock 取代關鍵字搜尋，真正的 SQL 放在 `fulltext` 群組 |
| 只取 indexed 的文件 | 關鍵字查詢 join `documents.status_key = indexed`；合併後依 id 取回內容時也再過濾一次 |
| 新文件停在 uploaded | 上傳時要有 queue worker；第一次評估因此作廢 |

## 評估結論

- 精確字串題：dense 0% 進入 Context → hybrid 條號、編號 100%（前 3 名）；Ch08 被擋下的精確編號題 5 題中救回 4 題（日期題 q24 不在精確詞規則內）。
- 語意改寫題：Recall@5 維持 100%，但 Recall@1 從 92% 降到 75%（指南 FAQ 因含「特休」「第十二條」被關鍵字推到第 1 名）；正確條文仍在 Top-5，排序交給下一章 Reranker。
- 無答案題：questions.jsonl 6/6；新增的 6 題中 n01、n04 被 Dense 門檻放行（dense 模式同樣），屬於門檻 0.59 的限制，不是關鍵字造成的。
- `exact_only` 與 `allow` 在本測試集結果相同。

## 測試

- 一般測試（預設執行）：RRF 計分、精確詞抽取（從原始問題經正規化）、正規化、資料不足的判斷、兩種 policy、只取 indexed、查詢先正規化、各模式切換；關鍵字搜尋以 mock 取代。
- **`fulltext` 群組（預設不執行）**：真正對 MySQL FULLTEXT 下 SQL。因為 InnoDB FULLTEXT 看不到未提交的資料，這組測試不使用 DatabaseTransactions，而是在測試資料庫 `rag_poc_testing` 提交資料（文件名稱前綴 `fulltext-test-`），結束時刪除。

  ```bash
  php artisan test --group=fulltext
  ```

  涵蓋：阿拉伯數字條號命中中文數字條文、含連字號的編號不被當成排除、1 字片段的編號（HRIS-3）、之一條號、自然語言排序、刪除文件後不再回傳、未建立索引的文件被排除。**修改 SearchTextNormalizer、KeywordSearchRepository 或 MySQL FULLTEXT 設定後必須執行；打 tag 前再跑一次。**

## 下一章

Reranker：Hybrid 先取 Top 20，再由 Cross-encoder 重新排序挑出 Top 5。本章保留的 `dense_rank`、`keyword_rank`、`exact_match`、`rrf_score` 用來比較 Rerank 前後的排名變化；Recall@1 下降的 q09、q10 是第一批觀察對象。
