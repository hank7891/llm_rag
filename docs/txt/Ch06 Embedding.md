# Ch06 Embedding：主題與交付重點

Oct 4, 2026 · @hank

## 一、本章目標

把 Ch05 切好的 Chunk 轉成向量，建立「找」這一側的能力。本章只確認向量產得出來、產得正確，還不存進 Qdrant，也還不比較模型好壞。

對應教學文件第五階段，位於第三篇「向量化與檢索」第一章，接在 Ch05 Chunking 之後、Ch07 Qdrant 之前。

1. 定義 `EmbeddingProviderInterface`，建立 `EmbeddingService`
2. 串接 Ollama `/api/embed`，支援批次處理
3. 準備第二個候選模型，記錄各模型的維度、最大輸入長度，以及是否需要前綴

## 二、核心觀念

**Embedding 不是回答，是座標。** bge-m3 把一段文字變成 1024 個數字，意思相近的文字，座標也會相近。它不會產生任何文字，所以無法取代 qwen3:8b。

**Bi-encoder：** 問題和文件各自獨立轉成向量。文件向量可以事先算好存起來，提問時只需要算問題的向量，搜尋才會快。這也是 Ch12 Reranker（Cross-encoder）更準卻更慢的原因。

**四條鐵律**（違反時通常不會報錯，只會默默變不準）：

| 規則 | 後果 |
| --- | --- |
| 問題與文件必須用同一個模型 | 不同模型的向量空間不同，比較起來沒有意義 |
| 維度由模型決定 | Ch07 建立 Collection 的 `size` 必須一致（bge-m3 為 1024） |
| 換模型就要全部重建索引 | 新舊向量不能混用 |
| 部分模型要求 query / document 前綴 | 漏加前綴，檢索品質會明顯下降 |

## 三、架構延續 Ch01 的設計

Ch01 刻意沒有把 `embed()` 放進 `ChatProviderInterface`，本章就是兌現那個決定。`AnthropicProvider` 不實作 Embedding，在 Container 綁定時就依能力區分。

```
EmbeddingService ── 依名稱取得 Provider（config/ai.php 的 embedding 區塊）
  └── EmbeddingProviderInterface
        └── embed(array $texts, EmbeddingOptions $options): EmbeddingResult
              ├── OllamaProvider   ← 本章實作
              ├── OpenAIProvider   ← 選做
              └── （AnthropicProvider 不實作）
```

`EmbeddingResult` 包含 vectors、model、dimension、usage（token 數）。

## 四、實作重點

- **批次與順序**：`/api/embed` 的 `input` 可送陣列。批次大小放進設定檔，並檢查「送幾段、回幾個向量」且順序一一對應，否則向量會配錯 Chunk，後面所有引用都會錯。
- **維度驗證**：設定檔記錄預期維度，實際回傳不一致時直接丟例外，不要等到 Ch07 寫入 Qdrant 才失敗。
- **關閉截斷**：`/api/embed` 預設會靜默截斷過長輸入，設定 `truncate: false` 讓超長直接報錯。這和 Ch04、Ch05 的「靜默失敗」是同一個問題。
- **前綴放在 Provider 層**：`EmbeddingOptions` 帶 `inputType`（query / document），由 Provider 依模型設定決定是否加前綴，業務層不需要知道。
- **校正 Token 比例**：用回傳的 `prompt_eval_count` 實測 bge-m3 的中文 Token 比例，確認 Ch05 的 Chunk 長度估算是否安全（bge-m3 的 Tokenizer 和 qwen3 不同）。

## 五、建議小實驗：親手感受語意距離

取幾組句子計算 Cosine Similarity，記錄在筆記裡：

- 「我的假沒休完怎麼辦？」vs「年度特別休假未休畢者……」→ 應該很高
- 「如何取消訂單」vs「訂單退訂流程」→ 應該很高
- 「我的假沒休完怎麼辦？」vs「停車場收費標準」→ 低，但不會是 0

最後一組的分數，就是 Ch08 需要 Relevance Threshold 的直接證據。

## 六、需要你決策的地方

