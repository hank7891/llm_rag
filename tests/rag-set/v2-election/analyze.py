#!/usr/bin/env python3
"""分析 rag:eval --json= 的原始資料（Ch15）：分類別指標、文件層級 Top-1、混淆矩陣、精確條號題、門檻掃描。

用法：
  python3 tests/rag-set/v2-election/analyze.py eval  E1=raw-E1.json E2=raw-E2.json E3=raw-E3.json  > report.md
  python3 tests/rag-set/v2-election/analyze.py gate  raw-E3.json [--exclude=H08] [--old=0.59]          > threshold.md

命中規則與 ExpectedSource 相同：文件名稱相同，且 section 最後一段與期望條號完全相同。
Gate 重算規則與 RetrieverService::retrieve() 相同：Dense 最高分 > 門檻，或有精確命中（exact_hit_count > 0）。
"""
import json, sys
from collections import Counter, defaultdict

CATS = ["single", "paraphrase", "exact_ref", "confusable", "temporal", "multi_hop", "no_answer", "false_premise"]
LABEL = {"single": "單一條文", "paraphrase": "口語改寫", "exact_ref": "精確條號", "confusable": "跨法易混淆", "temporal": "修法時效",
         "multi_hop": "多條組合", "no_answer": "無答案類（partial）", "false_premise": "錯誤前提"}
SHORT = {"01_中華民國憲法增修條文.pdf": "憲增", "02_總統副總統選舉罷免法.pdf": "總統法", "03_總統副總統選舉罷免法施行細則.pdf": "總統細則",
         "04_公職人員選舉罷免法.pdf": "公職法", "05_公職人員選舉罷免法施行細則.pdf": "公職細則", "06_公民投票法.pdf": "公投法", "07_公民投票法施行細則.pdf": "公投細則"}


def short(doc):
    return SHORT.get(doc, "人資文件" if doc else "—")


def matches(e, c):
    return c["document"] == e["document"] and c["section"] is not None and c["section"].split(" / ")[-1] == e["section"]


def pct(n, d):
    return "—" if d == 0 else f"{n / d * 100:.0f}%"


