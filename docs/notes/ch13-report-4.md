# Ch13 第四次回報：改寫跟隨回答 Provider（follow）與收尾

- 日期：2026-10-09
- 測試：615 個全部通過；`pint --test --dirty` 通過。
- Ch13 的程式檔案已 `git add`（不含 `docs/`、`.env*`、CLAUDE.md、AGENTS.md，見最後一節）。commit 與 tag `ch13-done` 由你執行。

## 1. follow：改寫跟著回答 Provider 走

### 設定（`config/rag.php` 的 `rag.conversation.rewrite`）

```php
'provider' => env('RAG_REWRITE_PROVIDER', 'follow'),
'max_chars' => ...,
'prompt_version' => env('RAG_REWRITE_PROMPT_VERSION', 'v3'),
'providers' => [
    'ollama' => ['model' => env('RAG_REWRITE_OLLAMA_MODEL'), 'timeout' => 120 (RAG_REWRITE_OLLAMA_TIMEOUT), 'provider_options' => ['think' => true (RAG_REWRITE_OLLAMA_THINK)]],
    'openai' => ['model' => env('RAG_REWRITE_OPENAI_MODEL'), 'timeout' => 15 (RAG_REWRITE_OPENAI_TIMEOUT), 'provider_options' => []],
    'fake'   => ['model' => null, 'timeout' => 30, 'provider_options' => []],   // 測試用
],
```

- **移除全域設定**：原本的 `rewrite.model`、`rewrite.timeout`、`rewrite.provider_options` 已移除，改成每個 Provider 各自一組。
- **改寫 Provider 的決定方式**：`RagAnswerService` 把本次實際使用的回答 Provider 傳給 `QueryRewriter::rewrite()`。這個值的優先順序是：API 參數或 `rag:ask --provider` → `rag.answer.default_provider` → Chat 預設。
  - `follow` 時，改寫使用這個 Provider。
  - 設定為 ollama 或 openai 時，以設定值覆寫 follow。
  - `rag:eval-conversation --rewrite-provider=` 也可以逐次覆寫。
- **送給 Provider 的參數**：`ChatOptions` 帶入該 Provider 的 model、逾時，`providerOptions` 為 `[名稱 => 該組 provider_options]`。各 Provider 只讀自己那組。
- **業務層不判斷 Provider 名稱**：只用名稱查設定表，和 `config/llm.php` 的 Provider 對照表相同。

### 沒有改寫設定時的降級流程

1. 以 follow 得到本次的 Provider，例如 gemini。在 `providers` 中找不到設定。
2. **不呼叫任何 LLM**（也不改用 Chat 預設或另一家）：`status = fallback`、`called = false`、`latency_ms = null`、`failure_reason = no_rewrite_config`、`provider = gemini`。
3. 寫 warning log：
   ```
   rewrite.fallback {"provider":"gemini","reason":"no_rewrite_config","question":"那兩年年資呢？"}
   ```
   其他降級原因（逾時、空白、過長、像答案）的 log 也都帶上 `provider`。
4. 以原問題檢索，問答照常進行；回答仍由 gemini 產生。

### 測試（`tests/Feature/Rag/ConversationTest.php`）

| 測試 | 內容 |
| --- | --- |
| `test_follow_uses_rewrite_settings_of_the_answer_provider_given_per_request`（2 組） | 逐次指定 ollama 時，改寫拿到 `['ollama' => ['think' => true]]`、逾時 120 秒；指定 openai 時拿到 `['openai' => []]`、15 秒 |
| `test_explicit_rewrite_provider_overrides_follow` | 設定為 openai、回答用 ollama 時，改寫使用 openai 的設定 |
| `test_provider_without_rewrite_settings_falls_back_without_using_another_provider` | 回答用 gemini 時：fallback、`called = false`、`no_rewrite_config`、檢索用原問題、整段只有回答呼叫（沒有任何改寫呼叫），warning log 帶 `provider = gemini` |

測試中把 ollama、openai、gemini 都對應到 `FakeChatProvider`，不會呼叫真實 API。

**反向驗證**：

