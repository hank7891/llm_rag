# Ch12 總結：Reranker（Cross-encoder 重新排序）

## 成果

```
RetrieverService
  → 第一階段：Hybrid（Dense + Keyword + RRF），取 rerank_candidates 筆候選
  → 資料不足的判斷（Ch11）：無候選 → 直接回傳，不呼叫 Reranker，也不呼叫 LLM
  → 第二階段：RerankStage
       送 content 原文（prefix_metadata 時加「文件名稱 條號：」）→ RerankService → llama-server /v1/rerank
       以 results[].index 對回候選（回傳順序依分數，不是送出順序）
       keep_exact：精確命中保證入選，其餘名額依 Reranker 分數
       失敗、逾時、格式錯誤 → 退回第一階段排序，reranked = false、rerank.degraded warning log
  → Top-K（answer.top_k = 5）→ Context Builder → LLM
```

- Rerank 能力：`RerankProviderInterface`、`CohereCompatibleRerankProvider`（llama-server、TEI、Jina、Cohere 共用格式）、`RerankService`；Chat 與 Embedding Provider 不實作（延續 Ch01 依能力拆介面）。
- 設定：連線（位址、模型、逾時）在 `config/llm.php` 的 `rerank`；檢索策略（enabled、candidates、keep_exact、prefix_metadata、min_score）在 `config/rag.php` 的 `rerank`。
- `RetrieverService` 仍是唯一入口；重排是它內部的第二階段（關閉時不經過，結果與 Ch11 完全相同），沒有另建 RetrieverInterface + Decorator。
- 每筆結果保留每一層的排名：候選名次（retrieval_rank）、Reranker 名次與分數，加上 Ch11 的 Dense、關鍵字、精確命中、RRF。
- 工具：`rag:search --rerank=on|off`、`rag:eval --rerank= --rerank-candidates= --rerank-keep-exact= --rerank-prefix-metadata=`（Recall@1～@20、MRR、重排前→後名次、延遲 p50 / p95、降級次數）。評估結果見 `ch12-eval.md`。

## llama-server

Ollama 不支援 Rerank，地端改用 llama.cpp：

```bash
llama-server -m ~/models/bge-reranker-v2-m3-Q8_0.gguf --alias bge-reranker-v2-m3 \
  --embedding --pooling rank --reranking \
  --host 127.0.0.1 --port 8012 -c 8192 -b 2048 -ub 2048
```

- `--embedding --pooling rank --reranking` 三個一起加；`-b`、`-ub` 調大，避免長段落（實測 790 Token）超過預設的批次上限。
- 模型：gpustack/bge-reranker-v2-m3-GGUF 的 Q8_0（636 MB，SHA-256 與 Hugging Face 一致），放在 `~/models/`。
- 不會自動啟動；沒有啟動時檢索會降級（不中斷），評估報告的「降級次數」大於 0 時要先確認服務。

## Bi-encoder 與 Cross-encoder

- **Bi-encoder（bge-m3）**：問題、文件各自轉成向量，文件向量可以預先算好存進 Qdrant，所以能快速搜尋大量資料；但只比較兩個座標，比較粗。
- **Cross-encoder（bge-reranker-v2-m3）**：把「問題 + 單一段落」一起讀，看得到字詞之間的對應，比較準；但每次提問都要對每筆候選重算，無法預先計算，只適合處理第一階段挑出的幾十筆候選。
- **Two-stage Retrieval**：第一階段要 Recall（不要漏），第二階段要 Precision（排得準）。**Recall@20 是 Reranker 的天花板**：沒進候選的段落救不回來。

## Reranker 分數不是 Cosine

- bge-reranker 輸出 logit：相關段落約 +1～+7，無關段落約 −10，沒有固定範圍，只能在同一次請求內比較高低。
- 觀察組 G：被 Dense 門檻放行的無答案題 n01 得 −1.21，比好幾題有答案題（a06 −2.57、q22 −1.67）還高，**Reranker 分數無法當成資料不足的門檻**。`min_score` 維持 null；資料不足仍由 Dense 門檻與精確命中判斷。

## 評估結論

| | A（Hybrid） | B（+ Reranker，候選 20） | F（+ 文件名稱前綴） |
| --- | --- | --- | --- |
| questions Recall@1 / MRR | 79% / 0.865 | **96% / 0.958** | 96% / 0.958 |
| hybrid Recall@1 / MRR | 53% / 0.647 | 71% / 0.735 | **76% / 0.765** |
| Recall@5（兩份） | 96% / 76% | 不變 | 不變 |
| 延遲 p50 / p95 | — | 約 0.2 / 1.4 秒 | 約 0.2 / 1.5 秒 |

- 7 題從第 2～4 名排回第 1 名（含 Ch11 退步的 q09、q10），沒有任何題目被擠到更後面。
- 候選 10 / 20 / 30 結果相同：經過資料不足的判斷後，實際候選大多不到 10 段。
- keep_exact 在本測試集沒有作用：Reranker 對編號給的分數本來就高；保留規則維持開啟作為防線。

## 是否上線（在這台 M1 上）

**決定：上線**（使用者決定，2026-10-08）。設定為 `candidates = 20`、`keep_exact = true`、`prefix_metadata = true`，由使用者修改 `.env`（`RAG_RERANK_ENABLED=true`、`RAG_RERANK_PREFIX_METADATA=true`）。

- **候選數維持 20，不採用評估中 p95 較低的 10**：候選上限只影響「候選超過 10 段」的題目，而那正是 Reranker 能把段落從後段救回前段的情況。目前測試集經過資料不足的判斷後，候選大多不到 10 段，所以 10 / 20 / 30 的品質相同；文件變多、候選變多後，較大的候選數才看得到價值。
- **p95 的差距只出現在 questions.jsonl**（候選 10：1,116 ms、20：1,400 ms）；hybrid.jsonl 兩者幾乎相同（1,436 vs 1,452 ms）。
- **加上文件名稱前綴**：F 組在 hybrid.jsonl 上 Recall@1 76% 對 B 組 71%，**提升只來自 a02 一題**（「差勤規章第5條規定什麼？」，題目中提到文件名稱，Chunk 內文沒有），questions.jsonl 兩組完全相同。效果的證據只有 1 題，p95 約多 100 ms。
- 效益：Recall@1 明顯提升（questions 79% → 96%、hybrid 53% → 76%），正確條文排在第 1 個參考資料。Recall@5 不變，代表 LLM 拿到的段落「集合」相同、只有順序改變；回答品質是否因此改善尚未以端到端評估驗證（backlog #17）。
- 成本：每次提問多 p50 約 0.2 秒、p95 約 1.4～1.5 秒。地端回答（qwen3:8b 每題約 10 秒）只多 2～15%；雲端回答（gpt-4.1-mini 約 1.7 秒）p95 時等待時間接近加倍。
- 風險：llama-server 需要手動啟動，沒有啟動時會靜靜地降級、品質退回 Ch11。`scripts/check-env.sh` 的 [4] 會檢查 llama-server 與模型檔，失敗時提示啟動指令。
- 延遲由硬體決定，換機器要重新量測；排序結果與硬體無關。

## 限制與後續

- Reranker 對回答品質的實際影響（Lost in the Middle）未以端到端評估驗證。
- 測試集小，Recall@5 已達天花板，看不到 Reranker「從 Top-20 救進 Top-5」的效果；文件變多、候選變多時才看得到。
- 雲端 Rerank API 對照（選做）未實作，可沿用 `CohereCompatibleRerankProvider`。
