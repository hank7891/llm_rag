# Ch14 最終驗收（教學文件第二十章）

- 日期：2026-10-09
- 線上設定：
  - Embedding bge-m3，門檻 > 0.59。
  - 檢索 Hybrid（exact_only），Reranker 開啟（候選 20、keep_exact、prefix_metadata）。
  - 改寫 follow（ollama v3 think／openai v3），Window 5。
- 驗收用文件：`國內出差旅費報支辦法.pdf`（#20，3 頁、10 Chunk）。由使用者自行準備的可外送內容，驗收後已刪除。
- 截圖：`docs/notes/ch14-screenshots/`。每張都已逐張檢查畫面內容是否符合該項驗收，結果寫在各項的「截圖檢查」。

## 總表

| # | 驗收項目 | 結果 |
| --- | --- | --- |
| 1 | 同一問題可切換地端與雲端模型回答 | ✔ |
| 2 | 有答案的問題能正確標示文件、條號與頁碼 | ✔ |
| 3 | 沒有答案時回答資料不足，未通過門檻時不呼叫 LLM | ✔ |
| 4 | 追問能找到正確段落 | ✔（截圖 04 已重拍，改寫後的問題已展開） |
| 5 | 刪除文件後問答不再引用 | ✔ |
| 6 | 測試集 Recall@K 有記錄並可比較 | ✔ |
| 7 | Provider 切換不需修改業務邏輯 | ✔ |
| 8 | 至少比較兩個 Embedding 模型 | ✔（附註：qwen3 的 Collection 不是最新） |
| 9 | 只支援 Chat 的 Provider 不實作 Embedding 介面 | ✔ |
| 10 | 門檻比較方向已依 Vector DB 與 Distance Metric 驗證 | ✔ |

## 1. 同一問題可切換地端與雲端模型回答

**截圖**：`03-answer-citation.png`（Ollama，對話 #10）、`06-openai.png`（OpenAI，對話 #11）

**截圖檢查**：

- 同一題「去台北出差住宿費上限多少？」，兩張都回答「每晚新臺幣二千八百元 [1]」，來源都是「國內出差旅費報支辦法.pdf　第六條　第 2 頁」。
- 03 上方是綠色「地端模型」提示，下方標示 `ollama / qwen3:8b`。
- 06 上方是黃色「雲端模型：…會送到 OpenAI」，下方標示 `openai / gpt-4.1-mini-2025-04-14`。
- 兩段對話的追問「我上個月剛轉正，可以申請嗎？」都有改寫並作答：Ollama 引用第二條；OpenAI 引用第六條與第二條。
- **符合。**

**rag_query_logs**：

| id | 對話 | 問題 | provider | model | 狀態 | 引用數 |
| --- | --- | --- | --- | --- | --- | --- |
| 390 | 10 | 去台北出差住宿費上限多少？ | ollama | qwen3:8b | answered | 1 |
| 392 | 11 | 去台北出差住宿費上限多少？ | openai | gpt-4.1-mini-2025-04-14 | answered | 1 |

**限制**：答案內容是否與原文一致，由使用者判讀。驗收文件已刪除，無法再從資料庫核對原文。

## 2. 有答案的問題能正確標示文件、條號與頁碼

**截圖**：`01-upload-status.png`、`03-answer-citation.png`

**截圖檢查**：

- 01：#20「國內出差旅費報支辦法.pdf」狀態為索引完成，3 頁、10 Chunk。
- 03：回答帶 [1]，來源清單顯示「[1] 國內出差旅費報支辦法.pdf　第六條　第 2 頁」；追問顯示「[4] …第二條　第 1 頁」。
- 編號沿用參考資料的排名編號，不重新編號（Ch10 規則），所以會出現 [4]。
- **符合。**

**Ch10 引用指標最終重跑**（`docs/notes/ch14-regression/ch14-answers-ollama-*.md`）：

