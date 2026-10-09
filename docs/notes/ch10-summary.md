# Ch10 總結：Citation（來源對應與顯示）

## 成果

```
RagAnswerService
  → LLM 原始回答（含 [n]）＋ 編號對照表（n → chunk_id）
  → 判斷狀態：回答以「資料不足」開頭 → insufficient_by_llm
  → CitationResolver
       CitationParser：找出 [1]、[1][3]、[1, 3]、[1、3]、[1-3]、［１］、[資料不足] …
       驗證編號：在對照表內？ → 以 chunk_id 向 MySQL 查詢 → 查不到記為 source_missing（warning log）
       資料不足的回答：標記一律移除、不顯示來源
       改寫回答：移除不合規標記，合法編號統一為 [n]
  → CitationFormatter：「[1] 員工管理辦法.pdf　第十二條　第 2 頁」，同檔同條同頁合併
  → Answer ＋ Sources（只列實際被引用的來源）＋ warnings（invalid_refs、uncited）
```

工具：`rag:ask --show-context`（資料來源、LLM 原始回答、被移除的標記）、`rag:eval-answer --prefix= --label=`（引用率、命中率、平均引用數、不合規標記、無答案題未顯示來源）；`POST /api/knowledge/ask` 回應新增 `citations`、`sources`、`warnings`。實驗紀錄見 `ch10-experiments.md`。

## 來源只能由程式產生

- LLM 只選編號；檔名、條號、頁碼由程式以 chunk_id 從 **MySQL** 查出。MySQL 是資料來源，Qdrant Payload 是衍生的索引，可能過期；查不到時記為 `source_missing`，同時是 MySQL 與 Qdrant 不同步的偵測點。
- 讓模型自己寫來源（實驗 3）：來源資訊緊貼每一段時，照抄的正確率很高（gpt-4.1-mini 13/13、qwen3:8b 12/13），但抄錯時（「第第二章」）看起來和正確的一樣、無法驗證、也看不出哪一句引用哪一段。Ch04 長 Context 時兩家都標錯頁碼。程式產生的來源錯誤率是 0。
- **不重新編號**：回答中的 [n] 就是送給 LLM 的排名編號，來源清單可能是 [1]、[3]。重編要同時改寫回答文字，Ch14 串流時文字已經顯示在畫面上，事後重編會對不上。

## 不合規引用

| 原因 | 處理 |
| --- | --- |
| out_of_range（不在對照表內） | 移除，記錄 |
| non_numeric（[資料不足]、[來源]） | 移除，記錄 |
| source_missing（MySQL 查不到） | 移除，記錄，warning log |
| insufficient_answer（資料不足的回答附了編號） | 移除，記錄；資料不足一律不顯示來源 |
| 已回答但沒有合法引用 | 照常回傳，`uncited = true`，記入 `rag_query_logs` |

## Prompt 調整（v1 → v2）

- 引用規則改為：句末標示、只引用直接支撐的資料、編號只能用參考資料中的數字、資料不足時只回答「資料不足」不加編號。
- 不合規標記 43 題 × 2 Provider 從 6 筆降到 1 筆；gpt-4.1-mini r01 的過度引用從 4 筆降到 2 筆；Ch09 的 `[資料不足]` 不再出現。
- 拒答變成固定的「資料不足。」，讓程式「以資料不足開頭」的判斷變得可靠；v1 時兩家各有 2 題拒答形式不同，被判為 answered 並顯示了來源。
- 沒有完全解決：qwen3:8b 開始為「資料中未提及」標引用，平均引用數不降反升。
- **修改引用相關的 Prompt 後必須重跑 `rag:eval-answer`**；v1 保留為 `rag-answer-v1.md`，以環境變數 `RAG_ANSWER_SYSTEM_PROMPT` 切換。

## 資料不足的判斷

- Ch09 的「含有資料不足」會把「先回答一部分、最後說另一部分資料不足」的回答判為不足，依「不足時不顯示來源」的規則連帶清掉合法的來源。本章改為「以資料不足開頭」，搭配 Prompt v2 要求拒答只回「資料不足」。
- 仍是字串判斷，換句話說的拒答（「無法回答」）判斷不到（backlog #12）。

## 指標的意義與限制

- 引用率、命中率在兩家都是 100%，但**命中只證明引用到對的段落，不證明句子有依據**：Ch09 q08 的 [2] 編號合法、來源存在，本章程式會照常顯示，但句子把「公司保存紀錄」改寫成「員工自己保留紀錄」。這類錯誤只能靠人工抽查或下一階段的 Faithfulness 評估。
- **同一個 Prompt，每次結果不同**：沒有固定 temperature，qwen3:8b r01 在三次執行中錯、對、錯。比較 Prompt 前後要看多題趨勢或重複執行（backlog #13）。

## 刪除文件

- 刪除 #4 後，MySQL、兩個 Collection 都已清除，`rag:index-check` 一致；原本引用它的問題改引用其他仍存在的條文，或由 LLM 回答資料不足（第二道防線），來源中不再出現 #4（規範最終驗收第 5 項）。
- 「Qdrant 仍有 Point、MySQL 已刪除」以測試模擬：該編號被列為 `source_missing`、從回答移除並寫入 warning log。
