# Ch12 Reranker：主題重點與交接文件

Oct 8, 2026 · @hank

## 一、本章目標與定位

本章在 Ch11 的 HybridRetriever 之後加一層 Reranker：先取候選 Top 20，重新排序後取 Top 5 交給 LLM。對應教學文件第十一階段。

Ch11 解決「**找得到**」，Ch12 解決「**排得準**」。兩章都只改 Retriever，Context Builder、Prompt、Citation、資料不足 Gate 都不動。

```
Question
  ↓
HybridRetriever（Dense + Keyword + RRF）→ 候選 Top 20
  ↓
Reranker（Cross-encoder 逐筆打分）
  ↓
Top 5 → Context Builder → LLM → Answer + Citation
```

本章目標：

1. 架設地端 Reranker 服務，以 bge-reranker-v2-m3 為主
2. 建立 `RerankProviderInterface` 與 `RerankingRetriever`，可由設定開關
3. 用同一份測試集證明「排名靠後」題有改善，且精確編號題與無答案題沒有退步
4. 記錄延遲成本，判斷 Reranker 值不值得上線

本章的處理對象是 Ch08 與 Ch11 評估中「Recall@20 命中、Recall@5 沒命中」的題目。

## 二、核心知識點

Reranker 用速度換準確度：只對少量候選做精細判斷，所以要和快速但粗略的第一階段檢索搭配。

### Bi-encoder 與 Cross-encoder

|  | Bi-encoder（bge-m3） | Cross-encoder（bge-reranker-v2-m3） |
| --- | --- | --- |
| 輸入 | 問題、文件各自獨立轉成向量 | 「問題 + 單一段落」一起讀 |
| 輸出 | 1024 維向量，再算 Cosine | 一個相關度分數 |
| 能否預先計算 | 可以，文件向量事先存進 Qdrant | 不行，每次提問都要對每筆候選重算 |
| 速度 | 快，可搜尋大量資料 | 慢，只適合幾十筆候選 |
| 準確度 | 較粗，只比較兩個座標 | 較準，能看到問題與段落之間的字詞對應 |

### Two-stage Retrieval

第一階段要 **Recall**（不要漏），第二階段要 **Precision**（排得準）。Ch11 的 Hybrid 負責第一階段，Reranker 負責第二階段。

### Recall@20 是 Reranker 的天花板

Reranker 只能重新排序，不能找回沒進候選的段落。某題在 Top 20 就沒找到，是第一階段的問題，加 Reranker 也救不回來。所以本章開始前先看 Ch11 的 Recall@20。

### Reranker 分數不是 Cosine

bge-reranker 輸出的通常是 logit，數值可以是負的，沒有固定範圍；用 sigmoid 可以轉成 0～1，但仍然不能和 Cosine 相比。Ch08 的門檻值不能沿用到 Reranker 分數上。

### 新的評估指標：MRR

Recall@K 只看「有沒有進前 K 名」，看不出第 1 名和第 5 名的差別。MRR（Mean Reciprocal Rank）看正確段落排第幾名：

```latex
\mathrm{MRR} = \frac{1}{|Q|} \sum_{q \in Q} \frac{1}{\mathrm{rank}_q}
```

正確段落排第 1 名得 1，第 2 名得 0.5，沒找到得 0。Reranker 的效果通常最先反映在 Recall@1 與 MRR。

### 延伸概念（了解即可）

- **Late Interaction（ColBERT）**：介於兩者之間，文件保留每個 Token 的向量，速度與準確度折衷。
- **LLM as Reranker**：用生成式模型逐筆或整批判斷相關性，彈性大但慢且分數不穩定。
- **nDCG**：考慮多個相關段落與名次的排序指標。
- **Lost in the Middle**：LLM 對 Context 中間的段落注意力較弱，所以排序不只影響有沒有入選，也影響回答品質。

### 本章要能說清楚的名詞

Reranker、Bi-encoder、Cross-encoder、Two-stage Retrieval、Recall vs Precision、Logit / Sigmoid、MRR、Max Sequence Length、Graceful Degradation。

## 三、本專案的實作選擇

主線採用 **llama.cpp 的 `llama-server` 原生執行 bge-reranker-v2-m3**；雲端 Rerank API 列為延伸對照。

