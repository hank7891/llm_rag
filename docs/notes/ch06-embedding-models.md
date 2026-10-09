# Ch06 Embedding 模型規格與實測

實測工具：`php artisan llm:embed-probe --model= --document=14`（Ollama 0.34.4，Mac 16GB，100% GPU）。

## 規格表

| 項目 | bge-m3 | qwen3-embedding:0.6b |
| --- | --- | --- |
| 參數量／量化 | 5.7 億／F16 | 6 億／Q8_0 |
| 模型檔大小 | 1.2 GB | 639 MB |
| 維度（實測） | **1024** | **1024** |
| 模型的最大輸入長度 | 8,192 | 32,768 |
| 本專案設定的上限 | 2,048（`LLM_EMBEDDING_NUM_CTX`，`num_ctx` = `num_batch`） | 2,048（同左） |
| query 前綴 | 無 | `Instruct: Given a web search query, retrieve relevant passages that answer the query\nQuery:`（實測約多 18 Token） |
| document 前綴 | 無 | 無 |
| 實測 Token／中文字（長文件 #14，53 段、2.3 萬字） | **0.68** | **0.73** |
| 載入後記憶體（num_ctx = num_batch = 2048） | 673 MB | 2.1 GB |
| 載入後記憶體（num_ctx = num_batch = 8192，調整前） | 963 MB | 6.7 GB |

- **兩個模型的維度剛好都是 1024**：Ch07 若誤用另一個模型，Qdrant 不會因為維度不符而報錯，向量會被「順利」寫入，但搜尋結果沒有意義。「問題與文件用同一個模型」只能靠程式把關（Collection 名稱與 payload 要記錄模型）。
- **qwen3-embedding 檔案只有 639MB，num_ctx 與 num_batch 開到 8192 時卻佔 6.7GB**，主因是 `num_batch`（見下方「記憶體」一節）。改為 2048 後降到 2.1GB。實驗完要 `ollama stop`。
- 短句的 Token 比例接近 1.0，是因為每段固定加上 2～3 個特殊 Token（開頭／結尾標記）；長文字才看得出真正的比例。

## Ollama 實測：/api/embed 的長度限制

`truncate` 與 `num_ctx` 是兩件事：

| 參數 | 決定什麼 | 實測 |
| --- | --- | --- |
| `truncate` | **超過上限時會不會報錯** | 預設 true：9,000 字的輸入照樣回傳 **HTTP 200**，只處理前 2,048 Token（靜默截斷）。設為 false：回 HTTP 400 `the input length exceeds the context length`（不含 Token 數） |
| `num_ctx`、`num_batch` | **單段輸入的上限有多大**（取兩者較小值） | 預設約 2,048 Token（2,040 字可以、2,100 字超過）；只設 `num_ctx: 8192` 仍為 2,048；兩者都設為 8,192 才能用到 bge-m3 的上限（7,000 字可以、9,000 字報錯） |

- OllamaProvider 一律帶 `truncate: false`：超過上限一定報錯，不會默默只用前半段產生向量。
- 上限設多大是**記憶體與餘裕之間的取捨**，不必開到模型的最大值。本專案的 Chunk 最長約 400 Token，設定為 `LLM_EMBEDDING_NUM_CTX=2048`（num_batch 相同），有約 5 倍的餘裕；需要更長的輸入時再調高。
- 輸入空字串時 Ollama 仍回傳 HTTP 200 與一個沒有意義的向量，所以 EmbeddingService 會先擋下空字串。

## 記憶體：6.7GB 從哪裡來

每次換設定前先 `ollama stop`，只送一句「測試」後看 `ollama ps`：

| 設定（num_ctx / num_batch） | qwen3-embedding:0.6b | bge-m3 |
| --- | --- | --- |
| 8192 / 8192（調整前） | **6.7 GB** | 963 MB |
| 8192 / 2048 | 2.9 GB | 673 MB |
| **2048 / 2048（調整後）** | **2.1 GB** | **673 MB** |
| 2048 / 512（參考） | 1.2 GB | 624 MB |

