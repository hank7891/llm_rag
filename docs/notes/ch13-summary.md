# Ch13 總結：Query Rewriting 與 Conversation Memory

## 成果

```
POST /api/knowledge/ask（conversation_id 選填）／ rag:ask --conversation= ／ rag:chat
  → RagAnswerService
       對話：沒帶 conversation_id 時建立新對話；有帶時從 MySQL 讀取最近 N 輪
       HistoryWindow：最近 window_turns 輪 → 移除 [n] 與來源清單 → 超過 history_budget_chars 時從最舊的一輪捨去
       QueryRewriter：沒有歷史 → skipped（不呼叫 LLM）
                      有歷史 → 改寫 Provider（follow：跟隨本次回答 Provider）→ rewritten / unchanged / fallback
       檢索（Hybrid + Reranker）一律使用改寫後的問題 → 無候選時不呼叫回答 LLM
       回答 LLM：System + 最近 N 輪（已移除編號）+ <reference> + 原始問題
       conversation_messages：使用者訊息存原問題與改寫結果，助理訊息存原始回答
```

- 資料表：`conversations`、`conversation_messages`；`rag_query_logs` 加上 conversation_id、改寫結果、改寫狀態、改寫耗時。
- `RagAnswer` 新增 conversation_id、original_question、rewritten_question、rewrite_status、rewrite_called、rewrite_ms、rewrite_usage、history_turns；`llm_called` 意義不變。
- 改寫狀態：skipped（第一輪）、rewritten、unchanged（結果與原問題相同，忽略空白與標點）、fallback（逾時、空白、過長、含「資料不足」、該 Provider 沒有改寫設定）。
- 工具：`rag:chat`（終端機連續提問）、`rag:eval-conversation`（none / concat / llm 三組對照、Window、改寫 Provider、Prompt 版本、think）。
- 多輪測試集 `tests/rag-set/conversations.jsonl`：16 題，歷史為腳本化內容，評估才可重現。主表 13 題；歧義題 h13 與無答案題 n01、n02 分開統計。

## 最終設定

| 項目 | 設定 |
| --- | --- |
| 改寫 Provider | `follow`：跟隨本次實際使用的回答 Provider（含 API 參數、`rag:ask --provider` 逐次指定的值），可明確指定 ollama / openai 覆寫 |
| Ollama | Prompt v3、think 開啟、逾時 120 秒 |
| OpenAI | Prompt v3、逾時 15 秒 |
| 其他 Provider（例如 Gemini） | 沒有改寫設定 → 降級為原問題、寫 warning log，不改用另一家 |
| Window | 5 輪、歷史預算 1500 字元 |

### 為什麼是 follow

- **不會因為改寫而引入回答流程以外的雲端供應商**：回答走地端時，改寫也走地端；只有使用者已經選擇雲端回答時，改寫才送到同一家雲端。
- **例外**：改寫後沒有候選時，回答 LLM 不會被呼叫，這次改寫就是唯一一次外送。
- 缺設定時不改用另一家，理由相同：改寫會把對話內容送出去，不能因為缺設定就把資料送給使用者沒選的供應商。

### 沒有改寫設定時的降級

1. `QueryRewriter` 依 follow 得到本次的 Provider（例如 gemini），在 `rag.conversation.rewrite.providers` 中找不到設定。
2. 不呼叫任何 LLM：`status = fallback`、`called = false`、`failure_reason = no_rewrite_config`。
3. 寫 warning log `rewrite.fallback`，內容為 `{"provider": "gemini", "reason": "no_rewrite_config", "question": "那兩年年資呢？"}`。
4. 以原問題檢索，問答照常進行。

## 評估結論（Reranker off、N = 5，主表 13 題）

| | Recall@1 / @5 / MRR | 改寫耗時 p50 / p95 | 忠實度 ✔ / △ / ✘ |
| --- | --- | --- | --- |
| A 不改寫 | 38% / 46% / 0.423 | — | — |
| B 串接歷史問題 | 62% / 100% / 0.776 | — | — |
| qwen3 v1（think 關閉） | 62% / 62% / 0.615 | 1.3 / 2.5 秒 | 9 / 1 / 5 |
| qwen3 v3（think 關閉） | 54% / 62% / 0.577 | 1.5 / 2.7 秒 | 9 / 0 / 6 |
| **qwen3 v3（think 開啟，最終設定）** | **85% / 100% / 0.923** | **29 / 77 秒** | **15 / 0 / 0** |
| OpenAI v3 | 100% / 100% / 1.000 | 1.0 / 4.5 秒 | 13 / 2 / 0 |

- 單輪回歸：questions、hybrid、reasoning 三份測試集的檢索結果與 Ch12 完全相同；單輪問答送進回答 LLM 的訊息逐題相同（輸入 Token 相同），13 題都不呼叫改寫。
- 回答 LLM 最大 input_tokens：1,436（num_ctx 8192 的 18%）。
- think 開啟時，16 題的 `done_reason` 都是 stop，改寫的 prompt + output Token 最大 964，沒有撞到上限。

## 觀察

### 關閉思考時，qwen3 解不開回指