| 指標 | questions.jsonl | reasoning.jsonl |
| --- | --- | --- |
| 引用率 | 23 / 23 | 7 / 7 |
| 命中率（引用到預期段落） | 23 / 23 | 6 / 7 |
| 不合規標記 | 0 | 1（r03：開頭寫「資料不足」又作答） |
| 無答案題未顯示來源 | 6 / 6 | 5 / 5 |

r03、r08 的異常是回答 LLM 的行為，說明見 `ch14-regression.md`。

**程式位置**：

- 來源由 `CitationResolver` 以 chunk_id 向 MySQL 查詢。
- 前端只從 done 事件的 `citations` 產生來源清單（`resources/js/knowledge-chat.js` 的 `renderAnswer()`）。

## 3. 沒有答案時回答資料不足，未通過門檻時不呼叫 LLM

**截圖**：`05-insufficient.png`

**截圖檢查**：

- 「公司有提供員工宿舍嗎？」顯示黃色「資料不足／沒有找到相關的文件段落，未呼叫模型」。
- 「產生回答」被劃掉，沒有來源區塊，下方標示「ollama（未呼叫模型）」。
- **符合。**

**SSE 事件紀錄**（curl 實測，0.4 秒結束）：

```
event: conversation   data: {"conversation_id":6}
event: stage          data: {"stage":"rewriting"}
event: rewrite        data: {"rewritten_question":"公司有提供員工宿舍嗎？","rewrite_status":"skipped"}
event: stage          data: {"stage":"retrieving"}
event: retrieval      data: {"candidates":0,"has_candidates":false,"reranked":false,"rerank_degraded":false}
event: done           data: {"answer":"資料不足","status":"insufficient_no_candidates",…,"llm_called":false,…}
```

沒有 `stage: generating`，也沒有 `delta`。rag_query_logs #396：`insufficient_no_candidates`、`llm_called = 0`、model 為 null。

**測試**：

- `KnowledgeStreamTest::test_no_candidates_sends_done_without_generation_or_llm_call`：事件順序、`llm_called = false`、串流沒有被呼叫。
- 反向驗證：拿掉短路後這個測試會失敗。
- Ch09 的 `RagAnswerTest`：非串流版本的資料不足短路。

**觀察**：這一題是對話中的第 3 題，雖然沒有呼叫回答模型，整題仍花了 33 秒（改寫 32,263 ms）。資料不足的短路只省下回答時間，追問時的改寫成本仍在。

## 4. 追問能找到正確段落

**截圖**：`04-followup.png`（重拍，對話 #13）

**截圖檢查**：

- 「公司的特休規定是什麼？」→「那兩年年資呢？」，第二題的「▼ 檢索用問題（已改寫）」已展開，顯示改寫後的問題。
- 回答「服務滿二年以上未滿三年者，給予十日特別休假 [1]」，來源「員工管理辦法.pdf　第十二條　第 2 頁」。
- 下方標示「ollama / qwen3:8b · 改寫 42415 ms · 檢索 3389 ms · 回答 15568 ms」。
- 截圖同時含有改寫後的問題與來源，**符合**。
- 第一題回答的最後一行「申請系統代碼為HR-2026，相關表單請至內部網站下載 [1]」：已查證第十二條的段落確實含有「HR-2026」與「內部網站」，引用有根據。

**資料證據**（rag_query_logs）：

| id | 問題 | 改寫後（檢索用） | 改寫狀態 | 回答狀態 | 引用數 |
| --- | --- | --- | --- | --- | --- |
| 441 | 公司的特休規定是什麼？ | （第一輪不改寫） | skipped | answered | 4 |
| 442 | 那兩年年資呢？ | 服務滿兩年未滿三年的特別休假天數是多少？ | rewritten | answered | 1 |

- 第一次截圖時的對話 #12（紀錄 #395）改寫成「服務滿兩年未滿三年的年資，特休天數是多少？」。
- 兩次的改寫都帶入了上一輪回答中的「未滿三年」，違反 v3「不要加入對話中回答的內容」的規則，但都沒有影響檢索：第十二條排第 1 名。

