# 文件解析測試樣本

全部為測試用的虛構內容，可重新產生。**不要放入真實的公司文件**（此資料夾會進版控）。

| 檔案 | 用途 | 產生方式 |
| --- | --- | --- |
| `handbook.pdf` | 3 頁中文文字型 PDF（CID 字型），每頁有頁首「公司機密」與頁尾頁碼，含全形英數字、硬換行、英文段落 | 見下方 |
| `long-handbook.pdf` | 19 頁、49 條的長文件（約 2.2 萬字），開頭（第 1 頁）／中段（第 10 頁）／結尾（第 19 頁）各埋一個事實（375 元、4,260 元、115 年 3 月 18 日），中段埋一句 Prompt Injection；Ch04、Ch05 實驗用 | `python3 source/generate-long-handbook.py`，再用 Chrome 印成 PDF。Ch05 修正：舊版（Ch04 實驗時）每頁第 4 條因版面溢出被裁掉，實際只有 39 條 |
| `regulation.pdf` | 4 頁規章：章（第一章／第 3 章）、條（中文與阿拉伯數字）、之一條號、超長條文（第四條）、跨頁條文（第六條，第 3～4 頁）、內文引用（其中兩處以 `<br>` 強制排到行首）；Ch05 切段用 | Chrome 印 `source/regulation.html` |
| `it-notice.txt` | 沒有任何結構的公告（10 段）；Ch05 無結構文件 | 手寫 |
| `onboarding.md` | 含 `#`～`###` 標題的 Markdown，「## 報到流程」後直接接「###」；Ch05 Markdown 結構 | 手寫 |
| `scanned.pdf` | 掃描型 PDF（只有圖片、沒有文字層） | `handbook.pdf` 第 1 頁轉成圖片再包成 PDF |
| `notice-utf8.txt` | UTF-8 純文字 | 手寫 |
| `notice-utf8-bom.txt` | UTF-8 BOM | `notice-utf8.txt` 前面加上 `EF BB BF` |
| `notice-big5.txt` | Big5 | `iconv -f UTF-8 -t CP950 notice-utf8.txt` |
| `guide.md` | Markdown，含標題與清單 | 手寫 |
| `broken.pdf` | 副檔名是 .pdf、內容是純文字的偽裝檔（上傳時就會被 MIME 檢查擋下） | 手寫 |
| `corrupted.pdf` | 真的 PDF 但內容被截斷（能通過上傳檢查，解析時失敗） | `head -c 3000 handbook.pdf > corrupted.pdf` |

## 重新產生 PDF

```bash
cd tests/fixtures/documents
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --no-pdf-header-footer \
  --print-to-pdf=handbook.pdf "file://$PWD/source/handbook.html"
pdftoppm -r 60 -png -f 1 -l 1 handbook.pdf scan && sips -s format pdf scan-1.png --out scanned.pdf && rm scan-1.png
```
