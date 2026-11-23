# 搬遷驗收評估（Mac mini，2026-10-30）

- 環境：Mac mini M5 Pro、64 GB；Laravel 在 Docker（Apache + PHP 8.3），Ollama 0.40.13、llama.cpp 0.8.0（第一階段為 0.34.4、0.6.0）。模型 ID 與第一階段相同。
- 對照基準：`docs/notes/ch14-regression/`（第一階段最終回歸）。
- 明細檔：`docs/notes/migration-eval/`。

## 檢索（`rag:eval`，hybrid、exact_only、--apply-threshold、rerank on）

| 測試集 | 檔案 | Recall@1 | 與第一階段比對 |
| --- | --- | --- | --- |
| questions.jsonl | `rag-eval-…-165015.md` | 96% | 每題命中名次、門檻判斷、進入 Context 相同；q16 第一階段名次 1→2，重排後仍為 1 |
| hybrid.jsonl | `rag-eval-…-165018.md` | 76% | 每題相同 |
| reasoning.jsonl | `rag-eval-…-165020.md` | 88%（R@5 100%） | 每題相同 |

差異只有分數小數：Dense 最大差 0.0003、重排分數最大差 0.013。RRF 分數有變的 q23、n01 回到 ch14 **基準**的值，符合 ch14-regression 對 FULLTEXT 統計的推測（重新匯入後全文索引重建）。

## 多輪（`rag:eval-conversation --rewrite=llm --rerank=on`）

| 檔案 | 條件 | Recall@1 | 降級 | 改寫 p50 / p95 | 說明 |
| --- | --- | --- | --- | --- | --- |
| `…-mac-mini-20261030-165458.md` | 未修正 | 92% | 1 | 7.7 / 120 秒 | h01 逾時（120 秒） |
| `…-mac-mini-run2-…` | 未修正（模型已載入） | 92% | 1 | 6.9 / 120 秒 | 排除冷啟動 |
| `…-mac-mini-diag-t400-…` | 未修正、逾時 400 秒 | 92% | 1 | 6.7 / 400 秒 | h01 仍未結束：think 重複迴圈 |
| `…-mac-mini-rp11-…`、`…-rp1.1-b-…` | repeat_penalty 1.1 | 85% | 0 | 6 / 24 秒 | 兩次相同；h06、h14「第一個問題」回指失敗 |
| `…-mac-mini-rp1.05-b-…`、`…-rp1.05-c-…` | repeat_penalty 1.05（選項覆寫） | 100% | 0 | 7 / 12 秒 | 兩次只差 n02 一個「那」字 |
| `…-mac-mini-final-…` | repeat_penalty 1.05（設定預設值） | 100% | 0 | 6.5 / 10.6 秒 | 最終設定 |

- h01 直接呼叫 Ollama 串流重現：think 從第 234 字起重複同一句，180 秒約 1.4 萬字未結束；`presence_penalty 1.5`（11 秒）或 `repeat_penalty 1.1`（6 秒）都能正常結束。qwen3:8b 模型檔本身為 `repeat_penalty 1`，第一階段為何沒有發生無法判斷是 runtime 還是硬體差異。
- 第一階段（M1）改寫 16 題三次完全相同；未修正的新環境兩次有 3 題改寫文字不同，加上 repeat_penalty 後恢復可重現。
- 1.05 的已知瑕疵：h07 把「識別證」改寫成「身分證」（仍命中）。只依 16 題決定，見 backlog #22。

## 其他

- 自動化測試 644 passed；`--group=qdrant` 1 passed、`--group=fulltext` 7 passed。
- 瀏覽器 `/knowledge/chat`：串流、階段顯示、來源正常；「那兩年年資呢？」改寫與檢索正確，回答 LLM 答成「七日」（應為十日），屬 backlog #13（回答 LLM 未固定 temperature），與搬遷無關。