- think 關閉時，「第一個問題的…」這類回指，qwen3 幾乎都原樣輸出（h05、h06、h14），v1、v2、v3 都一樣，N = 1、3、5 也都一樣。
- v3 開啟思考後，忠實度從 9 / 0 / 6 提升到 15 / 0 / 0，回指與編號題全部補對。
- 代價是延遲：改寫 p50 約 29 秒、p95 約 77 秒（M1），比回答 LLM 還久；原本 30 秒的逾時會讓約一半的題目降級。
- 改寫輸出很短，但開啟思考後 `eval_count` 是 200～800：同一個請求在 think 開啟時為 185，關閉時為 9。Ollama 文件只寫 `eval_count` 是「number of tokens in the response」，沒有說明是否包含 thinking，所以這只是觀察，未經文件確認。

### 小模型對 Prompt 規則很敏感

- 同條件（think 關閉、N = 3）下，v1（教學文件原文兩行）的 Recall@1 高於 v2（多四條規則）：64% vs 57%（當時 h13 尚未改為歧義題，以 14 題計算）。
  - v2 修好了編號保留（h12），卻讓其他題原樣輸出（h11、n02）。
  - v2 還把括號例子「表單編號」抄進改寫結果（h01）。
- N = 5、think 關閉時，v1 Recall@1 62%、v3 54%（@5 都是 62%）。v3 拿掉括號例子並改寫回答內容規則後，較常原樣輸出：16 題中 v3 有 10 題 unchanged，v1 是 6 題。
- think 開啟只測了 v3，**不能推論各版本在開啟思考時的優劣**。
- 每加一條規則都可能在別題產生副作用：修改 Prompt 後一定要重跑整份 `rag:eval-conversation`，不能只看針對的那一題。

### Sliding Window 的靜默失敗

- h14：歷史 4 輪，追問「第一個問題的那筆費用是誰負擔？」回指第 1 輪（識別證補發）。
- OpenAI 改寫在 N = 3 時只看得到第 2～4 輪，「第一個問題」被對到 Window 中的第一輪（事假），改寫成「事假期間所產生的費用是由誰負擔？」。
  - 改寫看起來合理但主題是錯的；狀態仍是 rewritten，沒有任何錯誤或降級訊號。
  - N = 1 時被對到筆電，N = 5 時才命中。
- qwen3（think 關閉）在各種 N 都原樣輸出，看不出 Window 的影響；這個現象要在改寫能力足夠的模型上才觀察得到。
- 對策是 Conversation Summary（把 Window 之外的較早輪次壓成摘要保留），本章不實作。

### 長回答會先觸發 history_budget_chars

- 除了 `window_turns` 的輪數上限，長回答也可能先觸發 `history_budget_chars`，使實際保留的輪數少於設定值。
- `rag:chat` 實測（conversation 4）：每輪回答約 400～600 字時，第 5 輪的 4 輪歷史約 1,700 字，超過 1,500 字預算，Window 5 只保留 3 輪，最舊的一輪整輪被丟掉。
- 相同的 4 個問題再跑一次（conversation 5），回答較短（共 1,226 字），沒有截斷：同一段對話會不會截斷，取決於每次回答的長度。
- 截斷和 Window 一樣是靜默的，只記在 `conversation.history` log（`in_window`、`kept`）。改進方向列入 backlog #19（完整保留使用者問題，只截短助理回答）。

### 改寫 LLM 會把無意義的輸入補成上一輪的主題

- 在 `rag:chat` 中送出「x」，qwen3（think 關閉）把它改寫成「服務滿二年以上未滿三年者的特別休假天數是多少？」，也就是上一輪的主題。
- 回答 LLM 看的是原問題「x」，所以回答「資料不足」；如果回答 LLM 也拿改寫後的問題，就會回答一個使用者沒問的問題。
- 改寫的驗證（空白、過長、像答案）攔不到這種情況，因為輸出格式完全正常。這也是回答階段送「原始問題」的理由之一。

### 改寫產生的泛用詞會讓 Dense 分數上升

- n01（無答案）：「那員工宿舍呢？」不改寫時 Dense 最高 0.5595，低於門檻 0.59。
- OpenAI 搭配 v2 改寫成「請問公司有提供員工宿舍的**相關規定或資訊**嗎？」，Dense 升到 0.5978，超過門檻，變成「有候選」並呼叫回答 LLM，只剩第二道防線。
- 泛用詞和任何規章段落都有點像，會把分數普遍往上推；而 Dense 門檻是用單輪的原始問題校準的（Ch08）。
- v3 之後兩種改寫模型都沒有再加泛用詞（n01 0.5741），但改寫設定改變後，仍要確認無答案題是否被放行。

### 其他

- **串接歷史（B）不用 LLM，Recall@5 卻達到 100%**，但話題切換與完整問題的 Recall@1 下降，無答案題也會被放進回答 LLM。
- **h13（歧義題）是「改寫比不改寫差」的反例**：上一輪問「補助上限」，追問「那識別證補發呢？」照字面被補成「識別證補發的補助上限」，大多數改寫組都找不到；不改寫時第 1 名命中。
- **回答 LLM 沒有固定 temperature**：同一次改寫與檢索，回答一次「十日」、一次「七日」（backlog #13）。

## 限制與後續

- Conversation Summary 未實作：回指 Window 之外，或被預算丟掉的輪次時，會靜默失敗。
- 改寫延遲：think 開啟時每次追問多等約 20～77 秒（M1）。換機器要重新量測逾時。
- 實驗 5（回答時同時附上改寫後問題）未做。
- 測試集小（主表 13 題），單題差異就是 8 個百分點。
