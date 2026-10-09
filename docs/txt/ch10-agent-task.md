# Ch10 Agent 任務：Citation（來源對應與顯示）

> 對應規範：《地端 LLM 與 RAG 基礎實作教學 v3.1》十一、第九階段：加入來源 Citation
> 相關段落：四（第二階段：逐頁保存）、六（第四階段：section 與頁碼）、八（第六階段：資料同步）、十八（Agent Prompt 6 後半）、二十（最終驗收第 2、5 項）
> 前一章：Ch09 完成基本 RAG（`ch09-done`）

## 0. 任務範圍

Ch09 已經讓 LLM 以 `[n]` 標示引用，並在回應中附上編號對照表。本章把這些編號**以固定規則（deterministic）**對應回檔名、條號與頁碼，並處理所有不合規的引用。

```
RagAnswerService（Ch09）
↓
LLM 原始回答（含 [n]）+ ContextBuilder 編號對照表（n → chunk_id）
↓
CitationResolver
  ├── 解析回答中的引用標記
  ├── 驗證編號：存在？在本次對照表內？
  ├── 清除不合規標記（超出範圍、非數字，例如 [資料不足]）
  └── 以 chunk_id 向 MySQL 查詢來源（MySQL 為資料來源，Qdrant Payload 不作為顯示依據）
↓
CitationFormatter
↓
Answer + Sources（只列出實際被引用的來源）
```

**核心原則：來源資訊只能由程式產生。** LLM 只輸出編號，檔名、條號、頁碼一律由程式依資料庫內容組出。任何 LLM 自行寫出的檔名或頁碼都不得顯示為來源。

**完成本章後請停止。以下項目不屬於本章，請勿實作：**

- Hybrid Search、Sparse Vector、MySQL FULLTEXT（Ch11）
- Reranker（Ch12）
- Query Rewriting、多輪對話 RAG（Ch13）
- `/knowledge/chat` 等 UI 頁面、Streaming 回答的引用處理（Ch14）
- 句子層級的出處標示（highlight 原文位置）
- LLM-as-Judge、Faithfulness 自動評估（規範列為下一階段）
- 修改 Embedding 模型、Collection、門檻值、Top-K 或 ContextBuilder 的編號方式

## 1. 前置條件

開始前先確認以下項目。任何一項不符合就回報，不要自行補做。

- repo 已有 tag `ch09-done`
- `RagAnswerService` 回傳 status 三種：已回答、門檻擋下、LLM 判斷不足
- `ContextBuilder` 會產生編號對照表，每個編號至少能對應到 `chunk_id`
- `document_chunks` 有 `page_start`、`page_end`、`section`，`documents` 有 `name`
- `docs/notes/ch09-experiments.md` 已記錄 Ch10 待辦：過度引用（OpenAI r01 引用 4 筆）、引用格式錯誤（Ollama 的 `[資料不足]`）
- `rag:eval-answer` 可用，測試集含「預期段落」欄位

## 2. 環境與既有規則

- 先閱讀 `CLAUDE.md`、`AGENTS.md`、`docs/notes/ch09-*`，沿用既有命名與設計；若與本檔衝突，先停下來回報
- 問答一律透過 `RagAnswerService`，Controller 不自行處理引用
- `CitationResolver` 與 `CitationFormatter` 必須是**不呼叫 LLM、不依賴外部服務**的純邏輯（查 MySQL 的部分以 Repository 注入，測試時可替換）
- 顯示格式、是否合併同來源等設定從 config 讀取
- qwen3 的 thinking 設定沿用 Ch09 結論（線上預設關閉）

## 3. 執行項目

### 3.1 設定

在 `config/rag.php` 新增 `citation` 區塊：

| 設定 | 說明 | 建議起始值 |
| --- | --- | --- |
| `citation.label_format` | 來源顯示格式 | `{document}　{section}　{pages}` |
| `citation.page_format` | 單頁／跨頁格式 | `第 {start} 頁`／`第 {start}–{end} 頁` |
| `citation.merge_same_source` | 同檔、同條、同頁的多個編號合併成一行顯示 | `true` |
| `citation.strip_invalid` | 自回答中移除不合規標記 | `true` |

