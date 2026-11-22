#!/usr/bin/env python3
"""把 Ch15 的兩份題目來源轉成 rag:eval／rag:eval-conversation 的格式（只轉格式，不改題目內容）。

來源（source/）：
  draft-v1.jsonl   交付包的 71 題草稿（id A01～I02）
  bank-80.md       使用者設計的 80 題題庫（Q01～Q60 單題、Q61～Q80 為 S01～S05 系列對話）
  expected_articles.json、manifest.csv   2026-11-23 版法規的條號清單與檔名

輸出：
  questions.jsonl       單題 131 題（draft 71 + bank 60），rag:eval 用
  conversations.jsonl   系列對話 20 輪（歷史 = 前幾輪的問題 + 判讀要點），rag:eval-conversation 用
  standalone.jsonl      系列對話的「獨立輸入」20 題，rag:eval 用，只和多輪結果對照，不併入單題基準

對應規則：
  法規名稱 → 文件名稱：取最長相符（「公職人員選舉罷免法」是「…施行細則」的子字串）
  條號 63-1 → section 最後一段「第 63-1 條」（官方 PDF 的條號寫法，structure_v3 原樣保存）

用法：python3 tests/rag-set/v2-election/build.py（有任何條號對不上時列出並以非 0 結束）
"""
import csv, json, re, sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
SRC = HERE / "source"

EXPECTED = json.loads((SRC / "expected_articles.json").read_text(encoding="utf-8"))
MANIFEST = list(csv.DictReader((SRC / "manifest.csv").read_text(encoding="utf-8-sig").splitlines()))
DOCUMENTS = {row["law"]: row["file"].removesuffix(".md") + ".pdf" for row in MANIFEST}

problems: list[str] = []


def law_of(name: str) -> str:
    hits = [law for law in DOCUMENTS if law in name]
    if not hits:
        raise ValueError(f"對不上任何法規：{name}")
    return max(hits, key=len)


def source(qid: str, law_name: str, article: str) -> dict:
    law = law_of(law_name)
    info = EXPECTED[law]
    if article not in info["articles"]:
        problems.append(f"{qid}：{law} 沒有第 {article} 條")
    elif article in info["deleted"]:
        problems.append(f"{qid}：{law} 第 {article} 條已刪除")
    return {"document": DOCUMENTS[law], "section": f"第 {article} 條"}


def from_draft() -> list[dict]:
    rows = []
    for line in (SRC / "draft-v1.jsonl").read_text(encoding="utf-8").splitlines():
        if not line.strip():
            continue
        q = json.loads(line)
        rows.append({
            "id": q["id"],
            "type": q["category"],
            "question": q["question"],
            "expected": [source(q["id"], e["doc"], e["article"]) for e in q["expected"]],
            "answerable": q["answerable"],
            "expected_answer": "；".join(q["key_points"]) + (f"（{q['note']}）" if q["note"] else ""),
            "origin": "draft-v1",
        })
    return rows


def parse_refs(qid: str, cell: str) -> list[dict]:
    cell = cell.strip()
    if cell in ("—", "-", ""):
        return []
    refs = []
    for part in cell.split("、"):
        m = re.fullmatch(r"\s*(.+?)\s*§\s*([\d]+(?:-\d+)?)\s*", part)
        if not m:
            problems.append(f"{qid}：看不懂的期望引用「{part}」")
            continue
        refs.append(source(qid, m.group(1), m.group(2)))
    return refs


def cells(line: str) -> list[str]:
    return [c.strip() for c in line.strip().strip("|").split("|")]


def from_bank() -> tuple[list[dict], list[dict], list[dict]]:
    singles, turns = [], []
    category, series = None, None
    for line in (SRC / "bank-80.md").read_text(encoding="utf-8").splitlines():
        if m := re.match(r"^## .+ · (\w+)$", line):
            category, series = m.group(1), None
        elif m := re.match(r"^### (S\d+) (.+)$", line):
            category, series = None, (m.group(1), m.group(2))
        elif category and re.match(r"^\| Q\d+ ", line):
            qid, question, answerable, refs, point = cells(line)
            singles.append({
                "id": qid, "type": category, "question": question,
                "expected": parse_refs(qid, refs), "answerable": answerable,
                "expected_answer": point, "origin": "bank-80",
            })
        elif series and re.match(r"^\| Q\d+／T\d ", line):
            head, chained, standalone, answerable, refs, point = cells(line)
            qid, turn = head.split("／")
            turns.append({
                "id": qid, "series": series[0], "series_title": series[1], "turn": int(turn[1:]),
                "chained": chained, "standalone": chained if standalone == "同連續輸入" else standalone,
                "answerable": answerable, "expected": parse_refs(qid, refs), "point": point,
            })

    conversations, standalone = [], []
    for t in turns:
        history = [{"question": p["chained"], "answer": p["point"]} for p in turns if p["series"] == t["series"] and p["turn"] < t["turn"]]
        conversations.append({
            "id": t["id"], "type": f"{t['series']}-T{t['turn']}", "history": history, "question": t["chained"],
            "expected": t["expected"], "expected_rewrite": t["standalone"] if history else None,
            "answerable": t["answerable"],
            "note": f"{t['series']} {t['series_title']}；歷史回答以前幾輪的判讀要點代替（腳本化，評估才可重現）",
        })
        standalone.append({
            "id": f"{t['id']}s", "type": "single" if not t["expected"] or t["answerable"] == "yes" else "no_answer",
            "question": t["standalone"], "expected": t["expected"], "answerable": t["answerable"],
            "expected_answer": t["point"], "origin": f"bank-80 {t['series']} 獨立輸入",
        })
    return singles, conversations, standalone


def write(name: str, rows: list[dict]) -> None:
    (HERE / name).write_text("".join(json.dumps(r, ensure_ascii=False) + "\n" for r in rows), encoding="utf-8")


def main() -> int:
    draft = from_draft()
    singles, conversations, standalone = from_bank()
    questions = draft + singles

    ids = [q["id"] for q in questions]
    if len(ids) != len(set(ids)):
        problems.append("單題 id 重複")
    for q in questions + standalone:
        if (q["answerable"] == "no") != (q["expected"] == []):
            problems.append(f"{q['id']}：answerable={q['answerable']} 與 expected 不一致")

    write("questions.jsonl", questions)
    write("conversations.jsonl", conversations)
    write("standalone.jsonl", standalone)
    print(f"questions.jsonl {len(questions)} 題（draft {len(draft)}、bank {len(singles)}）；conversations.jsonl {len(conversations)} 輪；standalone.jsonl {len(standalone)} 題")
    for p in problems:
        print("✗", p)
    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