| 問題 | 建議 |
| --- | --- |
| 第二個候選模型 | 選小一點的避免記憶體吃緊，例如 `qwen3-embedding:0.6b`，可觀察「需要 query 前綴」的模型（維度與前綴規則以實測和模型說明為準） |
| 批次大小 | 先從 16～32 開始，觀察速度與記憶體 |
| Embedding 放在哪個 Job | 本章先提供 Service 與測試指令，是否併入索引 Job 留到 Ch07 再決定 |

## 七、明確不做

Qdrant、Vector Search、Recall@K 模型比較（Ch08）。本章只確認兩個候選模型都能產生向量，並記錄規格。

## 八、驗收

- 輸入一批 Chunk 文字，取得數量、順序都正確的向量
- 維度與設定一致，不一致時有明確錯誤
- 超長輸入會報錯，而不是被截斷
- 兩個候選模型的規格表（維度、最大長度、前綴規則、實測 Token 比例）已寫進 `docs/notes/`
- `AnthropicProvider` 沒有實作 `EmbeddingProviderInterface`

記憶體方面：bge-m3 約 1.2GB，和 qwen3:8b 同時載入仍在安全範圍，實驗完記得執行 `ollama stop`。

## 九、進入前確認

- [ ] Ch05 已收尾：`git tag` 有 `ch05-done`，`git status` 乾淨
- [ ] 終端機下載第二個候選模型：`ollama pull qwen3-embedding:0.6b`
- [ ] `ollama list` 同時看得到 `bge-m3` 與 `qwen3-embedding:0.6b`

## 十、交給 CLI Agent 的指令