### 3.2 引用標記解析

需要辨識的寫法（皆以實際模型輸出為準，先跑一次測試集收集樣本再決定 regex）：

| 寫法 | 處理 |
| --- | --- |
| `[1]`、`[1][3]` | 標準格式 |
| `[1, 3]`、`[1、3]`、`[1-3]` | 拆成多個編號 |
| 全形 `［１］` | 正規化成半形再解析 |
| `[資料不足]`、`[來源]` 等非數字內容 | 視為不合規，移除並記錄 |
| `[9]`（超出本次對照表） | 視為不合規，移除並記錄 |

**不重新編號**：回答中的編號維持 ContextBuilder 給的排名編號，來源清單只列出被引用的編號，因此可能出現 `[1]`、`[3]` 這種有間隔的清單。原因是 Ch14 若改為 Streaming，文字已經顯示在畫面上，事後重編會對不上。

### 3.3 CitationResolver

輸入：LLM 原始回答、ContextBuilder 編號對照表。
輸出 `CitationResult`：

| 欄位 | 內容 |
| --- | --- |
| `answer` | 清除不合規標記後的回答 |
| `citations` | 每個被引用編號的來源：`ref`、`chunk_id`、`document_id`、`document_name`、`section`、`page_start`、`page_end`、`score` |
| `invalid_refs` | 被移除的標記原文與原因（`out_of_range`、`non_numeric`、`source_missing`） |
| `uncited` | status 為「已回答」但沒有任何合法引用時為 `true` |

規則：

- 來源以 `chunk_id` 向 MySQL 查詢；查不到（文件已刪除、索引不同步）時，該編號列入 `invalid_refs`（`source_missing`），並寫入 warning log。這同時是 Ch07 雙寫問題的偵測點
- `section` 為空時省略條號；`page_start == page_end` 時顯示單頁
- status 為「門檻擋下」或「LLM 判斷不足」時，`citations` 必須為空，回答中的任何標記一律移除
- `uncited` 的回答照常回傳，但 API 回應帶出此旗標，並記入 `rag_query_logs`

### 3.4 CitationFormatter

- 依 `citation.label_format` 產生顯示字串，例如：`[1] 員工管理辦法.pdf　第 12 條　第 5 頁`
- `merge_same_source = true` 時，同檔、同條、同頁的編號合併：`[1][3] 員工管理辦法.pdf　第 12 條　第 5 頁`
- 清單依編號排序

### 3.5 Prompt 調整（僅限引用規則）

針對 Ch09 待辦，System Prompt 的引用規則改為：

```
引用資料時，在句末標示編號，例如 [1]；多個來源寫成 [1][2]。
只引用直接支撐該句內容的資料，不要為了完整而列出所有參考資料。
編號只能使用參考資料中出現的數字。
參考資料不足以回答時，只回答「資料不足」，不要加上任何編號或括號。
```

其餘 Prompt 內容不動。修改前後都要保留版本，供第 4 節實驗 2 比較。

### 3.6 整合

- `RagAnswerService` 回傳結果加入 `citations`、`invalid_refs`、`uncited`
- `POST /api/knowledge/ask` 回應格式：

```json
{
  "status": "answered",
  "answer": "依據員工管理辦法，未休完的特休…… [1]",
  "citations": [
    { "ref": 1, "label": "員工管理辦法.pdf　第 12 條　第 5 頁", "document_id": 10, "chunk_id": 101, "section": "第 12 條", "page_start": 5, "page_end": 5 }
  ],
  "warnings": { "invalid_refs": [], "uncited": false },
  "usage": { "input_tokens": 0, "output_tokens": 0 }
}
```

- `rag:ask` 輸出加上「資料來源」區塊；`--show-context` 時另外列出 `invalid_refs`
- `rag_query_logs` 新增：引用數、不合規標記數、`uncited`

### 3.7 評估指令擴充

`rag:eval-answer` 新增以下自動指標（逐題寫入結果檔，並彙總）：