**rag:eval-conversation 重跑**（`docs/notes/ch14-regression/ch13-eval-llm-ch14-*.md`）：

- 主表 13 題 Recall@1 / @5 為 100% / 100%，MRR 1.000。
- h01（特休 → 那兩年年資呢？）第 1 名命中。
- 與基準兩次的 16 題逐題完全相同。

## 5. 刪除文件後問答不再引用

**截圖**：`10-after-delete.png`

**截圖檢查**：

- 刪除 #20 後再問「去台北出差住宿費上限多少？」，顯示「資料不足／沒有找到相關的文件段落，未呼叫模型」，沒有來源。
- **符合**。這張截圖同時呈現了問題 1（對話 #12 被保留、先前訊息沒有顯示、經過改寫 34,816 ms），已修正，見 ch14-summary。這次改寫結果與原問題相同，不影響本項。

**rag:index-check**（bge-m3，線上 Collection）：

- 10 份文件全部「一致」，#20 已不在 MySQL。
- **孤兒 Points：無**（MySQL 沒有的 document_id 若還有 Points，會列為孤兒）。

**直接查詢 Qdrant**（`POST /collections/{name}/points/count`，filter `document_id = 20`，exact）：

| Collection | document_id = 20 | 對照：document_id = 17 |
| --- | --- | --- |
| company_docs_bge_m3 | **0** | 4 |
| company_docs_qwen3_embedding_0_6b | **0** | — |

**程式與測試**：

- `DocumentService::delete()` 先刪除所有 `company_docs_*` 中的 Points，成功後才刪除 MySQL。
- `IndexingTest::test_purge_deletes_from_every_project_collection_only`。
- `QdrantIntegrationTest::test_create_write_count_reindex_and_delete`（`--group=qdrant`）。

## 6. 測試集 Recall@K 有記錄並可比較

| 章節 | 紀錄 | 內容 |
| --- | --- | --- |
| Ch08 | `ch08-semantic-search.md`、`rag-eval-company_docs_*-202610051*.md` | Dense、門檻 0.59、兩個 Embedding 模型 |
| Ch09～Ch10 | `ch09-answers-*.md`、`ch10-answers-*.md`、`ch10-experiments.md` | 端到端問答、引用指標（Prompt v1 / v2） |
| Ch11 | `ch11-eval.md`、`ch11-eval/` | Dense / keyword / hybrid、exact_only / allow |
| Ch12 | `ch12-eval.md`、`ch12-eval/` | Reranker A～G 組 |
| Ch13 | `ch13-eval/`、`ch13-eval3/`、`ch13-report-2～5.md` | 多輪：不改寫 / 串接 / 改寫、Prompt 版本、think、Window |
| Ch14 | `ch14-baseline/`、`ch14-regression/`、`ch14-regression.md` | 最終線上設定的回歸 |

**本章重跑總表**（最終線上設定）：

| 測試集 | 有答案題 | Recall@1 | Recall@5 | MRR |
| --- | --- | --- | --- | --- |
| questions.jsonl | 24 | 96% | 96% | 0.958 |
| hybrid.jsonl | 17 | 76% | 76% | 0.765 |
| reasoning.jsonl | 8 | 88% | 100% | 0.938 |
| conversations.jsonl（多輪主表） | 13 | 100% | 100% | 1.000 |

**比較方式**：所有評估指令都把設定寫進結果檔開頭（模型、門檻、模式、Reranker、改寫設定），檔名含 Collection、模式與時間，可以直接並排比較。

## 7. Provider 切換不需修改業務邏輯

**grep**（`app/` 全部，排除 Provider 實作本身）：

