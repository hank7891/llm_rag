# Ch04 總結：直接把文件交給 LLM

## 成果

```
POST /api/documents/{document}/ask   或   php artisan rag:ask-doc {document} {question}
  → DocumentQaService：檢查狀態（parsed 以後才可提問，否則 409）
  → DocumentContextBuilder：依頁碼組合全文，每頁加【第 N 頁】，統計字數與粗估 Token
  → Messages：system = resources/prompts/document-qa.md（只放規則）
               user   = <document>全文</document> + 問題
  → ChatService.chat（num_ctx 透過 providerOptions 覆寫）
  → answer + usage + meta（prompt_eval_count、粗估 Token、truncated_suspected、耗時）
```

刻意不使用 RAG，作為之後比較的 baseline。實驗紀錄見 `ch04-experiments.md`。

## Context Window 與 num_ctx

- **Context Window**：模型一次能處理的 Token 上限，包含 System Prompt、文件、問題，以及要產生的回答。
- **num_ctx**：Ollama 實際分配的 Context 大小。qwen3:8b 的上限是 40960，但 Ollama 不會自動用到上限，必須在每次請求明確帶入。
- **KV Cache**：num_ctx 越大，佔用的記憶體越多（實測 4096 → 32768：5.6GB → 9.9GB）。記憶體不足時會從 GPU 掉到 CPU，速度大幅下降。
- **雲端不一樣**：雲端模型的 Context 是固定的，超過時直接回錯誤，不會靜默截斷。

## 截斷判斷方法（實測後修正）

| 原本的假設 | 實測結果 |
| --- | --- |
| 截斷時砍掉後面，答不出結尾 | **砍掉前面**，答不出開頭與中段，System Prompt 也一起被砍 |
| prompt_eval_count 會接近 num_ctx | **只剩約一半**（4096 → 2050，8192 → 4098） |

因此截斷判斷改為兩個條件同時成立：

1. 送出前以字數粗估 Token 數（`TokenEstimator`：中文每字 0.75、其他字元 0.3），**粗估值 > num_ctx**；
2. **實際處理的 Token 數（prompt_eval_count）< 粗估值的 80%**。

只用條件 1 會在估算不準時誤判；只用條件 2 分不出「本來就很短的文件」與「被截到很短的長文件」。判斷由 OllamaProvider 負責（只有它同時知道 num_ctx 與 prompt_eval_count），結果放在 `ChatResult::$inputTruncated`；雲端為 null（不會發生）。偵測到截斷時，ChatService 另記一筆 `llm.chat.input_truncated` 警告。

另一個選擇是讓截斷直接變成錯誤：`OLLAMA_TRUNCATE=false`（或 `--no-truncate`），Ollama 會回 HTTP 400 並附上精確 Token 數。本章為了觀察截斷維持預設 true；之後的 RAG 章節建議改為 false。

## 設計上的取捨

- **num_ctx 的逐次覆寫用 `providerOptions`**（Ch01 規劃的逃生口）：`ChatOptions(providerOptions: ['ollama' => ['num_ctx' => 4096]])`，各 Provider 只讀自己那組，雲端直接忽略。沒有把 num_ctx 升格為共通欄位，因為雲端的 Context 不能設定，設了卻被忽略反而誤導。
- **指令與資料分離**：System 只放規則，文件放在 user 的 `<document>` 標籤內；文件內容裡的 `<document>` 標籤改為全形，避免提前關閉。實驗證實：強化版的 Prompt Injection 在無防護時讓 Gemini 與 qwen3 回答「我是 GPT」，加上防護後三家都擋住。
- **System Prompt 獨立成檔案**（`resources/prompts/document-qa.md`），修改規則不用改程式。

## 長 Context 做法的適用範圍與極限

**適合**：文件少、短（幾千到一兩萬 Token）、同一份文件會問很多次（可利用 Prompt 快取）、需要整份文件的脈絡（例如摘要）。

**極限**：

1. **容量**：Context 有上限，數百份文件不可能一次放進去；地端還受記憶體限制。
2. **隱蔽的失敗**：超過上限時 Ollama 靜默截斷，連 System Prompt 一起砍掉，回答看起來像「文件裡沒有」。
3. **速度與成本**：地端每換一份文件都要重新計算（實測 2～3 分鐘）；雲端每題都要付整份文件的 Token 費用。
4. **引用不可靠**：模型自己標註的頁碼會出錯（qwen3 把第 7 頁標成第 5 頁）。
5. **Lost in the Middle**：本次 1.25 萬 Token 的文件未觀察到，但更長的 Context 中段資訊較容易被忽略。

→ 這些極限就是 Ch05 Chunking 與之後檢索的動機：只把和問題相關的幾段送給模型，並由程式記住每一段的頁碼。

## 其他觀察

- 中文 Token 比例：qwen3 與 Gemini 每字約 0.75，gpt-4.1-mini 約 0.87。同一段文字，不同模型可以差 18%。
- Prompt 快取：同一份文件的後續問題從 2～3 分鐘降到 1～8 秒；截斷時快取幾乎無效。
- 章節編號：教學文件的「直接交給 LLM」排在本章（Ch04），Chunking 移到 Ch05。