def percentile(values, p):
    v = sorted(values)
    return v[max(0, -(-p * len(v) // 100) - 1)] if v else None


def load(path):
    return json.load(open(path, encoding="utf-8"))


def answerable(qs):
    return [q for q in qs if q["answerable"] != "no"]


def metrics(qs):
    n = len(qs)
    r1 = sum(1 for q in qs if q["rank"] == 1)
    r5 = sum(1 for q in qs if q["rank"] is not None and q["rank"] <= 5)
    mrr = sum(1 / q["rank"] for q in qs if q["rank"]) / n if n else 0
    doc1 = sum(1 for q in qs if q["top"] and q["top"][0]["document"] in {e["document"] for e in q["expected"]})
    return n, r1, r5, mrr, doc1


def table(headers, rows):
    out = ["| " + " | ".join(headers) + " |", "|" + " --- |" * len(headers)]
    out += ["| " + " | ".join(str(c) for c in r) + " |" for r in rows]
    return "\n".join(out)


def eval_report(runs):
    out = []
    names = list(runs)
    out.append("## 總表（有答案題 = yes + partial，不套門檻）\n")
    rows = []
    for name, raw in runs.items():
        qs = answerable(raw["questions"])
        n, r1, r5, mrr, doc1 = metrics(qs)
        lat = [q["latency_ms"] for q in raw["questions"]]
        rows.append([name, n, pct(r1, n), pct(r5, n), f"{mrr:.3f}", pct(doc1, n), percentile(lat, 50), percentile(lat, 95)])
    out.append(table(["組", "題數", "Recall@1", "Recall@5", "MRR", "文件層級 Top-1", "檢索 p50 ms", "檢索 p95 ms"], rows))

    out.append("\n## 分類別（Recall@1 / Recall@5 / MRR / 文件層級 Top-1）\n")
    rows = []
    for cat in CATS:
        row = [LABEL[cat]]
        for name, raw in runs.items():
            qs = [q for q in answerable(raw["questions"]) if q["type"] == cat]
            n, r1, r5, mrr, doc1 = metrics(qs)
            row.append("—" if n == 0 else f"{pct(r1, n)} / {pct(r5, n)} / {mrr:.2f} / {pct(doc1, n)}")
        n = len([q for q in answerable(next(iter(runs.values()))["questions"]) if q["type"] == cat])
        rows.append([row[0], n] + row[1:])
    out.append(table(["類別", "題數"] + names, rows))

    out.append("\n## 多條組合（expected 兩條以上）：任一命中 vs 全部命中（Top-5）\n")
    rows = []
    for name, raw in runs.items():
        qs = [q for q in answerable(raw["questions"]) if len(q["expected"]) > 1]
        any5 = sum(1 for q in qs if q["rank"] is not None and q["rank"] <= 5)
        all5 = sum(1 for q in qs if all(r is not None and r <= 5 for r in q["expected_ranks"]))
        rows.append([name, len(qs), pct(any5, len(qs)), pct(all5, len(qs))])
    out.append(table(["組", "題數", "Recall@5（任一）", "All@5（全部）"], rows))
    name = names[-1]
    qs = [q for q in answerable(runs[name]["questions"]) if len(q["expected"]) > 1]
    out.append(f"\n{name} 逐題（各期望條文的名次，— 為 Top-20 內沒有）：\n")
    out.append(table(["id", "類別", "期望", "各自名次"], [[q["id"], LABEL[q["type"]], "、".join(f"{short(e['document'])} {e['section']}" for e in q["expected"]), " / ".join("—" if r is None else str(r) for r in q["expected_ranks"])] for q in qs]))

    for name, raw in runs.items():
        out.append(f"\n## 混淆矩陣：期望法規 × Top-1 法規（{name}，只計單一法規的有答案題）\n")
        m = defaultdict(Counter)
        for q in answerable(raw["questions"]):
            docs = {e["document"] for e in q["expected"]}
            if len(docs) != 1:
                continue
            m[short(next(iter(docs)))][short(q["top"][0]["document"]) if q["top"] else "—"] += 1
        cols = [v for v in SHORT.values()] + ["人資文件"]
        cols = [c for c in cols if any(m[r][c] for r in m)]
        rows = [[r, sum(m[r].values())] + [m[r][c] or "" for c in cols] for r in SHORT.values() if r in m]
        out.append(table(["期望 \\ Top-1", "題數"] + cols, rows))

    out.append("\n## 精確條號題（exact_ref）逐題\n")
    out.append("正確條文的名次（E1 / E2 / E3），以及 Hybrid 時正確 Chunk 是否被一般關鍵字（FULLTEXT 名次）或精確詞命中。\n")
    by = {name: {q["id"]: q for q in raw["questions"]} for name, raw in runs.items()}
    hy = by[names[1]] if len(names) > 1 else by[names[0]]
    rows = []
    for q in runs[names[0]]["questions"]:
        if q["type"] != "exact_ref":
            continue
        h = hy[q["id"]]
        hit = next((c for c in h["top"] if any(matches(e, c) for e in h["expected"])), None)
        rows.append([q["id"], q["question"], " / ".join(str(by[n][q["id"]]["rank"] or "—") for n in names),
                     h["exact_hit_count"], "—" if hit is None else (hit["keyword_rank"] or "未命中"), "—" if hit is None else ("是" if hit["exact_match"] else "否"),
                     short(h["top"][0]["document"]) + " " + h["top"][0]["section"].split(" / ")[-1] if h["top"] else "—"])
    out.append(table(["id", "問題", "名次 " + "/".join(names), "精確命中數", "正確 Chunk 的 FULLTEXT 名次", "正確 Chunk 精確命中", f"{names[1] if len(names) > 1 else names[0]} Top-1"], rows))

    out.append(f"\n## 未進 Top-5 的有答案題（{names[-1]}）\n")
    rows = []
    for q in answerable(runs[names[-1]]["questions"]):
        if q["rank"] is None or q["rank"] > 5:
            t = q["top"][0] if q["top"] else None
            rows.append([q["id"], LABEL[q["type"]], q["question"], "、".join(f"{short(e['document'])} {e['section']}" for e in q["expected"]), q["rank"] or "—",
                         "—" if t is None else f"{short(t['document'])} {t['section'].split(' / ')[-1] if t['section'] else ''}"])
    out.append(table(["id", "類別", "問題", "期望", "名次", "Top-1"], rows))
    return "\n".join(out) + "\n"


def gate(q, t):
    return (q["dense_top_score"] is not None and q["dense_top_score"] > t) or (q["exact_hit_count"] or 0) > 0


def correct_dense(q):
    scores = [c["dense_score"] for c in q["top"] if c["dense_score"] is not None and any(matches(e, c) for e in q["expected"])]
    return max(scores) if scores else None


def gate_report(raw, exclude, old):
    qs = raw["questions"]
    yes = [q for q in qs if q["answerable"] == "yes"]
    no = [q for q in qs if q["answerable"] == "no" and q["id"] not in exclude]
    partial = [q for q in qs if q["answerable"] == "partial"]
    ex = [q for q in qs if q["id"] in exclude]
    out = [f"- 原始資料：{raw['set']}，mode={raw['mode']}，rerank={raw['rerank']}，模型 {raw['model']}",
           f"- yes {len(yes)} 題、no {len(no)} 題（排除 {', '.join(exclude) or '無'}）、partial {len(partial)} 題（單獨列出，不納入選值）",
           "- Gate：Dense 最高分 > 門檻，或有精確命中（與 RetrieverService 相同）\n"]

    def dist(label, values):
        v = sorted(x for x in values if x is not None)
        return [label, len(values), len(v), f"{v[0]:.4f}", f"{percentile(v, 5):.4f}", f"{percentile(v, 50):.4f}", f"{percentile(v, 95):.4f}", f"{v[-1]:.4f}"] if v else [label, len(values), 0] + ["—"] * 5

    out.append("## 兩群分數分布（Dense Cosine）\n")
    out.append(table(["群", "題數", "有分數", "min", "p5", "p50", "p95", "max"], [
        dist("yes：正確 Chunk 的 Dense 分數", [correct_dense(q) for q in yes]),
        dist("yes：Top-1 Dense 分數（Gate 實際看的值）", [q["dense_top_score"] for q in yes]),
        dist("no：Top-1 Dense 分數", [q["dense_top_score"] for q in no]),
        dist("partial：Top-1 Dense 分數", [q["dense_top_score"] for q in partial]),
    ]))
    exact_no = [q["id"] for q in no if (q["exact_hit_count"] or 0) > 0]
    out.append(f"\n精確命中會繞過門檻：no 題中有精確命中的 {len(exact_no)} 題（{', '.join(exact_no) or '無'}），任何門檻都擋不下。")

    out.append("\n## 門檻掃描（0.40～0.75，分數 > 門檻才通過）\n")
    rows, candidates = [], []
    for i in range(40, 76):
        t = i / 100
        blocked_yes = [q["id"] for q in yes if not gate(q, t)]
        passed_no = [q["id"] for q in no if gate(q, t)]
        rate = (len(no) - len(passed_no)) / len(no)
        rows.append([f"{t:.2f}", len(blocked_yes), len(passed_no), f"{rate * 100:.0f}%", sum(1 for q in partial if not gate(q, t))])
        if rate >= 0.9:
            candidates.append((len(blocked_yes), t))
    out.append(table(["門檻", "yes 誤擋", "no 放行", "no 擋下率", "partial 被擋"], rows))

    if candidates:
        best = min(candidates)
        ties = [t for b, t in candidates if b == best[0]]
        out.append(f"\n符合「no 擋下率 ≥ 90%」且 yes 誤擋最少：誤擋 {best[0]} 題，門檻 {ties[0]:.2f}～{ties[-1]:.2f}。")
    else:
        out.append("\n沒有任何門檻能讓 no 擋下率 ≥ 90%。")

    yes_scores = [q["dense_top_score"] for q in yes]
    no_scores = [q["dense_top_score"] for q in no if not (q["exact_hit_count"] or 0)]
    lo, hi = min(yes_scores), max(no_scores)
    out.append(f"\n## 重疊區間\n\nyes 的 Top-1 最低 {lo:.4f}，no 的 Top-1 最高 {hi:.4f}（不含精確命中）。" + ("兩群**重疊**，落在重疊區間內的題目：" if lo <= hi else "兩群沒有重疊。"))
    if lo <= hi:
        rows = [[q["id"], q["answerable"], LABEL.get(q["type"], q["type"]), q["question"], f"{q['dense_top_score']:.4f}", q["exact_hit_count"]]
                for q in sorted(yes + no, key=lambda q: q["dense_top_score"]) if lo <= q["dense_top_score"] <= hi]
        out.append("\n" + table(["id", "answerable", "類別", "問題", "Top-1 Dense", "精確命中"], rows))

    out.append(f"\n## 舊門檻 {old} 在新資料上\n")
    rows = [[q["id"], q["answerable"], q["question"], f"{q['dense_top_score']:.4f}", q["exact_hit_count"], "通過" if gate(q, old) else "擋下"]
            for q in yes if not gate(q, old)] + \
           [[q["id"], q["answerable"], q["question"], f"{q['dense_top_score']:.4f}", q["exact_hit_count"], "通過" if gate(q, old) else "擋下"] for q in no + partial + ex]
    out.append(f"yes 被擋 {sum(1 for q in yes if not gate(q, old))} 題、no 被放行 {sum(1 for q in no if gate(q, old))} / {len(no)} 題。以下列出被擋的 yes 題，以及所有 no、partial、排除題：\n")
    out.append(table(["id", "answerable", "問題", "Top-1 Dense", "精確命中", f"{old}"], rows))
    return "\n".join(out) + "\n"


def main():
    if sys.argv[1] == "eval":
        runs = {a.split("=", 1)[0]: load(a.split("=", 1)[1]) for a in sys.argv[2:]}
        sys.stdout.write(eval_report(runs))
    else:
        args = [a for a in sys.argv[2:] if not a.startswith("--")]
        opts = dict(a[2:].split("=", 1) for a in sys.argv[2:] if a.startswith("--"))
        exclude = [x for x in opts.get("exclude", "").split(",") if x]
        sys.stdout.write(gate_report(load(args[0]), exclude, float(opts.get("old", 0.59))))


if __name__ == "__main__":
    main()
