# Ch14 第三次回報：第二階段完成

- 日期：2026-10-09
- 測試：643 個全部通過；`pint --test --dirty` 通過；`npm run build` 成功。
- 本章的程式已 `git add`：41 個檔案，不含 `docs/`、`.env*`、CLAUDE.md、AGENTS.md。commit 與 tag `ch14-done` 由你執行。

## 一、問題 1 修正：重新載入就是新對話

- `resources/js/knowledge-chat.js` 移除 sessionStorage 的保存與恢復。`conversation_id` 只存在頁面記憶體中。
- 新增 `test_chat_script_does_not_persist_conversation_across_reloads`：問答頁的 JS 不得使用 sessionStorage / localStorage，防止日後又加回去。
- 「從伺服器恢復對話內容」列入 backlog #20。

## 二、你的決定與補充

| # | 項目 | 處理 |
| --- | --- | --- |
| 1 | CORS | 新增 `config/cors.php`，`allowed_origins` 只允許 `APP_URL`；新增 2 個測試（前置請求、跨站 POST），反向驗證通過。final-summary 已記下「只能防止跨站讀取、不能阻擋盲送」與 llama-server 沒有 API Key |
| 2 | hook 實測 | 見第三節：**從子目錄寫入沒有被擋下** |
| 3 | 基準多輪評估 | 原本只跑一次，已補跑第二次。兩次（加上本章一次）16 題完全相同，見第四節 |
| 4 | backlog：串流中斷沒有記錄 Token | **與既有的 #1（Ch02）重複**，已把 Ch14 的內容併入 #1，沒有另開一項。原本規劃的 #22（Markdown）因此改為 #21 |
| 5 | Reranker 讓多輪 Recall@1 85% → 100% | 已寫進 final-summary 第二節 |
| 6 | 逐張檢查截圖 | **04 需要補拍**（見第五節），其餘符合 |
| 7 | 刪除後的 Points | `rag:index-check` 沒有孤兒；直接查 Qdrant，兩個 Collection 的 `document_id = 20` 都是 0 筆（對照 #17 有 4 筆），已寫進驗收第 5 項 |

觀察 2（資料不足的題目整題仍花 33 秒，全部在改寫）已寫進 ch14-summary 與驗收第 3 項。

## 三、hook 實測

| 工作目錄 | 寫入 `docs/notes/.env.hooktest`（符合 `*/.env.*`） | 結果 |
| --- | --- | --- |
| 專案根目錄 | 被擋下：`PreToolUse:Write hook error: … 錯誤：禁止修改敏感設定檔` | 保護有效 |
| `docs/notes` | **寫入成功**（hook 找不到 `.claude/hooks/protect_sensitive.sh`，exit 127，不阻擋） | **保護失效** |

- 為了避免 hook 失效時真的覆蓋 `.env`，測試沒有使用 `.env`，而是用無害的檔名。子目錄那次建立的測試檔已刪除。
- `.claude/settings.json` 目前仍是 `bash .claude/hooks/protect_sensitive.sh`，前置作業中的修正還沒做。
- 建議改成 `bash "$CLAUDE_PROJECT_DIR/.claude/hooks/protect_sensitive.sh"`，改完後重做上表的兩項測試。

## 四、回歸結果（詳見 `ch14-regression.md`）

**檢索（questions / hybrid / reasoning）**：

- 每題的名次、門檻判斷、進入 Context、重排前→後全部與基準相同。
- 只有 2 題的分數小數不同：q23 的 RRF 0.0320 → 0.0323；hybrid n01 的 Top-1 0.0301 → 0.0291、重排分數 −1.364 → −1.369。
- 推測原因：兩次之間上傳又刪除了驗收文件，MySQL FULLTEXT 的統計因此改變。沒有驗證，報告中已註明。

**多輪評估（基準 #1、基準 #2、本章）**：

- 三次都是 Recall@1 / @5 100%，unchanged 3 題，降級 0。
- 16 題的改寫結果文字、改寫狀態、命中名次完全相同，只有延遲不同（p50 約 28～30 秒）。

**引用指標（`rag:eval-answer`）**：

