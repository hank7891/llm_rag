# llm_rag

地端 LLM 與 RAG 基礎實作的**學習型 POC**專案。

目標不是把 AI 做得多聰明，而是完整理解一條資料流：**資料怎麼進去、怎麼被搜尋、怎麼進入 Context、最後又怎麼被 LLM 使用**。

---

## 學習目標

完成後應能理解並實作兩件事：

1. **LLM 串接**：同一套程式可呼叫地端模型（Ollama）與雲端模型（OpenAI / Gemini），並正確處理多輪對話。
2. **RAG 資料流**：文件從上傳、解析、切段、向量化、檢索，一路到成為 LLM 回答的依據，並附上來源。

```
上傳 → Queue 逐頁解析 → 結構優先切段（保留條號與頁碼）→ Embedding → Qdrant
提問（可連續追問）→ Query Rewriting → Hybrid Search（Dense + 關鍵字 + RRF）→ 相關度門檻
→ Reranker → Top-K 參考資料 → LLM（地端或雲端）→ 程式對應來源（文件、條號、頁碼）
```

## 最終成果

一套簡易的「內部知識問答系統」：

1. 上傳公司內部文件，在背景解析並向量化。
2. 使用者提問，可以連續追問。
3. 系統檢索相關段落，由 LLM 依文件回答，並標示來源（檔名、條號、頁碼）。
4. 找不到依據時明確回答「資料不足」，不硬湊答案。

## 目前進度

**第一階段 POC 已完成**（Ch01～Ch14，tag `v0.1-poc`）。

- 教學文件第二十章的 10 項最終驗收全部通過：`docs/notes/ch14-acceptance.md`。
- 14 章的決策、最終線上設定、backlog 與下一階段建議：`docs/notes/final-summary.md`。
- 下一階段建議先做 **Evaluation（Faithfulness）**，原因見 final-summary 第五節。

## 技術組合

| 用途 | 選型 |
|---|---|
| Backend | Laravel 13 / PHP 8.3（Docker：Apache + mod_php，port 8000） |
| Background Job | Laravel Queue（database driver，Docker 的 `queue` 容器） |
| 前端 | Blade + Vite + Tailwind + 原生 JS（SSE 以 fetch + ReadableStream 讀取） |
| 地端 LLM | Ollama（原生執行，port 11434）：qwen3:8b |
| 雲端 LLM | OpenAI（gpt-4.1-mini）、Gemini |
| Embedding | bge-m3（線上）、qwen3-embedding:0.6b（比較用），皆透過 Ollama |
| Vector Database | Qdrant 1.19（Docker，port 6333） |
| Business Database / 關鍵字檢索 | MySQL 8.4（Docker，port 33060），FULLTEXT ngram |
| Reranker | llama.cpp `llama-server` + bge-reranker-v2-m3 Q8_0（原生執行，port 8012） |
| 文件解析 | poppler `pdftotext`（PHP 映像內） |

> macOS 開發環境：Laravel（Apache + PHP、Queue Worker）、MySQL、Qdrant 在 Docker；Ollama 與 llama-server 以 Homebrew 原生執行（Docker 在 Mac 無法使用 GPU 加速），容器以 `host.docker.internal` 連線。主機不安裝 PHP，`php artisan`、`composer` 一律在容器內執行。所有連線位址都從 `.env` 讀取。

## 新主機建置

以下以 macOS（Apple Silicon、Homebrew）為例。參考硬體：Mac mini M5 Pro、64 GB 記憶體（2026-10-30 起；第一階段為 MacBook Pro M1、16 GB）。延遲數字依硬體而不同，排序與 Recall 不受影響。

**分工**：Docker 負責 Apache + PHP（`app`）、Queue Worker（`queue`）、MySQL、Qdrant；Homebrew 負責 Ollama、llama.cpp、Node。主機不安裝 PHP、Composer、poppler。

### 1. 安裝工具

| 工具 | 用途 | 安裝 | 本專案使用的版本 |
|---|---|---|---|
| Docker Desktop | app、queue、MySQL、Qdrant | `brew install --cask docker-desktop` | Docker 29.9、Compose v5.6 |
| Ollama | 地端 LLM、Embedding | `brew install ollama` | 0.40.13（第一階段 0.34.4） |
| llama.cpp | Reranker（`llama-server`） | `brew install llama.cpp` | 0.8.0（第一階段 0.6.0） |
| Node.js + npm | 前端建置（主機執行） | `brew install node` | Node 26.13 |
| PHP 8.3 + 擴充、Composer、poppler | Laravel、PDF 解析 | `docker/php/Dockerfile`（不在主機安裝） | PHP 8.3.36、Composer 2.11、pdftotext 25.03 |