```
grep -rnE "(===|!==|==|case)\s*'(ollama|openai|gemini|anthropic)'" app | grep -v "app/Ai/Chat/Providers/\|app/Ai/Embedding/Providers/"
→ 沒有任何符合（exit 1）

git diff ch13-done -- app routes | grep -nE "^\+.*'(ollama|openai|gemini)'"
→ 沒有任何符合（exit 1）
```

**本章的設計**：

- 地端或雲端的標示放在 `config/llm.php` 的 `chat.labels`，由 `ChatProviderCatalog` 提供給畫面。
- 問答頁的 Provider 清單由「有改寫設定的 Provider」推導，不寫死名稱。

**測試**：

- `ChatServiceTest::test_switching_provider_by_name_needs_no_caller_change`、`test_switching_default_provider_by_config_needs_no_caller_change`。
- `ConversationTest::test_follow_uses_rewrite_settings_of_the_answer_provider_given_per_request`：以 Fake 對應 ollama / openai，各自選到自己的改寫設定。

## 8. 至少比較兩個 Embedding 模型

**紀錄**：`ch08-semantic-search.md` 第 84 行起。

- 同一批 76 個 Chunk、同一份測試集，各自建立 Collection。
- 結果：

  | | bge-m3 | qwen3-embedding:0.6b |
  | --- | --- | --- |
  | Recall@1 | 88% | 71% |
  | 換句話說題 Recall@1 | 92% | 58% |

- 線上採用 bge-m3。

**目前的 Collection**（`rag:collections`）：

| Collection | 維度 | distance | points_count |
| --- | --- | --- | --- |
| company_docs_bge_m3 | 1024 | Cosine | 77 |
| company_docs_qwen3_embedding_0_6b | 1024 | Cosine | 72 |

**附註**：

- qwen3 的 Collection 不是最新的。`rag:index-check --model=qwen3-embedding:0.6b` 顯示 #17（員工管理辦法.pdf，4 Chunk）、#19（表單與系統代碼一覽.txt，1 Chunk）未建索引，正好是少的 5 筆。
- Ch08 比較時兩邊資料相同。之後新增的文件只建了線上模型的索引（`rag:index --model=` 不會自動執行）。
- 要再比較時，先執行 `rag:index --all --model=qwen3-embedding:0.6b`。

## 9. 只支援 Chat 的 Provider 不實作 Embedding 介面

教學文件以 `AnthropicProvider` 為例，本專案沒有這個類別；只支援 Chat 的是 OpenAI 與 Gemini。

```
app/Ai/Chat/Providers/OllamaProvider.php:33  class OllamaProvider implements ChatProviderInterface, EmbeddingProviderInterface
app/Ai/Chat/Providers/OpenAIProvider.php:26  class OpenAIProvider implements ChatProviderInterface
app/Ai/Chat/Providers/GeminiProvider.php:24  class GeminiProvider implements ChatProviderInterface
```

- `config/llm.php` 的 `embedding.providers` 只登記 ollama 與 fake。
- `EmbeddingService::provider()` 對未登記的名稱丟出 `UnknownEmbeddingProviderException`，對沒有實作介面的類別直接拒絕。
- 測試：`EmbeddingServiceTest::test_unknown_provider_lists_available_names`。

## 10. 門檻比較方向已依 Vector DB 與 Distance Metric 驗證

**紀錄**：`ch08-semantic-search.md`「Qdrant `score_threshold` 實測」

- 實測分數**大於**門檻才保留，等於門檻的會被排除。
- Cosine 越高越相似，比較方向由 Qdrant 處理。

**程式位置**：

- `app/Rag/VectorStore/CollectionManager.php:13`：`DISTANCE = 'Cosine'`，建立 Collection 時使用。
- `app/Rag/VectorStore/QdrantClient.php:102`：搜尋時把門檻作為 Qdrant 的 `score_threshold` 送出。
- `config/rag.php` `retrieval.score_thresholds`：依模型設定。bge-m3 0.59，依據為無答案題最高 0.5665、有答案題最低 0.6106，取中點。