- questions：引用率與命中率都是 23 / 23。
- reasoning 有 2 題異常，都是回答 LLM 的行為，不是本章的修改造成的：
  - r03：開頭說「資料不足」又作答，還混有簡體字，被判為資料不足。
  - r08：回答「不會影響年終獎金」，文件沒有這個資訊，屬於 Grounding 失敗。

## 五、截圖檢查

| 截圖 | 驗收 | 檢查結果 |
| --- | --- | --- |
| 01-upload-status.png | 2 | ✔ #20 索引完成，3 頁、10 Chunk |
| 03-answer-citation.png | 1、2 | ✔ Ollama：2,800 元，第六條 第 2 頁；追問引用第二條 |
| 04-followup.png | 4 | **✘ 需補拍**：回答（十日）與來源（第十二條）都有，但「▶ 檢索用問題（已改寫）」是收合的，看不到改寫後的問題。請展開後重新截圖、覆蓋原檔。目前以 rag_query_logs #395 作為輔助證據（改寫為「服務滿兩年未滿三年的年資，特休天數是多少？」） |
| 05-insufficient.png | 3 | ✔ 資料不足、未呼叫模型、「產生回答」劃掉 |
| 06-openai.png | 1 | ✔ OpenAI：答案與來源與 03 相同，雲端提示為黃色 |
| 10-after-delete.png | 5 | ✔ 刪除後資料不足；畫面中的對話 #12 正是問題 1，不影響本項 |

本章驗收第 4 項（含 `<script>` 的文件）與第 5 項（問答中開 `/document`）沒有在瀏覽器實測。驗收報告中標示為△，並寫明是以程式、測試或 curl 驗證。

## 六、產出

| 檔案 | 內容 |
| --- | --- |
| `docs/notes/ch14-regression.md` | 回歸比對 |
| `docs/notes/ch14-acceptance.md` | 10 項最終驗收、本章驗收、`rag:stats` |
| `docs/notes/ch14-summary.md` | 本章總結 |
| `docs/notes/final-summary.md` | 14 章的決策、最終線上設定、分類後的 backlog、部署前差距、下一階段 |
| `docs/notes/backlog.md` | #1 補充 Ch14；新增 #20、#21 |
| `CLAUDE.md` | 串流與非串流共用 Pipeline、模型輸出不得以 HTML 插入、CORS、`--no-reload`、`rag:stats`、目錄結構 |

### rag:stats（Ch14 期間，07:20 起，不含評估提問）

- 共 12 筆：answered 8、資料不足（無候選）3、interrupted 1。
- 改寫 fallback 0 / 5、Rerank 降級 0 / 9。
- 改寫 p50 / p95 為 32 / 36 秒，回答 12 / 25 秒，總計 22 / 43 秒。

## 七、需要你處理的事

1. **補拍截圖 04**：展開「檢索用問題」。
2. **hook 改為絕對路徑**，並重做第三節的兩項測試。
3. **`.env` 與 `.env.example` 加入**（目前兩個檔都還沒有）：

   ```dotenv
   # --- 串流問答與開發伺服器（Ch14）---
   # 串流問答的執行時間上限（秒）；必須大於「改寫逾時 + 回答串流期限」（Ollama：120 + 300）
   RAG_STREAM_TIME_LIMIT=600
   # php artisan serve 的 worker 數；一條 SSE 串流會佔住一個 worker
   # 必須搭配 --no-reload，否則仍只有單一 worker（.env 修改後要手動重啟）
   PHP_CLI_SERVER_WORKERS=4
   ```

   加入後 `git add .env.example`。
4. **docs/、CLAUDE.md、AGENTS.md 是否納入版控**（第一次回報的決策 9，尚未決定）：
   - 交接文件的收尾步驟是 `git add docs/notes/`，但 CLAUDE.md 規定排除 `docs/`。
   - 如果要納入，CLAUDE.md 的 Git 規範要一起修改。
   - `docs/notes/ch14-baseline/`、`ch14-regression/` 和截圖也在 `docs/` 底下。
5. **commit 與 tag**：

   ```
   feat: 實作 Ch14 UI 與最終驗收：三個頁面、RAG 串流問答、降級統計
   ```

   加上 `git tag ch14-done`。交接文件也提到可選擇再打 `v0.1-poc`。
