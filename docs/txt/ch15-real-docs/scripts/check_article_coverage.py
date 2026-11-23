#!/usr/bin/env python3
"""檢查索引後的 Chunk 是否涵蓋每一條條文、哪些條文被切成多段。

用法：
  python3 scripts/check_article_coverage.py chunks.json laws/expected_articles.json

chunks.json 由 Agent 從 MySQL 匯出，格式為陣列，每筆至少含：
  {"document_name": "...", "section": "第 63-1 條", "content": "..."}
document_name 只要「包含」法規名稱即可（例如 04_公職人員選舉罷免法.pdf）。

條號寫法會被正規化，以下都視為同一條：
  第 63-1 條 / 第63條之1 / 第六十三條之一
"""
import json, re, sys
from collections import defaultdict

CN = {"零": 0, "一": 1, "二": 2, "三": 3, "四": 4, "五": 5, "六": 6, "七": 7, "八": 8, "九": 9}

def cn2int(s: str) -> int:
    if s.isdigit():
        return int(s)
    total, num = 0, 0
    for ch in s:
        if ch in CN:
            num = CN[ch]
        elif ch == "十":
            total += (num or 1) * 10; num = 0
        elif ch == "百":
            total += (num or 1) * 100; num = 0
    return total + num

def norm(section: str):
    """回傳正規化條號字串，例如 '63-1'；抓不到回傳 None。"""
    if not section:
        return None
    s = section.replace(" ", "")
    m = re.search(r"第([0-9零一二三四五六七八九十百]+)(?:-([0-9]+))?條(?:之([0-9零一二三四五六七八九十]+))?", s)
    if not m:
        return None
    main = cn2int(m.group(1))
    sub = m.group(2) or (str(cn2int(m.group(3))) if m.group(3) else None)
    return f"{main}-{sub}" if sub else str(main)

def main():
    chunks = json.load(open(sys.argv[1], encoding="utf-8"))
    expected = json.load(open(sys.argv[2], encoding="utf-8"))
    seen = defaultdict(lambda: defaultdict(int))   # law -> article -> chunk count
    unmatched_docs, no_section = set(), 0
    for c in chunks:
        # 取最長的相符名稱：「公職人員選舉罷免法」是「…施行細則」的子字串，不能取第一個
        hits = [name for name in expected if name in c.get("document_name", "")]
        law = max(hits, key=len) if hits else None
        if law is None:
            unmatched_docs.add(c.get("document_name")); continue
        art = norm(c.get("section", ""))
        if art is None:
            no_section += 1; continue
        seen[law][art] += 1

    ok = True
    for law, info in expected.items():
        exp = [a for a in info["articles"] if a not in info["deleted"]]
        got = seen.get(law, {})
        missing = [a for a in exp if a not in got]
        split = {a: n for a, n in got.items() if n > 1}
        extra = [a for a in got if a not in info["articles"]]
        print(f"\n== {law}：應有 {len(exp)} 條（不含刪除 {len(info['deleted'])} 條），索引到 {len(got)} 條")
        if missing:
            ok = False; print(f"  ✗ 缺少 {len(missing)} 條：{', '.join(missing[:30])}{' …' if len(missing) > 30 else ''}")
        if extra:
            ok = False; print(f"  ✗ 多出不存在的條號（多半是解析錯誤）：{', '.join(extra[:30])}")
        if split:
            print(f"  · 被切成多段的條文 {len(split)} 條：" + ", ".join(f"{a}×{n}" for a, n in sorted(split.items(), key=lambda x: -x[1])[:20]))
    if unmatched_docs:
        print(f"\n· 不屬於 7 部法規的文件：{sorted(d for d in unmatched_docs if d)}")
    if no_section:
        ok = False; print(f"\n✗ 有 {no_section} 個 Chunk 抓不到條號（section 為空或格式未知）")
    print("\n結果：" + ("通過" if ok else "未通過"))
    sys.exit(0 if ok else 1)

if __name__ == "__main__":
    main()