安裝後鎖定 Ollama 與 llama.cpp 的版本，並讓 Ollama 常駐：

```bash
brew pin ollama llama.cpp     # 升級會改變模型的推論結果，影響門檻與改寫
brew services start ollama
```

> runtime 版本會影響結果：換到 Ollama 0.40 後，qwen3 改寫（think + temperature 0）在一題追問陷入重複迴圈，因此改寫加上 `repeat_penalty 1.05`（見 `docs/notes/backlog.md` #22）。升級前後要重跑第 9 節的評估。

### 2. 下載模型

```bash
# 地端 LLM 與 Embedding
ollama pull qwen3:8b
ollama pull bge-m3
ollama pull qwen3-embedding:0.6b   # 只有比較 Embedding 模型時才需要
ollama list                        # 核對下表的 ID

# Reranker：gpustack/bge-reranker-v2-m3-GGUF 的 Q8_0（約 636 MB）
mkdir -p ~/models
curl -L -o ~/models/bge-reranker-v2-m3-Q8_0.gguf \
  https://huggingface.co/gpustack/bge-reranker-v2-m3-GGUF/resolve/main/bge-reranker-v2-m3-Q8_0.gguf
shasum -a 256 ~/models/bge-reranker-v2-m3-Q8_0.gguf
```

| 模型 | ID / SHA256 |
|---|---|
| qwen3:8b | `500a1f067a9f` |
| bge-m3 | `790764642607` |
| qwen3-embedding:0.6b | `ac6da0dfba84` |
| bge-reranker-v2-m3-Q8_0.gguf | `a43c7c9b11a4c1517e5bf95151960e1621d1b72f7a493364b01e386cf1aaa1d3`（635,676,416 bytes） |

ID 不同代表上游更新了模型，門檻與評估結果都要重新確認。

### 3. 取得專案與設定 `.env`

```bash
git clone https://github.com/hank7891/llm_rag.git
cd llm_rag
cp .env.example .env
```

編輯 `.env`：

- `DB_PASSWORD`、`DB_ROOT_PASSWORD`：自行設定。MySQL 容器第一次啟動時會用這兩個值建立帳號。
- `OPENAI_API_KEY`、`GEMINI_API_KEY`：使用雲端模型時才需要。**API Key 不可進版控**，`.env` 已被 `.gitignore` 排除。
- `RAG_RERANK_ENABLED`：`.env.example` 預設為 `false`，第一階段的線上設定是 `true`。開啟前要先啟動 llama-server（第 7 節）；沒有啟動時檢索會靜默降級。
- 連線位址以容器內的角度填寫（`.env.example` 已是容器化的值）：

  | 變數 | 值 | 說明 |
  |---|---|---|
  | `DB_HOST` / `DB_PORT` | `mysql` / `3306` | compose 的 service 名稱；主機上的工具才用 `127.0.0.1:33060` |
  | `QDRANT_URL` | `http://qdrant:6333` | |
  | `OLLAMA_BASE_URL` | `http://host.docker.internal:11434` | Ollama 在主機 |
  | `RAG_RERANK_BASE_URL` | `http://host.docker.internal:8012` | llama-server 在主機 |
  | `PDFTOTEXT_PATH` | `/usr/bin/pdftotext` | PHP 映像內的路徑 |

- 其他設定的說明都寫在 `.env.example` 的註解中，除了 Reranker 開關，其餘預設值都是第一階段的最終線上設定。

### 4. 建置映像、安裝套件與建置前端

```bash
id -u; id -g                     # 預期 501 / 20；不同時修改 docker/php/Dockerfile 的 UID、GID
docker compose build app         # queue 共用同一個映像
docker compose run --rm --no-deps -u www-data -e COMPOSER_HOME=/tmp/composer app composer install
docker compose run --rm --no-deps -u www-data app php artisan key:generate
npm ci && npm run build          # 主機執行
```

> 在容器內執行 artisan / composer 時一律加 `-u www-data`（與 Apache 同身分），避免以 root 建立的 log、cache 讓 Apache 無法寫入。

### 5. 啟動 MySQL、Qdrant，建立資料庫

```bash
docker compose up -d mysql qdrant
docker compose ps        # 等 mysql 顯示 healthy
```

`compose.yaml` 已設定 FULLTEXT 需要的 `ngram_token_size=2` 與 `innodb_ft_enable_stopword=OFF`（只在啟動時讀取）。從舊主機搬資料庫時，**匯入前**先確認這兩個值，FULLTEXT 索引在匯入時建立：

