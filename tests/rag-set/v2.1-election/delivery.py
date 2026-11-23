#!/usr/bin/env python3
"""v2.1：「以條計、前 20 名」與「線上前 5 名、答案段落送達率」並列（Ch15 結案）。

用法：
  php artisan rag:eval --set=tests/rag-set/v2.1-election/questions.jsonl --json=raw.json   （容器內，照線上設定）
  python3 tests/rag-set/v2.1-election/delivery.py raw.json [--range=Q01-Q60] > report.md

兩組條件不同，不排名、不相減：
  前 20 名：rag:eval 第一次檢索（不套門檻、Top-20），看「找不找得到」
  線上前 5 名：rag:eval 的 context（套門檻、預設 Top-K、keep_exact 照設定），即實際送進 LLM 的段落
送達規則見 README「答案段落送達率」。
"""
import json
import re
import sys
from collections import defaultdict
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "v2-election"))
import analyze as A  # noqa: E402

A.LABEL["no_answer"] = "無答案類（partial）"
SEGMENTS = {k: v for k, v in json.loads((HERE / "answer_segments.json").read_text(encoding="utf-8")).items() if not k.startswith("_")}


def squash(text):
    return re.sub(r"\s", "", text or "")


def in_range(qid, rng):
    lo, hi = rng
    return qid[0] == lo[0] and qid[1:].isdigit() and int(lo[1:]) <= int(qid[1:]) <= int(hi[1:])


def same_article(e, c):
    return A.matches(e, c)


def delivered(q, e):
    """期望條文 e 的答案段落是否在 context 中"""
    ctx = q["context"] or []
    texts = [s["text"] for s in SEGMENTS.get(q["id"], []) if s["document"] == e["document"] and s["section"] == e["section"]]
    if not texts:
        return any(same_article(e, c) for c in ctx)
    return all(any(same_article(e, c) and squash(t) in squash(c["content"]) for c in ctx) for t in texts)


def article_in_context(q, e):
    return any(same_article(e, c) for c in q["context"] or [])


def online(q):
    exp = q["expected"]
    return {
        "gate": bool(q["context"]),
        "art_any": any(article_in_context(q, e) for e in exp),
        "seg_any": any(delivered(q, e) for e in exp),
        "seg_all": all(delivered(q, e) for e in exp),
        "doc1": bool(q["context"]) and q["context"][0]["document"] in {e["document"] for e in exp},
    }


def diagnose(q, e, raw):
    """期望條文 e（的答案段落）沒有送進線上 5 段的原因"""
    if not q["context"]:
        return "門檻擋下（Dense 最高分未超過門檻、無精確命中）"
    sent = {c["chunk_id"] for c in q["context"]}
    # 條文已有段落送達時，含答案的是同條文中沒送達的另一段（前 20 名沒有段落內容，以此推定）
    prefix = "條文的其他段落有送達，含答案的段落沒有：" if article_in_context(q, e) else ""
    hits = [c for c in q["top"] if same_article(e, c) and c["chunk_id"] not in sent]
    if not hits:
        return prefix + "前 20 名也沒有"
    c = hits[0]
    t = raw["threshold"]
    if not c["exact_match"] and (c["dense_score"] is None or c["dense_score"] <= t):
        dense = "Dense 不在前 20" if c["dense_score"] is None else f"Dense {c['dense_score']:.4f} ≤ 門檻"
        return prefix + f"{dense}，{'只有一般關鍵字命中（exact_only 排除）' if c['keyword_rank'] else '也沒有關鍵字命中'}"
    if not c["exact_match"] and q["exact_hit_count"] >= raw["context_top_k"]:
        return prefix + f"精確命中 {q['exact_hit_count']} 筆占滿名額（keep_exact），本段不是精確命中"
    return prefix + f"重排後名次 {c['rerank_rank']}，在 {raw['context_top_k']} 名外"


