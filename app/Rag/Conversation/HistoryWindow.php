<?php

namespace App\Rag\Conversation;

use App\Rag\Citation\CitationParser;

/**
 * Sliding Window：只保留最近 N 輪，並控制總長度。資料庫讀出的歷史與評估用的腳本化歷史都經過這裡。
 *
 * 助理回答中的 [n] 與來源清單一律移除：編號只對該輪的參考資料有意義，帶進下一輪會讓模型沿用舊編號（Ch10 的引用驗證就會失準）。
 */
class HistoryWindow
{
    public function __construct(
        private readonly CitationParser $citations,
        private readonly int $windowTurns,
        private readonly int $budgetChars,
    ) {}

    /**
     * @param  list<Turn>  $turns  由舊到新
     * @return list<Turn> 由舊到新
     */
    public function apply(array $turns): array
    {
        $turns = array_map(fn (Turn $t) => new Turn($t->question, $this->stripCitations($t->answer)), array_slice($turns, -$this->windowTurns));

        // 歷史、參考資料、回答共用 num_ctx：超過預算時從最舊的一輪開始捨去
        while (count($turns) > 1 && $this->length($turns) > $this->budgetChars) {
            array_shift($turns);
        }

        // 只剩一輪仍然超過時，截短該輪的回答（問題保留原樣）
        if ($turns !== [] && $this->length($turns) > $this->budgetChars) {
            $last = $turns[0];
            $turns = [new Turn($last->question, mb_substr($last->answer, 0, max(0, $this->budgetChars - mb_strlen($last->question))))];
        }

        return $turns;
    }

    public function stripCitations(string $answer): string
    {
        // 來源清單（程式產生，不是對話內容）：「資料來源」標題與「[1] 檔名　條號　頁碼」形式的行
        $answer = preg_replace('/^\s*(資料來源[:：]?.*|\[\d+\](\[\d+\])*\s+\S+\.(pdf|txt|md)\b.*)$/mu', '', $answer);

        foreach (array_reverse($this->citations->parse($answer)) as $marker) {
            $answer = mb_substr($answer, 0, $marker->offset).mb_substr($answer, $marker->offset + mb_strlen($marker->raw));
        }

        return trim(preg_replace(['/[ \t\x{3000}]+(?=[。，、；：！？.,;:!?\n]|$)/mu', '/\n{3,}/'], ['', "\n\n"], $answer));
    }

    /** @param list<Turn> $turns */
    private function length(array $turns): int
    {
        return array_sum(array_map(fn (Turn $t) => mb_strlen($t->question) + mb_strlen($t->answer), $turns));
    }
}
