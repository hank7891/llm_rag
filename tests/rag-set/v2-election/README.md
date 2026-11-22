# v2 測試集：台灣選舉、罷免與公投法規（Ch15，2026-11-23 凍結）

語料：全國法規資料庫 2026-11-23 版 7 部法規的官方 PDF（`source/manifest.csv`）。標準答案以交付包的 `laws/*.md` 為準，不引用網路或模型記憶。

**已凍結**：之後所有調參都用這一份。增修題目等於換尺，前後數字不能比；需要時另建 v3。

| 檔案 | 題數 | 用途 |
| --- | ---: | --- |
| `questions.jsonl` | 131 | 單題基準（`rag:eval --set=tests/rag-set/v2-election/questions.jsonl`） |
| `conversations.jsonl` | 20 | 系列對話 S01～S05（`rag:eval-conversation --set=…`） |
| `standalone.jsonl` | 20 | 系列對話的「獨立輸入」，只和多輪結果對照，不併入單題基準 |

## 來源

- `source/draft-v1.jsonl`：交付包的 71 題草稿（A01～I02）
- `source/bank-80.md`：使用者設計的 80 題題庫（Q01～Q60 單題、Q61～Q80 系列對話）

兩份都不改內容；`build.py` 只轉格式並檢查每個條號都存在且未刪除。修改來源後重新執行 `python3 tests/rag-set/v2-election/build.py`。

## 欄位與對應規則

- `answerable`：yes / no / partial。no 的 `expected` 為空；partial 列出可引用的條文，檢索要命中，但不納入門檻選值
- `expected[].document`：法規名稱取**最長相符**對應到上傳的檔名（「公職人員選舉罷免法」是「…施行細則」的子字串）
- `expected[].section`：條號 `63-1` → `第 63-1 條`（官方 PDF 的寫法，`structure_v3` 起原樣保存在 section 最後一段）
- `expected` 列多條時，Recall@k 為任一命中；All@k（全部命中）見 `rag:eval --json=` 的 `expected_ranks`
- 系列對話的歷史回答以前幾輪的「判讀要點」代替（腳本化，評估才可重現）；`expected_rewrite` 是題庫的「獨立輸入」

## 已知限制

- H08（特休遞延）在草稿中是無答案題，但知識庫保留了第一階段的人資文件，語料中其實有答案；門檻統計時單獨列出
- 測試題只問程序與規則，不涉及特定政黨、候選人或政治立場
