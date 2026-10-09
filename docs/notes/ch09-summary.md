# Ch09 總結：完成基本 RAG

## 成果

```
POST /api/knowledge/ask｜rag:ask｜rag:eval-answer
  → RagAnswerService::answer(question, AnswerOptions(provider, source))
     1. RetrieverService：bge-m3 產生問題向量 → Qdrant Top-5（score_threshold 0.59）
        無候選時用同一個向量補查 1 筆（不套門檻），取得被擋下的最高分
     2. 無候選 → 直接回答「資料不足」，不呼叫 LLM（第一道防線）
     3. ReferenceContextBuilder：組成 [1]…[n]（只有編號與內容）與編號對照表
     4. System = resources/prompts/rag-answer.md（只放規則）
        User   = <reference>…</reference> + 問題（指令與資料分離）
     5. ChatService::chat()：Ollama（qwen3:8b，num_ctx 8192）或雲端（gpt-4.1-mini）
     6. 回答含「資料不足」→ insufficient_by_llm（第二道防線）；否則 answered
     7. 寫入 rag_query_logs（失敗不影響回答）
```

工具：`rag:ask "{問題}" --provider= --show-context`、`rag:eval-answer --provider= --set= --label=`；`POST /api/knowledge/ask`。實驗紀錄見 `ch09-experiments.md`，每題回答與人工判讀見 `ch09-answers-*.md`。

## RAG 的兩道「資料不足」防線

| | 第一道：相關度門檻 | 第二道：LLM 依規則判斷 |
| --- | --- | --- |
| 擋得住 | 和文件完全無關的問題（宿舍、停車場、Wi-Fi） | 主題相關但內容沒有答案（婚假天數、育嬰期限） |
| 擋不住 | 標題相關、內容沒有答案的條文（分數 0.64～0.74，比許多有答案的題目還高） | 模型「想幫忙」時把不相關的內容整理成答案（qwen3:8b 的加班費） |
| 代價 | 有答案但問法太短、追問、精確編號被誤擋 | 每題都要呼叫 LLM；地端模型遵守規則的穩定度較低 |

- **沒有候選時不呼叫 LLM**：模型沒有參考資料時只能依記憶回答，最容易產生看似合理、但和公司規定無關的內容；固定回覆也不受模型遵守規則的穩定度影響。30 題中 11 題不需要呼叫 LLM。
- 對使用者而言，門檻擋下的「假沒休完」（0.58）與真正無答案的「員工宿舍」看起來一模一樣；`rag_query_logs` 記錄被擋下的分數，作為日後調整門檻、Hybrid Search、Query Rewriting 的依據。

## 來源由程式掌握

- 送給 LLM 的參考資料**只有編號與內容，不含檔名與頁碼**；編號對照表（chunk_id、文件、條號、頁碼、分數）留在程式端，Ch10 依此顯示來源。
- 理由：長 Context 對照時，qwen3:8b 把健康檢查補助標成第 5 頁（實際第 10 頁），gpt-4.1-mini 標成第 25 頁（文件只有 19 頁）。只讓模型「選編號」，來源就一定是真的存在的那一段。
- 參考資料中的 `<reference>` 標籤改成全形，避免內容提前「關閉」標籤（同 Ch04 的 `<document>`）。

## 和長 Context（Ch04）相比

- 同樣 5 題（三個位置的事實、一題無答案、一題 Injection 所在條文），**結論完全相同**。
- **輸入 Token 下降約 95%**（17,265 → 平均約 630），雲端成本同比例下降。
- 長 Context 在地端的第一題要 2～3 分鐘（超過逾時 120 秒）；RAG 每題 5～8 秒，與文件長度無關。
- 施行日期題的正確條文以 0.6020 排第 2（第 1 名 0.6027）：靠 Top-K 一起送進 Context 才答對，只取 Top-1 就會答錯。

## 地端與雲端

