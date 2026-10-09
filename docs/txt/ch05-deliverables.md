# Ch05 Chunking（結構優先的切段）：交付重點

> 對應教學文件：第四階段（Chunking）
> 前置章節：Ch03（`document_pages` 逐頁文字、Normalization）、Ch04（中文字數與 Token 比例、num_ctx 預算）

## 本章目標

把 `document_pages` 的逐頁文字切成語意完整的 `document_chunks`，採用**結構優先、大小為輔**的切法：先依章、條、標題切分，過長的段落再依段落、換行、句號遞迴切分，並在被迫切開處保留重疊。每個 Chunk 都要正確記錄 `page_start`、`page_end`、`section`，長度控制在 Embedding 模型的輸入上限內。本章不呼叫任何 AI。

```
documents.status = parsed
  → Dispatch ChunkDocumentJob
  → PageTextAssembler：依頁碼串成全文 + 建立 offset map（每頁在全文中的起訖字元位置）
  → StructureSplitter：依行首的「第 X 章」「第 X 條」、Markdown 標題切出結構段落，並記錄 section
  → RecursiveSplitter：單段過長時，依 段落 → 換行 → 句號 → 字元 遞迴切分，切開處保留對齊句子的 Overlap
  → 以 Chunk 的起訖位置反查 offset map → page_start / page_end
  → 刪除該文件的舊 Chunk → 寫入 document_chunks
```

## 一、程式交付

### 前置檢查

- [ ] **確認 Ch03 的 Normalization 有保留換行**。條號辨識只比對行首，若當時把換行全部合併掉，需先調整 Ch03 並重新解析測試文件
- [ ] 確認頁首、頁尾已在 Ch03 移除，否則會混進 Chunk 內容

### 資料表

- [ ] Migration：`document_chunks`
  - [ ] `id`、`document_id`（外鍵，文件刪除時一併刪除）、`chunk_index`
  - [ ] `content`（原文，Citation 顯示用）
  - [ ] `char_count`、`token_count`（本章以字元數近似估算）
  - [ ] `page_start`、`page_end`
  - [ ] `section`（例如「第三章 請假 / 第 12 條」，無結構文件可為 null）
  - [ ] `chunk_strategy`（策略與參數版本，例如 `structure_v1`，Ch08 比較設定時使用）
  - [ ] `created_at`
  - [ ] 索引：(`document_id`, `chunk_index`) 唯一

### 核心類別

- [ ] `PageTextAssembler`
  - [ ] 依 `page_number` 排序串成全文，頁與頁之間以換行連接
  - [ ] 回傳全文與 offset map，提供「字元位置 → 頁碼」的查詢方法
- [ ] `StructureSplitter`
  - [ ] 辨識行首的章：`第一章`、`第 1 章`
  - [ ] 辨識行首的條：`第 12 條`、`第十二條`、`第12條之1`、`第十二條之一`
  - [ ] 辨識 Markdown 標題（`#`～`###`）
  - [ ] 內文中的「依第 X 條規定」**不得**被當成邊界
  - [ ] 維護目前所在的章與條，產生 `section` 字串
  - [ ] 章標題本身不單獨成為 Chunk，而是作為其下各條的 section 資訊
- [ ] `RecursiveSplitter`
  - [ ] 分隔順序：空行（段落）→ 換行 → 句末標點（。！？；）→ 字元
  - [ ] 只有上一層切出來仍超過上限時，才往下一層切
  - [ ] Overlap 只用在同一段落被迫切開的位置，且對齊句子邊界；條與條之間不加重疊
  - [ ] 切得過碎的尾段（低於最小長度）併回前一段
- [ ] `ChunkingService`
  - [ ] 組合上述元件，輸出 Chunk DTO 清單（含起訖字元位置）
  - [ ] 以起訖位置反查 `page_start` / `page_end`
  - [ ] 沒有任何結構的文件，自然退化成段落與句子切分，不另寫一套邏輯
- [ ] `TokenEstimator`
  - [ ] 以字元數近似估算 Token 數，係數放在 config
  - [ ] 係數要保守：Ch04 量到的是 qwen3 的 Tokenizer，bge-m3 的 Token 數會不同，Ch06 再實測校正

### 設定

- [ ] `config/rag.php`（或既有設定檔）新增 chunking 區段，不寫死在程式裡：
  - [ ] `max_tokens`（建議起點 500～800）
  - [ ] `overlap_ratio`（建議起點 10%～20%）
  - [ ] `min_tokens`（過短尾段的合併門檻）
  - [ ] `strategy`（`structure` / `fixed`，供實驗比較）
  - [ ] `chars_per_token`（估算係數）

### Job、狀態與指令

- [ ] `ChunkDocumentJob`：文件解析完成後自動 Dispatch
  - [ ] **冪等**：先刪除該文件的舊 Chunk，再於同一個 Transaction 內寫入
  - [ ] 失敗時將 `documents.status` 設為 `failed` 並寫入 `error_message`
  - [ ] 狀態流轉沿用教學文件的定義；若需要中間狀態（例如 `chunked`），在 `docs/notes/` 註明理由