- 讓改寫忽略回答 Provider、一律用預設值 → follow 測試 2 組都失敗。
- 缺設定時改用 ollama 的設定 → 降級測試失敗。

兩項都已還原。

## 2. think 開啟時的逐題紀錄

設定：follow → ollama、v3、think 開啟、逾時 120 秒、N = 5、Reranker off。完整明細在 `docs/notes/ch13-eval3/ch13-eval-llm-final-follow-ollama-*.md`。

| id | 改寫結果 | done_reason | prompt_eval_count | eval_count | 合計 | 延遲 ms | 名次 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| h01 | 兩年年資的特休天數是多少？ | stop | 179 | 785 | 964 | 77,230 | 2 |
| h02 | 家庭照顧假一年可以請幾天？ | stop | 154 | 276 | 430 | 24,830 | 1 |
| h03 | 事假會扣薪嗎？ | stop | 167 | 219 | 386 | 19,884 | 1 |
| h04 | 試用期滿之後會怎麼樣？ | stop | 153 | 343 | 496 | 30,613 | 1 |
| h05 | 特休沒休完的遞延期限是到什麼時候？ | stop | 191 | 257 | 448 | 23,226 | 2 |
| h06 | 識別證遺失補發的費用，公司會補助嗎？ | stop | 193 | 448 | 641 | 40,131 | 1 |
| h07 | 公司的識別證遺失怎麼辦？ | stop | 180 | 615 | 795 | 55,167 | 1 |
| h08 | （unchanged） | stop | 158 | 256 | 414 | 23,018 | 1 |
| h09 | （unchanged） | stop | 192 | 239 | 431 | 21,835 | 1 |
| h10 | （unchanged） | stop | 168 | 212 | 380 | 19,380 | 1 |
| h11 | BUG-2026-000183這張系統異常回報單需要附上什麼內容？ | stop | 178 | 404 | 582 | 36,334 | 1 |
| h12 | HR-F-014 表單的承辦人是誰？ | stop | 153 | 396 | 549 | 35,937 | 1 |
| h13（歧義） | 識別證補發的補助上限是多少？ | stop | 166 | 231 | 397 | 24,273 | 未命中 |
| n01 | 公司的員工宿舍規定是什麼？ | stop | 177 | 434 | 611 | 45,404 | 無候選 |
| n02 | 公司的特休可以換成現金給家人嗎？ | stop | 182 | 316 | 498 | 29,268 | 無候選 |
| h14 | 識別證遺失補發的費用是誰負擔？ | stop | 242 | 495 | 737 | 44,480 | 1 |

- **結果**：主表 Recall@1 / @5 / MRR 為 85% / 100% / 0.923；unchanged 3 題、降級 0。改寫延遲 p50 29,268 ms、p95 77,230 ms。
- **是否撞到上限：沒有**。
  - 16 題的 `done_reason` 都是 stop，沒有 length。
  - `prompt_eval_count + eval_count` 最大 964，是 num_ctx 8192 的 12%。
- **done_reason 的記錄方式**：
  - 表中的 done_reason 是 `ChatResult::finishReason`。`OllamaProvider` 把 Ollama 的 `done_reason` 對應成 enum：stop → stop、length → length，其他值 → other。
  - 所以 stop、length 等於原始值；如果出現 other，原始字串不會保留。這次 16 題都是 stop。
- **eval_count 是否包含 thinking：無法確認**。
  - Ollama API 文件（`docs/api.md`）只寫 `eval_count` 是「number of tokens in the response」，沒有提到 thinking。
  - 回應中也沒有分開的 thinking Token 欄位。
  - 實測同一個請求（h03 的歷史與追問，temperature 0）：

    | think | done_reason | prompt_eval_count | eval_count | content | thinking 字數 |
    | --- | --- | --- | --- | --- | --- |
    | 開啟 | stop | 101 | 185 | 事假會扣薪嗎？ | 280 |
    | 關閉 | stop | 107 | 9 | 請事假會扣薪嗎？ | 0 |

  - 兩次 content 長度相近，但 eval_count 差了 20 倍。這只是觀察，未經文件確認，所以不據此推算 thinking 的 Token 數。
  - 上表「合計」直接使用回應中的 `prompt_eval_count + eval_count`。即使 eval_count 包含 thinking，判斷是否撞到 num_ctx 時用的也是實際回傳的數字。