- **主因是 `num_batch`**：8192 → 2048 時 qwen3-embedding 少了 3.8GB。num_batch 決定一次運算處理多少 Token，Ollama 依此預留運算用的暫存空間。
- `num_ctx` 影響的是 KV Cache：qwen3-embedding 8192 → 2048 少了 0.8GB。**bge-m3 改 num_ctx 完全沒有影響**：它是 BERT 架構的編碼器，一次讀完整段、不逐字產生內容，沒有 KV Cache；qwen3-embedding 由生成式模型 Qwen3 改來，才有 KV Cache。
- num_batch 不能單獨調小：單段輸入的上限取 num_ctx 與 num_batch 的較小值，設為 512 時超過 512 Token 的 Chunk 就會被拒絕。

## 三組句子的 Cosine Similarity

| 問句（query） | 文件句（document） | 預期 | bge-m3 | qwen3-embedding |
| --- | --- | --- | --- | --- |
| 我的假沒休完怎麼辦？ | 年度特別休假未休畢者，得遞延至次一年度。 | 高 | 0.6881 | 0.6116 |
| 如何取消訂單 | 訂單退訂流程 | 高 | 0.8339 | 0.7210 |
| 我的假沒休完怎麼辦？ | 停車場收費標準 | 低 | **0.3736** | **0.2169** |

**觀察**

1. **無關的句子分數不是 0**：bge-m3 給「假沒休完」和「停車場收費標準」0.37。向量搜尋一定會回傳「最接近」的結果，即使它完全不相關——所以 Ch08 需要 **Relevance Threshold**，低於門檻的結果不能交給 LLM。
2. **分數的絕對值依模型而不同**：同一組無關句子，bge-m3 是 0.37、qwen3-embedding 是 0.22。**門檻不能跨模型沿用**，換模型就要重新決定門檻。
3. **相關句不一定「很高」**：「假沒休完」和「特別休假未休畢」用詞完全不同，bge-m3 給 0.69，和無關句子只差 0.31。口語問句與條文用語的落差，是之後 Query Rewriting（Ch13）要處理的問題。
4. **相關與無關的差距**：bge-m3 為 0.31～0.46、qwen3-embedding 為 0.39～0.50。只有三組句子，不能據此判斷哪個模型比較好，要等 Ch08 用測試集計算 Recall@K。

### qwen3-embedding 的前綴有沒有影響

| 句組 | 有前綴 | 沒前綴 |
| --- | --- | --- |
| 假沒休完 vs 特別休假未休畢 | 0.6116 | 0.6187 |
| 取消訂單 vs 訂單退訂流程 | 0.7210 | 0.7831 |
| 假沒休完 vs 停車場收費標準（無關） | 0.2169 | 0.2644 |

加了前綴，所有分數都變低（包括無關句）；相關與無關的差距，第一組有前綴較好（0.395 vs 0.354），第二組沒前綴較好。**三組句子看不出前綴的效果**，必須在 Ch08 以 Recall@K 比較。實作上依模型說明加上前綴。

## Ch05 的 Chunk 長度上限是否安全

| | Ch05 估算（中文每字 1.0） | bge-m3 實際 | qwen3-embedding 實際 |
| --- | --- | --- | --- |
| 長文件 #14 總 Token | 23,271 | 15,941（**69%**） | 17,116（**74%**） |
| 最長 Chunk（估算 585） | 585 | 約 400 | 約 430 |

- 實際 Token 數只有估算的 69～74%，**保守係數 1.0 是安全的**：最長的 Chunk 實際約 400 Token，在本專案設定的 2,048 上限內有約 5 倍的餘裕（bge-m3 的模型上限為 8,192）。
- **不需要調整 Chunk 上限**。上限 600 是為了檢索品質（一條一段）而設，不是被 Embedding 的長度限制。係數若改為實測的 0.7，Chunk 會變大約 40%，屬於「切段策略」的改變，留到 Ch08 用 Recall@K 比較後再決定。
- 實測比例：bge-m3 0.68、qwen3-embedding 0.73、qwen3:8b（Ch04）0.74、gpt-4.1-mini（Ch04）0.87。同一段中文，不同 Tokenizer 最多差約 28%。
