#!/usr/bin/env bash
# 從全國法規資料庫下載 7 部法規的官方 PDF（在你自己的電腦上執行）。
# 用法：bash scripts/download_official_pdfs.sh [輸出資料夾，預設 ./official_pdfs]
# 下載後請打開每份 PDF 第一頁，確認「修正日期」與 laws/manifest.csv 一致；
# 不一致代表法規在 2026-11-23 之後又修正過，測試集的對應題目要重新核對。
set -euo pipefail

OUT="${1:-./official_pdfs}"
mkdir -p "$OUT"

# 檔名|pcode
LAWS=(
  "01_中華民國憲法增修條文|A0000002"
  "02_總統副總統選舉罷免法|D0020053"
  "03_總統副總統選舉罷免法施行細則|D0020054"
  "04_公職人員選舉罷免法|D0020010"
  "05_公職人員選舉罷免法施行細則|D0020011"
  "06_公民投票法|D0020050"
  "07_公民投票法施行細則|D0020080"
)

for item in "${LAWS[@]}"; do
  name="${item%%|*}"
  pcode="${item##*|}"
  url="https://law.moj.gov.tw/LawClass/FilesType.aspx?DataId=${pcode}&md=m"
  dest="$OUT/${name}.pdf"
  echo "下載 ${name} ..."
  curl -fsSL --retry 2 -o "$dest" "$url"
  # 確認拿到的是 PDF，而不是錯誤頁
  if ! head -c 5 "$dest" | grep -q '%PDF-'; then
    echo "  ✗ ${name} 不是 PDF，請改用瀏覽器開 ${url} 手動下載" >&2
    rm -f "$dest"
    continue
  fi
  pages=$( (command -v pdfinfo >/dev/null && pdfinfo "$dest" | awk '/^Pages:/{print $2}') || echo "?")
  echo "  ✓ $(du -h "$dest" | cut -f1)，${pages} 頁"
  sleep 1   # 對公家網站客氣一點
done

echo "完成，檔案在 $OUT"