## 3. `.env` 與 `.env.example`：需要你手動加入

`.claude/hooks/protect_sensitive.sh` 會擋下 `*/.env`、`*/.env.*` 的寫入（`.env.example` 也符合），CLAUDE.md 也規定 `.env*` 由你修改，所以我沒有寫入。請把以下內容加進 `.env` 與 `.env.example`：

```dotenv
# 多輪對話（Ch13）
RAG_CONVERSATION_ENABLED=true
RAG_CONVERSATION_WINDOW_TURNS=5
# 歷史的總長度上限（字元）；超過時從最舊的一輪整輪捨去，長回答時實際保留的輪數可能少於 WINDOW_TURNS
RAG_CONVERSATION_HISTORY_BUDGET_CHARS=1500

# 改寫用的 Provider：
#   follow = 跟隨本次實際使用的回答 Provider（含 API 參數與 rag:ask --provider 逐次指定的值），
#            改寫不會把對話送到回答流程以外的供應商
#   ollama / openai = 明確指定，覆寫 follow
# 沒有改寫設定的 Provider（例如 gemini）不改寫：降級為原問題並寫 warning log，不改用另一家
RAG_REWRITE_PROVIDER=follow
RAG_REWRITE_PROMPT_VERSION=v3
RAG_REWRITE_MAX_CHARS=200

# 各 Provider 的改寫設定（MODEL 留空使用 config/llm.php 中該 Provider 的模型）
# Ollama：qwen3 關閉思考時解不開回指，開啟後改寫約 20～80 秒（M1），逾時要配合
RAG_REWRITE_OLLAMA_MODEL=
RAG_REWRITE_OLLAMA_TIMEOUT=120
RAG_REWRITE_OLLAMA_THINK=true
RAG_REWRITE_OPENAI_MODEL=
RAG_REWRITE_OPENAI_TIMEOUT=15
```

- 目前 `.env` 沒有這些鍵，所以實際上已經套用相同的預設值；加入後設定才看得到。
- 舊的 `RAG_REWRITE_MODEL`、`RAG_REWRITE_TIMEOUT` 已不再讀取，如果你之前加過，請刪除。
- `.env.example` 修改後請一起 `git add`。

## 4. PreToolUse:Write hook 錯誤（只調查，未修改）

| 項目 | 內容 |
| --- | --- |
| 設定位置 | 專案的 `.claude/settings.json`：`hooks.PreToolUse`，matcher `Write\|Edit` |
| 指令 | `bash .claude/hooks/protect_sensitive.sh`（**相對路徑**） |
| 指向檔案 | `/opt/homebrew/var/www/product/llm_rag/.claude/hooks/protect_sensitive.sh` |
| 原本用途 | 從 stdin 的 JSON 取出 `tool_input.file_path`；符合 `*.env`、`*/.env`、`*/.env.*`、`*config/database.php` 時 exit 2，阻擋 Claude 修改敏感設定檔 |

**錯誤原因**：

- 指令用相對路徑，hook 在 Claude Code 當下的工作目錄執行。
- 先前我在 Bash 中 `cd docs/notes/ch13-eval3` 檢視評估結果，工作目錄因此停在子目錄，之後的 Write 觸發 hook 時找不到檔案：
  ```
  bash: .claude/hooks/protect_sensitive.sh: No such file or directory   （exit 127）
  ```
- 已在 `docs/notes` 下用同一段指令重現。

**影響**：

- exit 127 不是 exit 2，Claude Code 視為 hook 執行錯誤，**不會阻擋寫入**。
- 也就是說，工作目錄不在專案根目錄的期間，`.env` 的保護是失效的。
- 這段期間我寫入的檔案只有 `docs/notes/` 下的報告，沒有碰到 `.env*`。
- 之後我執行指令時都先 `cd` 回專案根目錄。