```bash
docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SHOW VARIABLES WHERE Variable_name IN (\"ngram_token_size\",\"innodb_ft_enable_stopword\")"'
docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' < rag_poc.sql   # 只有搬資料時
```

**建立測試資料庫**：自動化測試會連到獨立的 `rag_poc_testing`，並拒絕連到其他資料庫。它不會自動建立：

```bash
docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "
  CREATE DATABASE IF NOT EXISTS rag_poc_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL PRIVILEGES ON rag_poc_testing.* TO \`rag\`@\`%\`;"'
```

**啟動 app、queue 並執行 migration（兩個資料庫都要）**：

```bash
docker compose up -d app queue
docker compose exec -u www-data app php artisan migrate
docker compose exec -u www-data -e DB_DATABASE=rag_poc_testing app php artisan migrate
```

> 不可執行 `migrate:fresh` 或 `migrate:reset`，也不可執行 `docker compose down -v`（會刪除 MySQL 與 Qdrant 的資料）。

### 6. 環境檢查與測試

```bash
bash scripts/check-env.sh                                  # Docker 服務、Ollama、PHP 容器、llama-server、版控安全
docker compose exec -u www-data app php artisan test       # 自動化測試（使用 Fake Provider，不呼叫真實 API）
```

llama-server 尚未啟動時，check-env 的 Reranker 項目會失敗，先啟動它（下一節）再檢查即可。

### 7. 啟動服務

`app`、`queue`、MySQL、Qdrant 由 Docker 常駐（`restart: unless-stopped`），Ollama 由 `brew services` 常駐。**只有 llama-server 要手動啟動**（重開機後也是）：

```bash
llama-server -m ~/models/bge-reranker-v2-m3-Q8_0.gguf --alias bge-reranker-v2-m3 \
  --embedding --pooling rank --reranking --host 127.0.0.1 --port 8012 -c 8192 -b 2048 -ub 2048
```

- 維持 `--host 127.0.0.1`：Docker Desktop 的 `host.docker.internal` 連得到主機的 127.0.0.1（已實測），不需要改成 `0.0.0.0`。改成 `0.0.0.0` 會讓同一網路的其他電腦都能呼叫，而 llama-server 沒有設定 API Key。Ollama 同理，維持預設的 127.0.0.1。
- Queue Worker 是 `queue:listen`，修改 Job 不用重啟；修改 `.env` 後執行 `docker compose exec -u www-data app php artisan config:clear`，並重啟 queue：`docker compose restart queue`。
- 前端開發時在主機執行 `npm run dev`（Vite HMR）。

瀏覽器打開：

| 路徑 | 用途 |
|---|---|
| `http://localhost:8000/document` | 文件列表與處理狀態 |
| `http://localhost:8000/document/upload` | 文件上傳 |
| `http://localhost:8000/knowledge/chat` | 知識問答（可連續追問、串流顯示） |

### 8. 重建知識庫資料

MySQL 與 Qdrant 的資料都不在版控中。

- **從舊主機搬遷**：匯入 MySQL 備份（第 5 節）後，文件狀態會顯示「索引完成」，但 Qdrant 是空的（問答會一律回答資料不足）。Qdrant 的資料都能由 MySQL 重新算出，一定要重建：

  ```bash
  docker compose exec -u www-data app php artisan rag:index --all
  docker compose exec -u www-data app php artisan rag:index --all --model=qwen3-embedding:0.6b   # 比較模型時才需要
  ```

  上傳的原始檔（`storage/app/private/`）也要一併搬過來，資料庫的 `documents.path` 指向這些檔案。

- **從頭建立**：評估用的文件都在 `tests/fixtures/documents/`（虛構內容），產生方式見該資料夾的 README。
  - **上傳時的檔名必須與測試集中的文件名稱一致**，例如 `handbook.pdf` 要上傳成「員工管理辦法.pdf」。測試集（`tests/rag-set/*.jsonl`）以「文件名稱 + 條號」判斷是否命中，名稱不同時 Recall 會變成 0。
  - 上傳後由 Queue Worker 自動解析、切段、建立 bge-m3 索引。`/document` 顯示「索引完成」即可。

- 確認 MySQL 與 Qdrant 一致：`docker compose exec -u www-data app php artisan rag:index-check`。

### 9. 驗證是否重現第一階段的結果

