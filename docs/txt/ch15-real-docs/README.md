# Ch15 真實文件重新校準｜交付包

第二階段第 1 章：以台灣選舉、罷免與公民投票相關法規作為真實文件，重跑第一階段的評估並重新決定相關度門檻。

- 建立日期：2026-11-23
- 法規版本：全國法規資料庫 2026-11-23 擷取（每份已用 SHA-256 與網站原文比對）

## 先讀哪個

1. `ch15-教學與執行說明.md`：本章為什麼做、會遇到什麼、怎麼做、怎麼驗收（給你看）
2. `ch15-agent-handoff.md`：交給 Coding Agent 的指令，含 5 個停止點（貼給 Agent）
3. `testset/ch15_election_testset_v1.csv`：71 題測試集草稿，請親自看過再定稿

## 使用順序

| 步驟 | 誰做 | 動作 |
| --- | --- | --- |
| 1 | 你 | 把整個資料夾放進 repo（建議 `docs/ch15/`），`laws/`、`testset/`、`scripts/` 一起 commit |
| 2 | 你 | 把 `ch15-agent-handoff.md` 交給 Agent，處理停止點 ①（舊人資文件去留） |
| 3 | 你 | 在自己的電腦執行 `bash scripts/download_official_pdfs.sh`，核對修正日期後上傳 7 份 PDF |
| 4 | 你 | 確認 7 份都變成 `indexed`，再通知 Agent 繼續 |
| 5 | Agent → 你 | 依停止點 ②～⑤ 回報、你決定 |
| 6 | Agent | 端到端抽測、篇章總結、打 `ch15-done` |

## 檔案

```
├── README.md
├── ch15-教學與執行說明.md
├── ch15-agent-handoff.md
├── laws/
│   ├── 01_中華民國憲法增修條文.md                 12 條
│   ├── 02_總統副總統選舉罷免法.md                128 條（115-08-19 修正）
│   ├── 03_總統副總統選舉罷免法施行細則.md          48 條
│   ├── 04_公職人員選舉罷免法.md                  148 條（115-07-01 修正）
│   ├── 05_公職人員選舉罷免法施行細則.md            62 條
│   ├── 06_公民投票法.md                           56 條（114-12-03 修正）
│   ├── 07_公民投票法施行細則.md                    23 條
│   ├── manifest.csv                 pcode、修正日期、條數、字數、SHA-256、官方網址與 PDF 網址
│   └── expected_articles.json       每部法規應有的條號與已刪除條號
├── testset/
│   ├── ch15_election_testset_v1.jsonl
│   └── ch15_election_testset_v1.csv（UTF-8 BOM，Excel 直接開不會亂碼）
└── scripts/
    ├── download_official_pdfs.sh    下載官方 PDF（需在可連 law.moj.gov.tw 的電腦執行）
    ├── check_article_coverage.py    檢查索引後每一條是否都有對應 Chunk
    └── build_testset.py             測試集的產生程式（增修題目可改這支再重產）
```

## laws/*.md 的格式

```
# 法規名稱
- 來源、修正日期、擷取日期
---
## 第 一 章 總則        ← 章／節／款標題
### 第 1 條            ← 條號，與全國法規資料庫一致（之 1 寫作 5-1）
條文第一項
條文第二項
```

`---` 之後的內容與全國法規資料庫逐字相同，雜湊值記在 `manifest.csv` 的 `body_sha256`，可用以下方式自行驗證：

```bash
python3 -c "import hashlib,sys;b=open(sys.argv[1],encoding='utf-8').read().split('\n---\n\n',1)[1];print(hashlib.sha256(b.encode()).hexdigest())" laws/04_公職人員選舉罷免法.md
```

## 測試集欄位

| 欄位 | 說明 |
| --- | --- |
| `id` | 類別字母＋流水號，例如 `D11` |
| `category` | single／paraphrase／exact_ref／confusable／temporal／multi_hop／no_answer／false_premise |
| `question` | 問題 |
| `expected` | `[{doc, article}]`，article 用 `63-1` 形式；無答案題為空陣列 |
| `answerable` | `yes`／`no`／`partial` |
| `key_points` | 正確回答應包含的重點（下一章 Faithfulness 人工評分用） |
| `note` | 這題的陷阱或設計用意 |

## 免責

法規內容取自政府公開資料（依著作權法第 9 條，法律不得為著作權之標的）。本交付包用於 RAG 學習與評估，系統回答不構成法律意見；實際選務以中央選舉委員會公告為準。