Ollama 目前仍不支援 Rerank：Dify 的 Ollama 外掛說明（2026 年 4 月）明確表示官方不支援 rerank 模型，建議改用 vLLM、llama.cpp、TEI、Xinference 等工具（[Dify Marketplace](https://marketplace.dify.ai/plugin/langgenius/ollama)）。網路上用 `/api/generate` 模擬的做法是把生成式介面硬套上去，不是真正的 Cross-encoder 打分，本章不採用。

| 方案 | 優點 | 缺點 | 定位 |
| --- | --- | --- | --- |
| llama.cpp `llama-server` | Mac 原生、可用 Metal GPU；GGUF 量化與 Ollama 同一套觀念；提供 `/v1/rerank` | 多一個要手動啟動的服務 | **主線** |
| TEI（Text Embeddings Inference） | 教學文件提到的方案，生產環境常見 | Docker on Mac 沒有 GPU，且 Docker 只配 4GB；arm64 映像檔需先確認 | 不採用 |
| Python 服務（sentence-transformers + MPS） | 彈性最大 | 要多維護一套 Python 環境 | 不採用 |
| 雲端 Rerank API（Cohere、Jina、Voyage） | 不必架設 | 文件內容會離開本機；需 API Key 與計費 | 延伸對照（選做） |

### llama-server 啟動方式

`--reranking` 開啟 Rerank 端點，官方文件要求同時加上 `--embedding` 與 `--pooling rank`，三個參數要一起使用（[simplified.guide](https://www.simplified.guide/llama-cpp/server-call-rerank-api)）。

```bash
llama-server \
  -m ~/models/bge-reranker-v2-m3-<量化>.gguf \
  --alias bge-reranker-v2-m3 \
  --embedding --pooling rank --reranking \
  --host 127.0.0.1 --port 8012
```

- 量化版本建議 Q8\_0 或 F16，Reranker 本身不大（約 0.6B 參數），過度量化可能影響排序品質
- Port 避開 Ollama 的 11434 與 Qdrant 的 6333，寫進 `.env`
- Laravel 日後移進 Docker 時，同樣要改用 `host.docker.internal`

### API 格式

請求帶 `model`、`query`、`documents`、`top_n`；回應的 `results` 每筆有 `index`（對應送出時的陣列位置，從 0 開始）與 `relevance_score`。這種格式與 Cohere、Jina 等雲端服務相同，所以寫一個相容 Provider，地端和雲端都能接。

分數尺度依模型而定，只能在同一次請求內比較高低，不能跨模型比較絕對值（[simplified.guide](https://www.simplified.guide/llama-cpp/server-call-rerank-api)）。

## 四、架構與關鍵設計決策

Reranker 以 Decorator 包住既有的 HybridRetriever，關閉時行為與 Ch11 完全相同；資料不足的判斷仍由 Ch11 的 Gate 負責。

### 架構

```
RerankProviderInterface
  └── rerank(string $query, array $documents, RerankOptions $options): RerankResult
        ├── CohereCompatibleRerankProvider   ← llama-server、TEI、Jina、Cohere 共用
        └──（ChatProvider、EmbeddingProvider 不實作）

RerankService ── 依設定取得 Provider

RerankingRetriever implements RetrieverInterface   ← Decorator
  └── 內部持有 HybridRetriever（或任何 RetrieverInterface）
```

`RerankResult` 包含每筆的 `index`、`score`、`model`，以及延遲毫秒數。這延續 Ch01 的設計：依能力拆介面，不讓 Chat 或 Embedding Provider 實作不存在的功能。

### 執行順序

1. 內層 Retriever 取回候選（`rerank_candidates`，預設 20），並照舊執行 Ch11 的 Gate
2. Gate 判定資料不足 → 直接回傳「無候選」，**不呼叫 Reranker**，也不呼叫 LLM
3. 有候選 → 送 Reranker，依分數重排
4. 套用精確命中保留規則（決策 3）
5. 取 `final_top_k`（預設 5）交給 Context Builder

### 決策 1：資料不足仍由 Ch11 的 Gate 判斷

Reranker 只負責排序，不負責決定有沒有資料。Reranker 分數尺度和 Cosine 不同，不能沿用 Ch08 的門檻。Reranker 分數門檻可以做成實驗（`rerank_min_score`，預設關閉），第一版不上線。

### 決策 2：失敗要能降級

Reranker 服務沒啟動、逾時或回傳格式錯誤時，退回內層 Retriever 的原始排序，寫入 warning log，並在結果標記 `reranked = false`。不要讓整個問答因為 Reranker 失敗而中斷。逾時時間放設定檔，建議先設 3 秒。

### 決策 3：精確命中不能被擠掉

Cross-encoder 對 `BUG-2026-000183` 這類編號不一定敏感，可能把 Ch11 救回來的精確命中 Chunk 排出 Top 5。做成設定 `rerank_keep_exact`：

| 值 | 行為 |
| --- | --- |
| `true`（預設） | 精確命中的 Chunk 保證保留在最終結果中，其餘名額依 Reranker 分數排序 |
| `false` | 完全依 Reranker 分數排序，評估時用來觀察 Reranker 對編號的實際表現 |

### 決策 4：送進 Reranker 的文字

- 送 `content` 原文，不是 Ch11 的 `search_text`（正規化過的文字只給關鍵字檢索用）
- 可在前面加上文件名稱與條號（例如「員工管理辦法 第 12 條：」），讓 Reranker 看到段落的上下文。做成設定，評估時比較有無差異
- 「問題 + 段落」的長度不能超過 Reranker 的最大輸入長度，否則會被截斷且不會報錯。Chunk 上限 1000 Tokens 時，要確認 llama-server 的 context 設定足夠

### 決策 5：每一層的排名都要保留

除了 Ch11 的 `dense_rank`、`dense_score`、`keyword_rank`、`keyword_score`、`exact_match`、`rrf_score`，新增 `rerank_rank`、`rerank_score`、`reranked`。除錯時才看得出是哪一層把結果排上來或擠下去。`rag:search` 指令也要顯示這些欄位。

### 設定參數（`config/rag.php`，可由 `.env` 覆寫）

| 參數 | 預設 | 說明 |
| --- | --- | --- |
| `rerank_enabled` | `false` | 評估完成、由你決定後才改成 `true` |
| `rerank_provider` | `llamacpp` | 對應 Provider 綁定 |
| `rerank_base_url` | `http://127.0.0.1:8012` | llama-server 位址 |
| `rerank_model` | `bge-reranker-v2-m3` | 與 `--alias` 一致 |
| `rerank_candidates` | `20` | 送進 Reranker 的候選數 |
| `final_top_k` | `5` | 沿用 Ch11 |
| `rerank_timeout` | `3` | 秒 |
| `rerank_keep_exact` | `true` | 決策 3 |
| `rerank_prefix_metadata` | `false` | 決策 4 |
| `rerank_min_score` | `null` | 實驗用，預設不啟用 |

## 五、評估方式

本章的成敗看三件事：排名靠後題的 Recall@5 是否提升、精確編號題與無答案題是否沒有退步、每次提問多花多少時間。

### 評估指令擴充

```bash
php artisan rag:eval --retrieval=hybrid --rerank=on --rerank-candidates=20
```

- 新增參數：`--rerank=on|off`、`--rerank-candidates`、`--rerank-keep-exact`、`--rerank-prefix-metadata`
- 新增指標：Recall@1、MRR、Reranker 延遲（每題毫秒數，彙總 p50 / p95）、降級次數
- 保留 Ch11 指標：依題型的 Recall@5、Recall@20、無答案題正確率
- 新增欄位：每題「Reranker 前名次 → 後名次」，直接看出哪幾題被排上來、哪幾題被擠下去
- 結果寫入 `docs/notes/ch12-eval.md`

### 比較組

| 組別 | 設定 | 目的 |
| --- | --- | --- |
| A（基準） | hybrid，rerank off | Ch11 結果，所有比較的基準 |
| B（主組） | hybrid + rerank，候選 20，keep\_exact on | 本章預計上線的設定 |
| C | hybrid + rerank，候選 10 | 候選少一點，延遲是否明顯下降、品質是否持平 |
| D | hybrid + rerank，候選 30 | 候選多一點，Recall@5 是否還能再升 |
| E | 同 B，keep\_exact off | 觀察 Reranker 對編號題的真實表現 |
| F（選做） | 同 B，prefix\_metadata on | 加上文件名稱與條號是否有幫助 |

### 看結果的順序

1. **先看天花板**：A 組的 Recall@20 有多少，代表 Reranker 最多能救回幾題
2. **再看主效果**：B 組的 Recall@1、Recall@5、MRR 比 A 組提升多少，列出被救回的題目
3. **再看副作用**：編號題與無答案題有沒有退步；E 組被擠掉的編號題就是保留規則存在的理由
4. **最後看成本**：延遲 p95 增加多少，換算成使用者等待的感受

### 判斷是否上線

把「提升幾題」和「多等幾百毫秒」放在一起，寫下判斷。如果 Ch11 的 Hybrid 已經把多數題目排進 Top 5，Reranker 的效益可能有限，「不上線」也是有價值的結論，理由寫進 summary 即可。

Reranker 本身是確定性的（同樣輸入得到同樣分數），但若評估包含 LLM 回答，仍沿用 Ch10 的做法固定 temperature 0 與 seed。

## 六、交接給 Agent CLI

以下內容可直接交給 Agent CLI。前置條件不符合時，Agent 應回報而不是自行補做。

### 前置條件

- `ch11-done` 已打上 tag，`docs/notes/ch11-eval.md` 有 hybrid 模式的 Recall@5 與 Recall@20
- `RetrieverInterface` 與 `HybridRetriever` 已存在，由 Service Container 依 `retrieval_mode` 綁定
- llama.cpp 已安裝（`brew install llama.cpp`），bge-reranker-v2-m3 的 GGUF 檔已下載

### 前置作業（由你手動完成）

- [ ] 啟動 llama-server（見第三節），`curl http://127.0.0.1:8012/health` 回傳 `{"status":"ok"}`
- [ ] 用 curl 送一個問題加三段文字到 `/v1/rerank`，確認相關段落分數最高
- [ ] Ollama 載入 qwen3:8b 與 bge-m3、Docker 啟動的狀態下，記錄 llama-server 加入後的記憶體用量

### Agent Prompt

```
在既有的 Hybrid Retriever 之後加入 Reranker。規則如下：

1. 建立 RerankProviderInterface：rerank(string $query, array $documents, RerankOptions $options): RerankResult，結果包含每筆 index、score、model 與延遲毫秒數。實作 CohereCompatibleRerankProvider，呼叫 POST {base_url}/v1/rerank（model、query、documents、top_n），回應的 results[].index 對應送出時的陣列位置。Chat 與 Embedding Provider 不實作此介面。建立 RerankService。
2. 建立 RerankingRetriever implements RetrieverInterface，以 Decorator 包住既有 Retriever。rerank_enabled = false 時行為與 Ch11 完全相同。
3. 執行順序：內層 Retriever 取 rerank_candidates 筆候選並照舊執行資料不足 Gate；判定資料不足時直接回傳無候選，不呼叫 Reranker；有候選才送 Reranker 重排，套用 rerank_keep_exact（精確命中的 Chunk 保證保留），再取 final_top_k。
4. Reranker 不參與資料不足判斷。rerank_min_score 只做為實驗參數，預設 null 不啟用。
5. 送進 Reranker 的是 content 原文，不是 search_text。rerank_prefix_metadata = true 時，在前面加上「文件名稱 條號：」。
6. Reranker 失敗、逾時（rerank_timeout）或格式錯誤時，退回原始排序，寫 warning log，結果標記 reranked = false，問答不可中斷。
7. 每筆結果在 Ch11 欄位之外新增 rerank_rank、rerank_score、reranked。rag:search 指令顯示這些欄位。
8. 所有參數放 config/rag.php 並可由 .env 覆寫：rerank_enabled（預設 false）、rerank_provider、rerank_base_url、rerank_model、rerank_candidates（20）、rerank_timeout（3）、rerank_keep_exact（true）、rerank_prefix_metadata（false）、rerank_min_score（null）。
9. 擴充 rag:eval：支援 --rerank、--rerank-candidates、--rerank-keep-exact、--rerank-prefix-metadata；新增 Recall@1、MRR、Reranker 延遲 p50/p95、降級次數，以及每題 Reranker 前後名次。跑 A～E 五組（見交接文件第五節），結果寫入 docs/notes/ch12-eval.md。

本章不要修改 Context Builder、Prompt、Citation、Query Rewriting，也不要把 rerank_enabled 改成 true。

完成後說明：Cross-encoder 為何比 Bi-encoder 準卻不能直接用來搜尋、Reranker 失敗時的降級流程，以及五組評估結果的差異與是否建議上線。
```

### 任務清單

- [ ] `RerankProviderInterface`、`RerankOptions`、`RerankResult`、`CohereCompatibleRerankProvider`、`RerankService`
- [ ] `RerankingRetriever`（Decorator），Service Container 依 `rerank_enabled` 綁定
- [ ] 資料不足時不呼叫 Reranker；精確命中保留規則
- [ ] 降級處理與 log
- [ ] `config/rag.php` 新增參數，`.env.example` 同步補上
- [ ] `rag:search` 顯示 Reranker 欄位
- [ ] 單元測試：index 正確對應回 Chunk（含 Reranker 回傳順序打亂的情況）、降級行為、精確命中保留、資料不足時 Reranker 未被呼叫（用 fake Provider 驗證）
- [ ] `rag:eval` 擴充並跑 A～E 五組，寫入 `docs/notes/ch12-eval.md`
- [ ] `CLAUDE.md` 與 `AGENTS.md` 補上 llama-server 的啟動方式與 port
- [ ] 撰寫 `docs/notes/ch12-summary.md`（沿用 Ch00 篇章總結格式）
- [ ] commit 並打上 tag `ch12-done`

### 驗收

1. `rerank_enabled = false` 時，`rag:eval` 結果與 Ch11 完全一致
2. B 組的 Recall@5 不低於 A 組，並列出被救回的題目與前後名次
3. 精確編號題在 B 組的 Recall@5 不低於 Ch11
4. 無答案題全部回覆「資料不足」，且 Reranker 與 LLM 都沒有被呼叫
5. 關閉 llama-server 後，問答仍能運作，log 有降級紀錄，結果標記 `reranked = false`
6. `ch12-eval.md` 記錄延遲 p50 / p95，`ch12-summary.md` 寫下是否上線的判斷與理由

## 七、常見陷阱

| 陷阱 | 現象 | 處理 |
| --- | --- | --- |
| index 對錯 Chunk | 來源引用全部錯亂，但不會報錯 | 以 `results[].index` 對回送出時的陣列，不要假設回傳順序；單元測試要涵蓋順序打亂的情況 |
| 少了 `--pooling rank` | 服務能啟動，但分數沒有意義或端點報錯 | `--embedding`、`--pooling rank`、`--reranking` 三個一起加 |
| 輸入被靜默截斷 | 長 Chunk 的後半段內容對排序沒有影響 | 確認 llama-server 的 context 長度足以容納「問題 + 最長 Chunk」 |
| 把 Reranker 分數當門檻 | 有答案題被誤判為資料不足，或無答案題混進 Context | 第一版不用 Reranker 分數判斷有無資料 |
| 送 `search_text` 給 Reranker | 小寫、去空白後的文字讓 Cross-encoder 判斷變差 | 送 `content` 原文 |
| 只看 Recall@5 | Reranker 把正確段落從第 4 名排到第 1 名，Recall@5 看不出改善 | 一起看 Recall@1 與 MRR |
| 記憶體吃緊 | 模型頻繁載入卸載，延遲忽高忽低 | 記錄三個模型同時常駐時的用量；必要時改用較小量化版本 |
| 忘記啟動 llama-server | 評估結果與 Ch11 一模一樣，誤以為 Reranker 沒效果 | 評估報告列出降級次數，大於 0 時要先確認服務狀態 |

## 八、需要你決策的地方

| 問題 | 建議 | 何時決定 |
| --- | --- | --- |
| Reranker 模型 | bge-reranker-v2-m3，多語言、中文表現穩定，llama.cpp 文件也以它為例 | 開始前 |
| GGUF 量化版本 | Q8\_0；記憶體吃緊再降到 Q4 | 開始前 |
| 是否接雲端 Rerank API 對照 | 選做；接的話用同一個 `CohereCompatibleRerankProvider` | 評估後 |
| `rerank_enabled` 是否改為 true | 看 B 組的改善題數與延遲 | 評估後，寫進 summary |
| 候選數 | 先用 20，依 C、D 組結果調整 | 評估後 |

## 下一章

Ch13 Query Rewriting 與 Conversation Memory。Ch11、Ch12 都在強化「單一問題」的檢索，Ch13 補上多輪追問：「那兩年年資呢？」直接拿去檢索會失敗，要先請 LLM 改寫成獨立問題。Ch08 實驗 4 記錄的追問失敗案例，就是 Ch13 的對照組。

## Sources

- [Dify Marketplace：Ollama 外掛說明](https://marketplace.dify.ai/plugin/langgenius/ollama)（Ollama 官方不支援 rerank 模型）
- [simplified.guide：How to call the llama.cpp rerank API](https://www.simplified.guide/llama-cpp/server-call-rerank-api)（啟動參數、請求與回應格式、分數尺度）
