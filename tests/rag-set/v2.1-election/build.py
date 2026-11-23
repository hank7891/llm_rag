#!/usr/bin/env python3
"""由凍結的 v2 產生 v2.1（Ch15 結案，2026-11-23）。v2 不修改；v2.1 只套用下列變更：

  1. Q58：answerable no → partial，期望 公職法 第 59-1 條、總統法 第 55-1 條（兩條同文：工作費數額由中選會擬訂、報行政院核定）
  2. 系列對話新增 S06「話題切換」（Q81～Q83）與對應的獨立輸入

並檢查 answer_segments.json 的原句：每句都要出現在交付包 laws/*.md 的對應條文中（交付包不在版控，找不到時略過並提示）。

用法：python3 tests/rag-set/v2.1-election/build.py
"""
import json
import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
V2 = HERE.parent / "v2-election"
LAWS = HERE.parents[2] / "docs" / "txt" / "ch15-real-docs" / "laws"

PRES = "02_總統副總統選舉罷免法.pdf"
LOCAL = "04_公職人員選舉罷免法.pdf"
LOCAL_RULES = "05_公職人員選舉罷免法施行細則.pdf"


def src(document, *articles):
    return [{"document": document, "section": f"第 {a} 條"} for a in articles]


PATCHES = {
    "Q58": {
        "answerable": "partial",
        "expected": src(LOCAL, "59-1") + src(PRES, "55-1"),
        "expected_answer": "可指出工作費數額基準由中選會擬訂、報行政院核定（公職法 §59-1、總統法 §55-1）；語料未提供今年實際日支金額。",
        "origin": "bank-80（v2.1 改為 partial）",
    },
}

# 投票規則：投票地、領票、投票時間（§17～§19），投開票所設置與程序（§57～§66）
VOTING_RULES = src(LOCAL, "17", "18", "19", "57", "58", "59", "59-1", "60", "61", "62", "63", "64", "65", "66")
S06_NOTE = "S06 話題切換：同領域換主題後改寫不應帶入前一題主題（2026-11-23 手動測試發現）；歷史回答以判讀要點代替"
S06 = [
    {
        "id": "Q81", "turn": 1, "answerable": "partial",
        "question": "縣市長投票通常都與里長綁在一起嗎？",
        "standalone": "縣市長選舉通常都與里長選舉同日舉行嗎？",
        "expected": src(LOCAL, "19", "21", "25") + src(LOCAL_RULES, "30"),
        "answer": "法規未規定縣市長選舉必須與里長選舉合併辦理；二種以上選舉同日舉行時，選舉人應一次進入投票所投票、名冊得合併編造、候選人以登記一種為限、投票所合併設置。",
        "expected_answer": "未規定必須合辦；同日舉行時的配套：§19 第 2 項一次進入投票所、§21 名冊得合併編造、§25 只能登記一種、細則 §30 投票所合併設置。",
    },
    {
        "id": "Q82", "turn": 2, "answerable": "yes",
        "question": "縣市長投票規則",
        "standalone": "縣市長投票規則",
        "expected": VOTING_RULES,
        "answer": "於戶籍地投票所投票，憑國民身分證領取選舉票，於規定投票時間內到場；投票所依選舉區廣狹分設，投票完畢後改為開票所當眾唱名開票。",
        "expected_answer": "問題本身已完整，改寫不應帶入前一題的「與里長合辦」；應引用公職法的投票規定。",
    },
    {
        "id": "Q83", "turn": 3, "answerable": "yes",
        "question": "投票規則",
        "standalone": "縣市長選舉的投票規則",
        "expected": VOTING_RULES,
        "answer": None,
        "expected_answer": "改寫應帶入「縣市長」；引用應為公職人員選舉罷免法，而非總統副總統選舉罷免法的同內容條文。",
    },
]


def read(name):
    return [json.loads(line) for line in (V2 / name).read_text(encoding="utf-8").splitlines() if line.strip()]


def write(name, rows):
    (HERE / name).write_text("".join(json.dumps(r, ensure_ascii=False) + "\n" for r in rows), encoding="utf-8")


def s06():
    conversations, standalone, history = [], [], []
    for t in S06:
        conversations.append({
            "id": t["id"], "type": f"S06-T{t['turn']}", "history": list(history), "question": t["question"],
            "expected": t["expected"], "expected_rewrite": t["standalone"] if history else None,
            "answerable": t["answerable"], "note": S06_NOTE,
        })
        standalone.append({
            "id": f"{t['id']}s", "type": "single" if t["answerable"] == "yes" else "no_answer",
            "question": t["standalone"], "expected": t["expected"], "answerable": t["answerable"],
            "expected_answer": t["expected_answer"], "origin": "v2.1 S06 獨立輸入",
        })
        if t["answer"]:
            history.append({"question": t["question"], "answer": t["answer"]})
    return conversations, standalone


def article_text(document, section):
    stem = document.removesuffix(".pdf")
    path = LAWS / f"{stem}.md"
    text = path.read_text(encoding="utf-8")
    m = re.search(rf"^### {re.escape(section)}\n(.*?)(?=^#{{2,3}} |\Z)", text, re.S | re.M)
    return None if m is None else re.sub(r"\s", "", m.group(1))


def check_segments(questions):
    segments = json.loads((HERE / "answer_segments.json").read_text(encoding="utf-8"))
    ids = {q["id"]: q for q in questions}
    errors = []
    for qid, items in segments.items():
        if qid.startswith("_"):
            continue
        q = ids.get(qid)
        if q is None:
            errors.append(f"{qid}: 不在 questions.jsonl")
            continue
        for s in items:
            if {"document": s["document"], "section": s["section"]} not in q["expected"]:
                errors.append(f"{qid}: {s['document']} {s['section']} 不是期望條文")
    if not LAWS.exists():
        print(f"略過原句檢查：找不到 {LAWS}", file=sys.stderr)
        return errors
    for qid, items in segments.items():
        for s in [] if qid.startswith("_") else items:
            body = article_text(s["document"], s["section"])
            if body is None or re.sub(r"\s", "", s["text"]) not in body:
                errors.append(f"{qid}: 原句不在 {s['document']} {s['section']}：{s['text']}")
    return errors


def main():
    questions = [dict(q, **PATCHES.get(q["id"], {})) for q in read("questions.jsonl")]
    conversations, standalone = s06()
    conversations = read("conversations.jsonl") + conversations
    standalone = read("standalone.jsonl") + standalone

    errors = check_segments(questions)
    if errors:
        print("\n".join(errors), file=sys.stderr)
        return 1

    write("questions.jsonl", questions)
    write("conversations.jsonl", conversations)
    write("standalone.jsonl", standalone)
    counts = {a: sum(1 for q in questions if q["answerable"] == a) for a in ("yes", "partial", "no")}
    print(f"questions.jsonl {len(questions)} 題 {counts}；conversations.jsonl {len(conversations)} 輪；standalone.jsonl {len(standalone)} 題")
    return 0


if __name__ == "__main__":
    sys.exit(main())