## 前置作業：`.env` 保護（hook）

`.claude/settings.json` 的 PreToolUse hook 已由使用者改為 `bash "$CLAUDE_PROJECT_DIR/.claude/hooks/protect_sensitive.sh"`，工作階段重啟後重新實測。

- 測試檔名使用 `.env.hooktest`：符合 `*/.env.*` 規則；萬一保護失效也只會產生無害的檔案，不會覆蓋真正的 `.env`。
- 兩次寫入都沒有產生檔案（`ls docs/notes` 沒有 `.env.hooktest`）。

| 工作目錄 | 寫入 `docs/notes/.env.hooktest` | 修正前 | 修正後 |
| --- | --- | --- | --- |
| 專案根目錄 | Write 工具 | 被擋下 | **被擋下**：`PreToolUse:Write hook error: [bash "$CLAUDE_PROJECT_DIR/.claude/hooks/protect_sensitive.sh"]: 錯誤：禁止修改敏感設定檔` |
| `docs/notes`（子目錄） | Write 工具 | **寫入成功**（hook 找不到腳本，exit 127，不阻擋） | **被擋下**：同上 |

## 本章驗收（交接文件 5.6）

| # | 項目 | 結果 |
| --- | --- | --- |
| 1 | 三個頁面完成「上傳 → 處理完成 → 問答與追問 → 刪除」 | ✔ 截圖 01、03、04、10 |
| 2 | 串流與非串流同一題的 done 相同 | ✔ `test_stream_done_matches_non_stream_answer` |
| 3 | 資料不足時沒有生成過程，`llm_called = false` | ✔ 第 3 項 |
| 4 | 含 `<script>` 的文件，問答後畫面不會執行 | △ **未以瀏覽器實測**（使用者未測）。程式與測試：所有輸出以 `textContent` 插入；`test_chat_script_never_inserts_html` 防止日後改用 innerHTML；列表頁刪除確認的 XSS 已修正並有測試 |
| 5 | 問答進行中 `/document` 可正常載入與輪詢 | ✔ curl 實測（4 個 worker，`--no-reload`）：串流期間 `/document` 0.07 秒；使用者未在瀏覽器實測 |
| 6 | `rag:stats` 輸出改寫 fallback 比例與各階段 p50 / p95 | ✔ 見下節 |
| 7 | 回歸：三份測試集檢索結果與基準相同 | ✔ 名次與判斷完全相同，2 題分數小數差異，見 `ch14-regression.md` |
| 8 | 10 項都有證據，未通過的寫明原因 | ✔ 10 項皆通過 |
| 9 | 全部測試與 pint 通過 | ✔ 643 個測試；pint 通過 |

## rag:stats（Ch14 期間：2026-10-09 07:20 起，不含評估提問）

| 項目 | 數值 |
| --- | --- |
| 問答筆數 | 12 |
| 回答狀態 | answered 8、insufficient_no_candidates 3、insufficient_by_llm 0、interrupted 1 |
| 改寫 | skipped 7、rewritten 3、unchanged 2、fallback 0 → **fallback 比例 0%** |
| Reranker | 嘗試 9、降級 0 → **降級比例 0%** |

| 階段 | 筆數 | p50 | p95 |
| --- | --- | --- | --- |
| 改寫 | 5 | 32,263 ms | 36,308 ms |
| 檢索（含重排） | 12 | 420 ms | 3,339 ms |
| 重排 | 9 | 693 ms | 1,763 ms |
| 回答 | 9 | 11,622 ms | 25,227 ms |
| 總計 | 12 | 21,508 ms | 43,244 ms |

- **Ch13 以來**（2026-10-09 全天，含 Ch13 收尾時 think 關閉的實測）：改寫 fallback 0 / 17、降級比例 0%。
- Rerank 降級率只能從 Ch14 開始統計，Ch13 以前的紀錄沒有重排欄位。
- 樣本很少（12 筆），p95 只代表這次驗收的操作。