| 指標 | 定義 |
| --- | --- |
| 引用率 | 已回答題中，至少有一個合法引用的比例 |
| 命中率（Citation Hit） | 已回答題中，被引用的 chunk 至少一個屬於測試集「預期段落」的比例 |
| 平均引用數 | 每題合法引用的平均數量（觀察過度引用） |
| 不合規標記數 | 依原因分類加總 |
| 不足題零引用 | 資料不足題的回答是否完全沒有來源 |

注意：命中率只能證明「引用到對的段落」，不能證明「該句話真的有依據」。句子是否被引用內容支撐，仍以人工抽查為準。

## 4. 實驗

結果寫入 `docs/notes/ch10-experiments.md`。

1. **兩個 Provider 的引用品質**：Ollama（think 關閉）與 OpenAI，以 Ch09 的完整測試集跑 `rag:eval-answer`，列出第 3.7 節所有指標。
2. **Prompt 調整前後**：以 3.5 修改前、後的 Prompt 各跑一次，比較平均引用數與不合規標記數，重點觀察 OpenAI r01 與 Ollama 的 `[資料不足]`。
3. **讓模型自己寫來源（對照組）**：另做一個實驗用的 Prompt 變體，參考資料中附上檔名、條號、頁碼，要求模型直接寫出「檔名、條號、頁碼」。抽 10 題比較模型寫的來源與資料庫實際值，記錄錯誤筆數與類型（頁碼錯、條號錯、檔名不存在）。此變體只存在實驗程式中，不進入正式流程。
4. **刪除文件**：刪除一份會被引用的文件，確認 Qdrant Points 已同步刪除後，以原本會引用它的問題重問，確認來源中不再出現該文件。另以測試手法模擬「Qdrant 仍有 Point、MySQL 已刪除」，確認 `source_missing` 被正確攔下。
5. **Ch09 的 q08 再檢查**：用 `rag:ask --show-context` 檢視 Ollama q08 的 [2]。引用合法但內容不支撐句子時，本章程式不會擋下；請記錄這個案例，作為「引用存在 ≠ 引用正確」的說明。

## 5. 驗收

1. 有答案題的回答能顯示正確的檔名、條號與頁碼，且來源全部由程式產生
2. 回答中不存在任何超出範圍或非數字的引用標記
3. 門檻擋下與 LLM 判斷不足的回答，來源清單皆為空
4. 刪除文件後，問答不再引用該文件（規範驗收第 5 項）
5. MySQL 查不到來源時不會顯示該來源，且有 warning log
6. 兩個 Provider 的實驗 1 指標已記錄；實驗 2～5 已完成並寫入筆記
7. 不合規標記數相較 Ch09 有下降，或已說明無法下降的原因
8. Unit / Feature Test 全部通過，至少包含：
   - 各種引用寫法的解析（3.2 表格全部情境）
   - 超出範圍、非數字、`source_missing` 三種不合規情況
   - 合併同來源、單頁與跨頁格式、`section` 為空
   - 不足或擋下時 `citations` 為空
   - `uncited` 旗標
   - Resolver 不呼叫 ChatService（以 Fake 驗證）

## 6. 完成後回報

1. 新增與修改的檔案清單，以及每個 Class 的責任
2. 與 Ch09 `ContextBuilder`、`RagAnswerService` 的銜接方式；若有修改 Ch09 的程式，說明原因
3. 實際收集到的引用寫法樣本，以及最終採用的解析規則
4. 一次 `rag:ask --show-context` 的完整輸出範例（含資料來源區塊）
5. 實驗 1～5 的結果摘要
6. 說明：為什麼來源以 MySQL 查詢、而不是直接用 Qdrant Payload；為什麼不重新編號

回報後先停下來，等使用者確認。

## 7. 收尾（使用者確認後執行）

- `docs/notes/ch10-summary.md`：篇章總結
- `CLAUDE.md` 與 `AGENTS.md` 補上規則：
  - 來源顯示一律由 `CitationResolver` / `CitationFormatter` 產生，不得使用 LLM 輸出的檔名或頁碼
  - 回答中的引用編號沿用 ContextBuilder 排名編號，不重新編號
  - 修改引用相關 Prompt 時，必須重跑 `rag:eval-answer` 並記錄引用指標
- commit 並打上 tag `ch10-done`