| | qwen3:8b | gpt-4.1-mini |
| --- | --- | --- |
| 30 題測試集（人工判讀，呼叫 LLM 的 19 題） | 17 / 19 + 2△ | 19 / 19 |
| 推理題 + 部分有答案（8 題） | think 關閉 7 / 8；think 開啟 7 / 8 + 1△ | 8 / 8 |
| LLM 平均耗時 | 10.0 秒（think 開啟 31.4 秒） | 1.7 秒 |

- **生成不是瓶頸**：30 題中有答案題的失敗全部發生在檢索端（5 題精確編號題被門檻擋下），LLM 沒有答錯任何一題。
- 單一條文、照抄即可的題目，兩家答對率幾乎相同；差距在**遵守指令的紀律**：qwen3:8b 會延伸內容並附上引用（q08 把公司的作業規定改寫成給員工的建議）、偶爾外洩簡體字；gpt-4.1-mini 較會整合多個段落並補上但書。
- **思考是兩面刃**：需要計算的 r01，think 開啟後答對（推理能力可以用時間換取）；但 Grounding 反而變差（r06 把來源誤植為勞基法、d01 帶出法規倍率）。
- 三組都沒有直接用勞基法回答無答案題：Prompt 規則有效。
- qwen3:8b 的耗時主要由輸入 Token 決定（相關係數 0.91，約每 1,000 個輸入 Token 10 秒）；回答變長時輸出 Token 也有明顯影響（每個約 80 ms）。

## 評估方法

- `rag:eval-answer` 只做兩項自動檢查（無答案題是否資料不足、有答案題是否標示 [n]），**答案正確性由人工判讀**，不用 LLM 自動評分。
- 測試集分兩份：`questions.jsonl`（Ch08 的 30 題，也用於門檻評估）與 `reasoning.jsonl`（推理題、部分有答案、第二道防線）。第二道防線的題目會通過門檻，放進前者會改變門檻評估的數字。
- **三組以相同方式「失敗」時，先檢查題目**：d03「差旅費用怎麼報支？」三組都答流程，因為文件確實有流程；問題出在題目，不是模型。
- **Grounding 檢查要看原文**：q08 的引用編號正確、句子也找得到相近的原文，但主體被改變。這類錯誤比編造更難發現。
- 量測地端耗時要注意模型載入狀態：先載入 bge-m3 再載入 qwen3:8b 時，Ollama 卸載了 bge-m3，第一題檢索因此多了 2.8 秒；剛呼叫過 qwen3:8b 時，Embedding 會慢約 2 倍。

## 工程設計

- 業務層只依賴 `RetrieverService` 與 `ChatService`；切換 Provider 不需要修改業務程式。
- Prompt 範本放在 `resources/prompts/rag-answer.md`；「資料不足」只在 config 定義一次，組裝時帶入 Prompt，固定回覆與模型被要求說的那句話不會不一致。
- 長度預算超過時從排名最後整段捨去（不從 Chunk 中間截斷），第 1 名一定保留。
- `rag_query_logs`：`status_key`、`source_key`（api / cli / eval，校準門檻時排除測試題）、`score_threshold` 與 `embedding_model`（門檻或模型改變後，舊紀錄仍能判讀）；寫入失敗只留 warning log。
- `rag:eval-answer` 單題逾時照實記錄、不重試，也不中斷其他題目；結果記錄 `finish_reason`。

## 限制與後續

- `insufficient_by_llm` 以字串比對判斷，遇到部分有答案的回答或換句話說的拒答不可靠（backlog #12）。
- Citation 的過度引用、`[資料不足]` 這類格式錯誤、資料不足時是否顯示來源，留給 Ch10（backlog #10）。
- qwen3:8b 偶爾輸出簡體字，先記錄；若要修只調整 Prompt（backlog #11）。
- 精確編號題交給 Hybrid Search（Ch11）、排名稍後的正確段落交給 Reranker（Ch12）、追問題交給 Query Rewriting（Ch13）。
- d03 改寫為「出差的差旅費用可以報多少錢？」後重跑：三組都沒有編造金額；gpt-4.1-mini 寫「無法回答」而被字串比對誤判為 answered。
