# llm_rag

地端 LLM 與 RAG 基礎實作的**學習型 POC**專案。

目標不是把 AI 做得多聰明，而是完整理解一條資料流：**資料怎麼進去、怎麼被搜尋、怎麼進入 Context、最後又怎麼被 LLM 使用**。

---

## 學習目標

完成後應能理解並實作兩件事：

1. **LLM 串接** — 同一套程式可呼叫地端模型（Ollama）與雲端模型（OpenAI / Gemini / Anthropic），並正確處理多輪對話。
2. **RAG 資料流** — 文件從上傳、解析、切段、向量化、檢索，一路到成為 LLM 回答的依據，並附上來源。

```
文件
 ↓ 文字解析（保留頁碼與章節）
 ↓ Chunking
 ↓ Embedding
 ↓ Vector Database
 ↓ Similarity Search（含相關度門檻）
 ↓ 組成 Context
 ↓ LLM
答案 + 來源
```

## 最終成果

一套簡易的「內部知識問答系統」：上傳公司內部文件 → 背景解析並向量化 → 使用者提問（可連續追問）→ 系統檢索相關段落 → LLM 依文件回答並標示來源（檔名、條號、頁碼）；找不到依據時明確回答「資料不足」，不硬湊答案。

## 技術組合

| 用途 | 選型 |
|---|---|
| Backend | Laravel |
| Background Job | Laravel Queue（Database / Redis Driver） |
| 地端 LLM Serving | Ollama（原生執行，port 11434） |
| 地端模型 | Gemma / Qwen / TAIDE（依硬體記憶體選參數量與量化版本） |
| 雲端 LLM | OpenAI / Gemini / Anthropic（至少接一家） |
| Embedding | 多語言模型，例如 bge-m3（透過 Ollama） |
| Vector Database | Qdrant（port 6333） |
| Business Database | MySQL |
| Deployment | Docker / Docker Compose |

> macOS 開發環境：Ollama 原生執行（Docker 在 Mac 無法使用 GPU 加速）；Laravel / MySQL / Qdrant 視需求放入 Docker。容器內連線 Ollama 需用 `http://host.docker.internal:11434`。

選用 framework 作為 POC 基底，是為了較好進行套件管控與結構添加。

## 階段路線

分階段實作，每階段都可理解、可驗收，不一次做完。

| 階段 | 主題 | 產出 |
|---|---|---|
| 1 | LLM API 串接 | `ChatProviderInterface` / `EmbeddingProviderInterface` 抽象層、多輪對話、Streaming（SSE） |
| 2 | 文件上傳與解析 | Queue 背景解析，逐頁保存文字（`documents` / `document_pages`） |
| 3 | 直接把文件交給 LLM | 理解 Context Window 與 `num_ctx` 的實際影響 |
| 4 | Chunking | 結構優先、大小為輔的 Recursive Chunking（`document_chunks`，保留條號與頁碼） |
| 5 | Embedding | 向量化、維度與最大輸入長度確認，準備候選模型 |
| 6 | Vector Database | Qdrant Collection / Point / Payload，與 MySQL 的索引同步 |
| 7 | Semantic Search | Top-K + Relevance Threshold，建立測試集並量測 Recall@K |
| 8 | 完成 RAG | Retriever → Context Builder → LLM |
| 9 | 來源 Citation | LLM 只輸出引用編號，由程式對應回 Metadata |
| 10 | Hybrid Search | Sparse + Dense，以 RRF 合併 |
| 11 | Reranker | Cross-encoder 重排序 |
| 12 | Conversation Memory 與 Query Rewriting | 追問改寫為獨立問題再檢索 |

第一階段聚焦文字型資料，**不處理**圖片辨識、OCR、複雜表格、語音、多模態，以及 Agent / Agentic Workflow / MCP / 複雜 Workflow。Tool Calling 僅需理解概念。

## 第一版 UI

| 路徑 | 用途 |
|---|---|
| `/document` | 文件列表與處理狀態 |
| `/document/upload` | 文件上傳 |
| `/knowledge/chat` | 內部知識問答（可連續追問） |

## 目錄結構

```
llm_rag/
├── docs/
│   ├── 地端_LLM_與_RAG_基礎實作教學_v3.1.docx   ← 本專案的教材本體
│   ├── agent-system/     ← 三層式開發協議（Plan / Executor / Review）
│   └── tasks/            ← 各階段任務資料夾 docs/tasks/YYYYMMDD-{slug}/
├── CLAUDE.md             ← AI agent 專案規範
└── AGENTS.md             ← AI agent 補充規範
```

> `docs/agent-system/` 與 `.claude/` 目前列於 `.gitignore`，不進版控。

## 開發協議

任務以三層式架構運作：**Plan（規劃）→ Executor（實作）→ Review（驗收）**，以檔案契約交棒，規範位於 `docs/agent-system/`。

每個學習階段對應一個標準路徑任務，建於 `docs/tasks/YYYYMMDD-{slug}/`。

分工原則：**AI Agent 負責 Coding，人負責 Architecture 與驗收。**

## 驗收標準

- 同一問題可切換地端與雲端模型回答。
- 文件中有答案的問題，能回答並正確標示文件、條號與頁碼。
- 文件中沒有答案的問題，回答「資料不足」；沒有候選結果通過相關度門檻時直接回覆，不呼叫 LLM。
- 追問（例如「那兩年年資呢？」）能找到正確段落。
- 刪除文件後，問答不再引用該文件。
- 測試集的 Recall@K 有記錄，調整參數後可以比較。
- Provider 切換後，Controller / Domain Service 等業務邏輯不需修改，只換設定或綁定。
- 至少比較兩個 Embedding 模型，各自建立獨立 Collection，換模型即重建索引。
- 只支援 Chat 的 Provider 不實作 `EmbeddingProviderInterface`，不以永遠丟例外的 `embed()` 假裝符合介面。
- 相關度門檻的比較方向與數值，已依實際 Vector DB 與 Distance Metric 驗證。

## 目前進度

專案處於**前置準備階段**：教材與開發協議已就緒，Laravel 專案骨架尚未建立。