```
進入 Ch06：Embedding。
本章只建立「文字 → 向量」的能力，不串 Qdrant，不做 Vector Search，
不做 Recall@K 模型比較（屬於 Ch08）。

【前置確認】
1. 確認 git 狀態乾淨、tag ch05-done 存在，否則先停下來回報。
2. 確認 ollama list 中有 bge-m3 與 qwen3-embedding:0.6b，缺少時停下來回報，不要自行下載。
3. 建立分支 ch06。

【實作範圍】
4. 建立 app/Ai/Embedding/ 目錄，結構比照 app/Ai/Chat/：
   - DTO/EmbeddingInputType.php：enum，值為 query / document
   - DTO/EmbeddingOptions.php：model（可為 null，代表用設定預設值）、inputType
   - DTO/EmbeddingResult.php：vectors（list<list<float>>）、model、dimension、
     usage（inputTokens）
   - Contracts/EmbeddingProviderInterface.php：
       embed(array $texts, EmbeddingOptions $options): EmbeddingResult
   - EmbeddingService.php：依 provider 名稱（未指定時用 config 的 default）
     從 Service Container 取得 Provider；驗證 texts 非空、不含空字串；
     回傳前檢查「向量數量 = 輸入數量」與「維度 = 設定值」。
     不做前綴處理與格式轉換，那是 Provider 的工作。
   - Exceptions/：UnknownEmbeddingProviderException、
     InvalidEmbeddingInputException、EmbeddingDimensionMismatchException、
     EmbeddingCountMismatchException

5. config/ai.php 新增 embedding 區塊：
   - default 讀取 AI_EMBEDDING_PROVIDER；providers 為「名稱 → 類別」對照表
   - model 讀取 AI_EMBEDDING_MODEL，預設 bge-m3
   - batch_size 讀取 AI_EMBEDDING_BATCH_SIZE，預設 16
   - models：每個模型的 dimension、max_tokens、query_prefix、document_prefix
       bge-m3：dimension 1024、max_tokens 8192、無前綴
       qwen3-embedding:0.6b：依模型說明填寫維度、上限與 query 指令前綴；
       數值須以實際呼叫結果確認，不一致時以實測為準並在回報中說明
   - .env 與 .env.example 補上對應變數

6. OllamaProvider 加上 implements EmbeddingProviderInterface：
   - 使用 /api/embed，input 以陣列批次送出，依 batch_size 分批，
     合併結果時必須維持原始順序
   - 請求一律帶 truncate: false，超過長度時讓 Ollama 回錯並轉成明確例外
   - 依 inputType 與模型設定加上 query / document 前綴
   - usage 由 prompt_eval_count 取得（分批時加總）

7. AnthropicProvider 不實作 EmbeddingProviderInterface，
   且 embedding.providers 不得註冊它。若 Ch02 曾預留 embed() 相關程式，一併移除。

8. 建立 FakeEmbeddingProvider（放置位置比照 FakeChatProvider）：
   依輸入文字產生固定、可重現的向量，記錄收到的 texts 與 options。

9. 建立 Artisan 指令 ai:embed-probe {--model=}：
   - 輸出模型名稱、實際維度、各句的 prompt_eval_count 與「Token ÷ 字元數」比例
   - 計算並列出以下句組的 Cosine Similarity（工具函式只供實驗使用，
     正式搜尋在 Ch07 交給 Qdrant）：
       「我的假沒休完怎麼辦？」vs「年度特別休假未休畢者，得遞延至次一年度。」
       「如何取消訂單」vs「訂單退訂流程」
       「我的假沒休完怎麼辦？」vs「停車場收費標準」
   - 問句一律以 query 送出，文件句以 document 送出

【測試】
10. 撰寫測試並全部通過（使用 Http::fake，不呼叫真實 Ollama）：
    - OllamaProvider 送出的 payload：input 為陣列、truncate 為 false、前綴依
      inputType 與模型正確套用（bge-m3 不加，qwen3-embedding 的 query 要加）
    - 超過 batch_size 時會分批呼叫，合併後順序與輸入一致
    - 回傳數量不符、維度不符時丟出對應例外
    - Ollama 回傳長度錯誤時，轉成明確的例外
    - 空陣列、含空字串時丟出 InvalidEmbeddingInputException
    - 未知 provider、Anthropic 名稱時丟出 UnknownEmbeddingProviderException
    - 以 FakeEmbeddingProvider 切換 provider，呼叫端程式碼不變

【實測】
11. 分別以兩個模型執行 ai:embed-probe，把輸出整理進
    docs/notes/ch06-embedding-models.md，內容包含：
    - 規格表：模型、維度、最大輸入長度、前綴規則、實測 Token／字元比例
    - 三組句子的 Cosine Similarity
    - 以 bge-m3 實測比例，回頭檢查 Ch05 的 Chunk 長度上限是否仍然安全
12. 實測結束後執行 ollama stop 卸載兩個 Embedding 模型。

【完成回報】做完以上內容就停止，不要進入 Ch07，不要 commit。
請依序回報：
A. 新增與修改的檔案清單，每個檔案一句話說明責任
B. Request Flow：一次 embed() 從 EmbeddingService 到 Ollama 再回來的流程，含分批與合併
C. 為什麼前綴放在 Provider 而不是 EmbeddingService
D. 為什麼要在 Service 層檢查數量與維度，而不是等寫入 Qdrant 才發現
E. 為什麼 truncate 要設為 false
F. AnthropicProvider 如何在綁定層就被排除，而不是用丟例外的 embed() 假裝實作
G. 兩個模型的規格表與實測差異（直接貼出 notes 內容即可）
H. 三組句子的分數觀察：無關句子的分數是多少？這對 Ch08 的門檻代表什麼
I. Ch05 的 Chunk 長度上限是否需要調整
J. 測試執行結果
```

## 十一、親自驗收

Agent 回報後，以下幾點建議自己確認，不要只看回報內容：

| 檢查項目 | 怎麼看 |
| --- | --- |
| 前綴真的有套用 | 看 payload 測試，或在 probe 指令印出實際送出的字串 |
| 無關句子的分數 | 若「停車場收費標準」的分數也不低，正好說明 Ch08 必須設門檻 |
| Token 比例 | bge-m3 的比例若明顯高於 Ch04 量到的 0.75，要回頭確認 Chunk 上限 |
| Anthropic 被排除 | 用 `ai:embed-probe` 帶 Anthropic 名稱，應得到明確的錯誤訊息 |

- [ ] 以上四項確認無誤
- [ ] 請 Agent commit 並打上 tag `ch06-done`
