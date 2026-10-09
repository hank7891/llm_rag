# Ch14 第四次回報：收尾修正

- 日期：2026-10-09
- 測試：643 個全部通過。程式沒有修改，這次只改 `docs/notes/` 的報告。
- 依你的指示沒有 commit。

## 1. hook 重新實測（已寫進 `ch14-acceptance.md`「前置作業」節）

| 工作目錄 | 寫入 `docs/notes/.env.hooktest` | 結果 |
| --- | --- | --- |
| 專案根目錄 | Write 工具 | **被擋下**：`PreToolUse:Write hook error: [bash "$CLAUDE_PROJECT_DIR/.claude/hooks/protect_sensitive.sh"]: 錯誤：禁止修改敏感設定檔` |
| `docs/notes` | 先以 Bash `cd docs/notes`，確認工作目錄已切換，再用 Write 工具 | **被擋下**：同上 |

- 兩次都沒有產生檔案。
- 修正前，子目錄那次會寫入成功。

## 2. 截圖 04（重拍）：符合驗收第 4 項

- 對話 #13：「公司的特休規定是什麼？」→「那兩年年資呢？」。
- 「▼ 檢索用問題（已改寫）」已展開，顯示改寫後的問題。
- 回答「服務滿二年以上未滿三年者，給予十日特別休假 [1]」，來源「員工管理辦法.pdf　第十二條　第 2 頁」。
- rag_query_logs #442 的改寫結果：「服務滿兩年未滿三年的特別休假天數是多少？」，與截圖一致。
- 另外查證了第一題回答中的「申請系統代碼為HR-2026，相關表單請至內部網站下載 [1]」：第十二條的段落確實含有 HR-2026 與「內部網站」，引用有根據。
- 驗收總表第 4 項改為 ✔，本章驗收第 8 項改為「10 項皆通過」。

## 3. `.env` 與 `.env.example`：**沒有找到新增的設定**

```
$ grep -n "RAG_STREAM_TIME_LIMIT\|PHP_CLI_SERVER_WORKERS" .env.example .env
.env:14:# PHP_CLI_SERVER_WORKERS=4
.env.example:14:# PHP_CLI_SERVER_WORKERS=4
```

- `RAG_STREAM_TIME_LIMIT`：兩個檔案都沒有。
- `PHP_CLI_SERVER_WORKERS`：只有第 14 行被註解掉的 `# PHP_CLI_SERVER_WORKERS=4`（Laravel 範本原本就有的那行），沒有生效。
- 兩個檔案的修改時間是 15:07（`.env`）與 14:32（`.env.example`），新增的內容可能沒有存檔。
- `.env*` 受 hook 保護，我沒有修改。

**目前的影響**：

- `RAG_STREAM_TIME_LIMIT` 沒有設定時使用預設值 600 秒，與建議值相同，行為不受影響。
- `PHP_CLI_SERVER_WORKERS` 被註解：直接執行 `php artisan serve --no-reload` 只會有單一 worker；要像 CLAUDE.md 那樣在指令前面加上 `PHP_CLI_SERVER_WORKERS=4`。

要加入的內容（與 report-3 相同）：

```dotenv
# --- 串流問答與開發伺服器（Ch14）---
# 串流問答的執行時間上限（秒）；必須大於「改寫逾時 + 回答串流期限」（Ollama：120 + 300）
RAG_STREAM_TIME_LIMIT=600
# php artisan serve 的 worker 數；一條 SSE 串流會佔住一個 worker
# 必須搭配 --no-reload，否則仍只有單一 worker（.env 修改後要手動重啟）
PHP_CLI_SERVER_WORKERS=4
```

第 14 行原本的 `# PHP_CLI_SERVER_WORKERS=4` 可以直接取消註解，不需要重複加一行。

## 4. 版控

- 維持現狀：`docs/`、CLAUDE.md、AGENTS.md 都不納入。
- 略過交接文件中 `git add docs/notes/` 那一步。
- 目前暫存區是本章的 41 個程式檔案。
- `.env.example` 加入設定後，請一併 `git add .env.example`。

## 5. 回歸報告修正（`ch14-regression.md`）

- 分數差異分成兩段說明：
  - **RRF 分數**：可能來自 MySQL FULLTEXT 統計的變化（未驗證）。
  - **Reranker 分數**：改寫為「兩個可能來源，都未驗證」：
    1. llama-server 重新啟動。
    2. 平行 slot 的浮點誤差。
- 已註明 Reranker 只看問題與段落文字，和全文檢索無關。上一版歸因於候選順序與批次組合，那個說法不正確。
- 附上驗證方式：同一個 process 中重複送出同一組輸入，再重啟服務比較一次。

## 6. r03、r08 是否在 `ch13-done` 時已經出錯

**無法確認，已照實寫進 `ch14-regression.md`**：

- `ch14-baseline/` 只有檢索與多輪評估，**沒有回答層的評估**。
- 時間最接近的資料是 Ch13 單輪回歸（04:56），當時兩題都正確。但那次的程式是 Ch13 開發中的版本，**Reranker 也是關閉**的，條件和本章不同，不能當基準。
- 回答 LLM 沒有固定 temperature（backlog #13），同條件重跑也可能不同。
- 要確認，需要在 `ch13-done` 上以線上設定跑 `rag:eval-answer`，最好重複多次，或先固定 temperature。

**final-summary「下一階段」**：

- 改以 r08 為例，說明為什麼下一步是 Faithfulness：
  - 回答有合法引用、狀態正常、來源正確，目前的檢查全部通過或只抓到一部分。
  - 但「不會影響年終獎金」在參考資料中找不到。
- 同時把「r08 出錯的原因」寫成目前無法區分是 Reranker 還是 temperature，沒有下因果結論。

## 修改的檔案（都在 `docs/notes/`，不進版控）

- `ch14-acceptance.md`：第 4 項、前置作業（hook）、本章驗收第 8 項。
- `ch14-regression.md`：分數差異的成因、r03 / r08 的基準對照。
- `ch14-summary.md`：驗收全數通過、hook 修正前後。
- `final-summary.md`：部署前差距中的 hook、下一階段以 r08 說明 Faithfulness。
- `ch14-report-4.md`：本回報。
