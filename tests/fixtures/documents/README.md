# 文件解析測試樣本

全部為測試用的虛構內容，可重新產生。**不要放入真實的公司文件**（此資料夾會進版控）。

| 檔案 | 用途 | 產生方式 |
| --- | --- | --- |
| `handbook.pdf` | 3 頁中文文字型 PDF（CID 字型），每頁有頁首「公司機密」與頁尾頁碼，含全形英數字、硬換行、英文段落 | 見下方 |
| `long-handbook.pdf` | 13 頁長文件（約 1.7 萬字、qwen3 約 1.25 萬 Token），開頭／中段／結尾各埋一個事實（375 元、4,260 元、115 年 3 月 18 日），中段埋一句 Prompt Injection；Ch04 實驗用 | 以固定亂數種子產生 `source/long-handbook.html`，再用 Chrome 印成 PDF |
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