**可行的修正**（你決定，我沒有修改）：

- 改用絕對路徑：`bash "$CLAUDE_PROJECT_DIR/.claude/hooks/protect_sensitive.sh"`，不受工作目錄影響。
- 也可以考慮讓 hook 在找不到檔案時 fail closed，但這需要改由外層指令判斷。

**其他相關的 hook**：上層 `/opt/homebrew/.claude/settings.json`（Homebrew 專案）有一個 Stop hook `./bin/brew lgtm`，同樣是相對路徑。這和 Write 錯誤無關，但如果它在本專案的工作階段生效，也會因為工作目錄找不到 `./bin/brew`。

## 5. backlog #19

新增「長回答時 `history_budget_chars` 比 `window_turns` 先截斷」：

- 附上 `rag:chat` 實測：每輪約 400～600 字時，Window 5 只保留 3 輪。
- 可行方向：完整保留使用者問題，只截短助理回答；或依輪數平均分配預算。

## 6. ch13-summary.md 與 CLAUDE.md

- **`docs/notes/ch13-summary.md`**：以 summary-draft 為基礎，補上你指定的四點（數字依 report-3 填寫）：
  1. 關閉思考時解不開回指，思考開啟後忠實度改善，代價是延遲。
  2. 小模型對 Prompt 很敏感：
     - N = 3 時 v1 64% vs v2 57%（註明當時以 14 題計算）。
     - N = 5 時 v1 62% vs v3 54%，v3 較常 unchanged（10 vs 6）。
     - think 開啟只測了 v3，不推論各版本的優劣。
  3. follow 的理由，以及「改寫後無候選時，改寫就是唯一一次外送」的例外。
  4. 長回答會先觸發 `history_budget_chars`。
- **`ch13-summary-draft.md`**：內容已全部併入 summary，可以刪除。我沒有刪，由你決定。
- **CLAUDE.md**：
  - 已完成章節加上 Ch13，後續章節改為 Ch14。
  - 目錄加上 `app/Rag/Conversation/`。
  - 新增「多輪對話」一節，包含：
    - 檢索一律使用改寫後的問題。
    - 歷史不得帶入引用編號。
    - 修改改寫 Prompt、Provider 或 think 設定後，必須重跑 `rag:eval-conversation`。
    - follow 與缺設定時的降級。
    - think 與泛用詞的注意事項。
  - 常用指令加上 `rag:chat`、`rag:eval-conversation`，以及 `rag:ask` 的新選項。

## 暫存檔案與 commit message

已 `git add`：

- **程式**：`app/`（Ch13 的修改與新增）、`config/rag.php`、`phpunit.xml`。
- **資料表**：三個 migration。
- **Prompt**：`resources/prompts/rag-rewrite-v1.md`、`v2.md`、`v3.md`。
- **測試**：`tests/Feature/Rag/ConversationTest.php`、`RagAnswerTest.php`、`tests/Feature/Ai/Chat/Providers/OllamaProviderTest.php`、`tests/rag-set/conversations.jsonl`。

未暫存：

- `docs/`：依規範排除。
- `.env.example`：等你加入後再暫存。
- **CLAUDE.md、AGENTS.md**：這兩個檔案從來沒有進過版控（git 顯示為 untracked），沿用過去的做法沒有加入。如果要納入版控，請自行 `git add`。

```
feat: 實作 Ch13 Query Rewriting 與 Conversation Memory：Server 端對話、Sliding Window、改寫跟隨回答 Provider
```

## 需要你注意的事

- **think 開啟後，每次追問的總等待約為改寫 20～80 秒加上回答 10～60 秒**（M1）：
  - 用 `php artisan serve` 測 API 時，Client 端的逾時要夠長。
  - Ch14 的聊天頁需要顯示「改寫中」之類的進度。
- **Ollama 只有一個 GPU 佇列**：多人同時提問時，改寫與回答會互相排隊，延遲會再加倍。
- **OpenAI 的改寫逾時 15 秒**是依實測 p95 4.5 秒加上餘裕設定的。如果回答改用 OpenAI 且網路較慢，可以再調整。