def pct(n, d):
    return A.pct(n, d)


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    opts = dict(a[2:].split("=", 1) for a in sys.argv[1:] if a.startswith("--"))
    rng = tuple(opts.get("range", "Q01-Q60").split("-"))
    raw = A.load(args[0])
    qs = [q for q in raw["questions"] if in_range(q["id"], rng)]
    ans = A.answerable(qs)
    n = len(ans)
    on = {q["id"]: online(q) for q in ans}

    n_, r1, r5, mrr, doc1 = A.metrics(ans)
    all5 = sum(1 for q in ans if all(r is not None and r <= 5 for r in q["expected_ranks"]))
    gate = sum(o["gate"] for o in on.values())
    art = sum(o["art_any"] for o in on.values())
    seg_any = sum(o["seg_any"] for o in on.values())
    seg_all = sum(o["seg_all"] for o in on.values())
    odoc1 = sum(o["doc1"] for o in on.values())

    out = [
        f"- 原始資料：`{args[0]}`（set `{raw['set']}`，mode={raw['mode']}，rerank={raw['rerank']}，門檻 {raw['threshold']}，線上 Top-K {raw['context_top_k']}，模型 {raw['model']}）",
        f"- 範圍：{rng[0]}～{rng[1]}，{len(qs)} 題；以下只計 yes＋partial {n} 題",
        "- **兩組條件不同，數字不排名、不相減**",
        "",
        "## 以條計、前 20 名（不套門檻）\n",
        A.table(["題數", "Recall@1", "Recall@5", "All@5", "MRR", "文件層級 Top-1"],
                [[n, pct(r1, n), pct(r5, n), pct(all5, n), f"{mrr:.3f}", pct(doc1, n)]]),
        "",
        "## 線上前 5 名（套門檻、keep_exact 照設定）\n",
        A.table(["題數", "通過門檻", "期望條文在 5 段內（以條計，任一）", "答案段落送達（任一）", "答案段落送達（全部）", "第 1 段的法規正確"],
                [[n, pct(gate, n), pct(art, n), pct(seg_any, n), pct(seg_all, n), pct(odoc1, n)]]),
        "",
        "## 分類別\n",
        "前 20 名：Recall@1 / Recall@5；線上：以條計 / 段落送達（任一）/ 段落送達（全部）\n",
    ]
    rows = []
    for cat in A.CATS:
        cq = [q for q in ans if q["type"] == cat]
        if not cq:
            continue
        c = len(cq)
        _, cr1, cr5, _, _ = A.metrics(cq)
        rows.append([A.LABEL[cat], c, f"{pct(cr1, c)} / {pct(cr5, c)}",
                     f"{pct(sum(on[q['id']]['art_any'] for q in cq), c)} / {pct(sum(on[q['id']]['seg_any'] for q in cq), c)} / {pct(sum(on[q['id']]['seg_all'] for q in cq), c)}"])
    out.append(A.table(["類別", "題數", "前 20 名", "線上前 5 名"], rows))

    out += ["", "## 兩組結果不一致的題目\n",
            "前 20 名的 Recall@5 命中，但線上答案段落沒有全部送達；或反過來。原因逐條由原始資料判斷（見 `diagnose()`）。\n"]
    rows = []
    for q in ans:
        o = on[q["id"]]
        top5 = q["rank"] is not None and q["rank"] <= 5
        if top5 == o["seg_all"] and o["seg_all"] == o["art_any"]:
            continue
        why = "；".join(f"{A.short(e['document'])} {e['section']}：{diagnose(q, e, raw)}" for e in q["expected"] if not delivered(q, e)) or "線上送達、前 20 名的 Top-5 沒有"
        ctx = "、".join(f"{A.short(c['document'])} {(c['section'] or '').split(' / ')[-1]}{'（精確）' if c['exact_match'] else ''}" for c in q["context"] or []) or "—"
        rows.append([q["id"], A.LABEL[q["type"]], q["question"], q["rank"] or "—", "是" if o["art_any"] else "否",
                     "是" if o["seg_any"] else "否", "是" if o["seg_all"] else "否", why, ctx])
    out.append(A.table(["id", "類別", "問題", "前 20 名名次", "線上以條計", "段落（任一）", "段落（全部）", "未送達的原因", "線上 5 段"], rows) if rows else "（無）")

    out += ["", "## 多段條文 11 題逐題（人工標註含答案的原句）\n"]
    rows = []
    for q in ans:
        if q["id"] not in SEGMENTS:
            continue
        for s in SEGMENTS[q["id"]]:
            e = {"document": s["document"], "section": s["section"]}
            hit = [c for c in q["context"] or [] if same_article(e, c)]
            has = [c for c in hit if squash(s["text"]) in squash(c["content"])]
            top_pos = next((i + 1 for i, c in enumerate(q["top"]) if same_article(e, c) and c["chunk_id"] in {x["chunk_id"] for x in has}), None)
            rows.append([q["id"], f"{A.short(s['document'])} {s['section']}", s["text"][:30] + ("…" if len(s["text"]) > 30 else ""),
                         "、".join(str(c["chunk_id"]) for c in hit) or "—", "是" if has else "**否**"])
    out.append(A.table(["id", "條文", "含答案的原句", "線上送達的該條段落（chunk_id）", "含答案的段落送達"], rows))
    sys.stdout.write("\n".join(out) + "\n")


if __name__ == "__main__":
    main()