以線上設定重跑檢索評估（以下指令都在 app 容器內執行，前面加上 `docker compose exec -u www-data app`）。`--rerank=on` 確保 Reranker 開啟，不受 `.env` 影響；llama-server 要先啟動。

```bash
php artisan rag:eval --set=tests/rag-set/questions.jsonl --mode=hybrid --policy=exact_only --apply-threshold --rerank=on
php artisan rag:eval --set=tests/rag-set/hybrid.jsonl    --mode=hybrid --policy=exact_only --apply-threshold --rerank=on
php artisan rag:eval --set=tests/rag-set/reasoning.jsonl --mode=hybrid --policy=exact_only --apply-threshold --rerank=on
php artisan rag:eval-conversation --rewrite=llm --rerank=on   # 多輪；改寫開啟 think（Mac mini 約 2 分鐘）
```

預期結果（`docs/notes/ch14-regression.md`）：

| 測試集 | Recall@1 | Recall@5 | MRR |
|---|---|---|---|
| questions.jsonl | 96% | 96% | 0.958 |
| hybrid.jsonl | 76% | 76% | 0.765 |
| reasoning.jsonl | 88% | 100% | 0.938 |
| conversations.jsonl（多輪主表） | 100% | 100% | 1.000 |

評估結果預設寫入 `docs/notes/`。只是驗證時可以加上 `--output-dir=` 指到其他位置，避免和第一階段的紀錄混在一起。

Mac mini（2026-10-30）搬遷後重跑：三組檢索評估每題的名次與門檻判斷都與第一階段相同（只有分數小數差異）；多輪評估在改寫加上 `repeat_penalty 1.05` 後為 100%。

## 常用指令

以下 `php artisan` 指令都在 app 容器內執行：`docker compose exec -u www-data app php artisan …`。

| 指令 | 用途 |
|---|---|
| `php artisan rag:chat` | 在終端機連續提問（走資料庫讀取歷史的真實路徑） |
| `php artisan rag:ask "{問題}" --show-context` | 單次問答，列出檢索結果、送出的訊息、Token 與耗時 |
| `php artisan rag:search "{問題}"` | 只做檢索，列出每一層（Dense、關鍵字、精確詞、重排）的名次 |
| `php artisan rag:eval-answer --provider=ollama --set=…` | 端到端問答與引用指標 |
| `php artisan rag:stats --since=…` | 問答紀錄統計：改寫與 Rerank 降級率、各階段 p50 / p95 |
| `php artisan rag:collections`、`rag:index-check` | Qdrant Collection 與 MySQL 一致性 |
| `php artisan test --group=qdrant`、`--group=fulltext` | 連本機 Qdrant / MySQL FULLTEXT 的整合測試（預設不執行） |

## 目錄結構

```
llm_rag/
├── app/
│   ├── Ai/               ← Chat / Embedding / Rerank 的 Provider 抽象層（依能力拆介面）
│   ├── Documents/        ← 上傳、解析、切段、狀態機
│   ├── Rag/              ← 索引、檢索（Hybrid、Reranker）、問答、Citation、多輪對話、評估、統計
│   └── Http/             ← Controller、串流（SSE）
├── config/llm.php、rag.php、documents.php   ← 所有可調參數（由 .env 讀取）
├── resources/prompts/    ← System Prompt（回答、改寫，多個版本可切換）
├── tests/rag-set/        ← 評估測試集（questions、hybrid、reasoning、conversations）
├── tests/fixtures/documents/   ← 虛構的測試文件與產生方式
├── scripts/check-env.sh  ← 環境檢查
└── docs/
    ├── 地端_LLM_與_RAG_基礎實作教學_v3.1.docx   ← 教材本體
    ├── txt/              ← 各章需求與交接文件
    └── notes/            ← 各章總結、實驗紀錄、評估結果、backlog、最終驗收
```

## 開發協議

每章依同一個流程進行：

1. 讀當章任務文件，與教學文件比對，列出出入與需要決定的事項。
2. 確認後分步實作；串接外部服務前先實測。
3. 自動化測試全綠，並以反向驗證確認重要測試有效。
4. 回報、暫存檔案。commit 與 tag（`chXX-done`）由人執行。

分工原則：**AI Agent 負責 Coding，人負責 Architecture 與驗收。**

AI Agent 的專案規則寫在 `CLAUDE.md`（`AGENTS.md` 只補充閱讀順序），這兩個檔案與 `.claude/` 的設定**不在版控中**，換主機時要另外搬移。

## 驗收標準

教學文件第二十章，第一階段已全部通過（證據見 `docs/notes/ch14-acceptance.md`）：

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