- [ ] Artisan 指令 `rag:chunk {document} --strategy= --max-tokens= --overlap=`：重新切段，方便實驗
- [ ] Artisan 指令 `rag:chunks {document}`：以表格列出 `chunk_index`、`section`、頁碼、長度與內容開頭，並輸出長度統計（最短、平均、最長、超過上限的數量）

## 二、測試交付

- [ ] 測試用文件（放在 `tests/fixtures/`）
  - [ ] 一份中文規章：包含章、條、「之一」條號，至少一條跨頁，至少一條長到必須被切開，內文中出現「依第 X 條規定」
  - [ ] 一份沒有結構的純 TXT
  - [ ] 一份 Markdown 文件
- [ ] Unit Test：`PageTextAssembler` 的 offset map 與頁碼反查正確（含頁面邊界上的字元）
- [ ] Unit Test：`StructureSplitter` 能辨識各種條號寫法，內文引用不會被當成邊界
- [ ] Unit Test：`section` 正確帶入章與條
- [ ] Unit Test：`RecursiveSplitter` 切出的每段都不超過 `max_tokens`，Overlap 對齊句子，過短尾段會被合併
- [ ] Unit Test：跨頁條文的 `page_start` / `page_end` 正確
- [ ] Unit Test：無結構文件能退化成段落與句子切分
- [ ] Feature Test：解析完成後會 Dispatch `ChunkDocumentJob`（`Queue::fake()`）
- [ ] Feature Test：對同一份文件重複切段，Chunk 數量不會增加

## 三、實驗與紀錄

結果寫入 `docs/notes/ch05-experiments.md`。本章的實驗以目視檢查與統計為主；切法對檢索品質的真正影響，要等 Ch08 有了測試集才能用 Recall@K 比較。

- [ ] **固定大小 vs 結構優先**：同一份規章各切一次，記錄 Chunk 數量、長度分布，以及有幾條規定被切成兩半
- [ ] **長度分布**：記錄最短、平均、最長長度，找出極短碎片與接近上限的 Chunk
- [ ] **跨頁抽查**：列出所有跨頁的 Chunk，逐一比對原始 PDF 頁碼
- [ ] **條號誤判**：搜尋內文中所有「第 X 條」，確認沒有被誤切
- [ ] **無結構文件**：觀察純 TXT 與 Markdown 的切分結果是否合理
- [ ] **Context 預算估算**：以 Top-5 × 平均 Chunk 長度，加上 System Prompt 與回答空間，確認放得進 `OLLAMA_NUM_CTX`（8192），為 Ch09 預留依據

## 四、驗收

- [ ] 上傳文件後自動產生 Chunk，可依 `chunk_index` 逐段檢視
- [ ] 抽查至少 10 個 Chunk，條號與頁碼正確，包含跨頁的條文
- [ ] 沒有規定在不必要的地方被切斷；被迫切開處有保留對齊句子的重疊
- [ ] 所有 Chunk 的估算長度都低於 `max_tokens`，且遠低於 bge-m3 的輸入上限（8192 tokens）
- [ ] 重新執行切段不會產生重複的 Chunk
- [ ] 調整 config 參數後重新切段，結果會跟著改變，不需要修改程式
- [ ] 能用自己的話說明：Chunk 太大和太小各會造成什麼問題，以及為什麼規章適合以「一條一段」為主

## 五、文件與收尾

- [ ] `docs/notes/ch05-experiments.md`：實驗紀錄
- [ ] `docs/notes/ch05-summary.md`：篇章總結，包含結構優先切法、Recursive Chunking、Overlap 的使用時機、跨頁頁碼的處理方式、Chunk 大小與 Embedding 上限及 Context 預算的關係
- [ ] `CLAUDE.md` 與 `AGENTS.md` 補上規則：
  - [ ] 切段參數一律從 config 讀取
  - [ ] `content` 保存原文，不在其中加入前綴或任何加工文字
  - [ ] 重新切段必須先刪除舊 Chunk
- [ ] commit 並打上 tag `ch05-done`

## 六、明確不做

Embedding（Ch06）、Qdrant 與 Vector Search（Ch07～Ch08）、任何 LLM 呼叫、Semantic Chunking、以實際 Tokenizer 精確計算 Token、「章節標題前綴」的 Embedding 用文字（Ch06 決定是否採用）、複雜表格處理。這些要寫進給 Agent 的 Prompt 裡，避免它順手做過頭。

## 附：本章新知識點

| 類別 | 名詞 |
| --- | --- |
| Chunking | Chunk、Chunk Size、Chunk Overlap、Recursive Chunking、Structure-aware Chunking、Fixed-size Chunking、Semantic Chunking（概念） |
| 結構辨識 | 章／條／項層級、行首比對、內文引用誤判、Section Metadata |
| 位置對應 | Offset Map、字元位置反查頁碼、跨頁 Chunk |
| Token | Token-based Chunking、Tokenizer 差異（qwen3 vs bge-m3）、字元數近似估算 |
| 連動限制 | Embedding 最大輸入長度、Context 預算（Top-K × Chunk Size） |
| 工程 | 冪等重建（Idempotent Re-chunking）、策略版本 `chunk_strategy`、參數外部化 |
