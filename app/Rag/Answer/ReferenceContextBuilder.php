<?php

namespace App\Rag\Answer;

use App\Rag\Retrieval\RetrievedChunk;

/**
 * 把檢索結果組成帶編號的參考資料：[1] 內容、[2] 內容……依 Retriever 的排序編號。
 *
 * 只放編號與內容，不放檔名與頁碼：來源由程式依編號對照表產生（Ch10），模型沒看到檔名與頁碼，就無從改寫或編造。
 */
class ReferenceContextBuilder
{
    /** @param list<RetrievedChunk> $chunks 依分數由高到低 */
    public function build(array $chunks, int $budgetChars): ReferenceContext
    {
        $blocks = [];
        $references = [];
        $used = 0;

        foreach ($chunks as $i => $chunk) {
            $block = '['.($i + 1).'] '.$this->neutralizeTags($chunk->content);

            // 超過預算時從排名最後整段捨去，不從 Chunk 中間截斷（截斷的條文可能意思相反）。
            // 第 1 名一定保留：否則「有候選」會因為設定值變成沒有參考資料
            if ($blocks !== [] && $used + mb_strlen($block) > $budgetChars) {
                break;
            }

            $blocks[] = $block;
            $used += mb_strlen($block);
            $references[] = new Reference($i + 1, $chunk->chunkId, $chunk->documentId, $chunk->documentName, $chunk->section, $chunk->pageStart, $chunk->pageEnd, $chunk->score);
        }

        return new ReferenceContext(implode("\n", $blocks), $references, count($chunks) - count($blocks));
    }

    /**
     * 內容若剛好含有 </reference>，會提前結束標籤，讓後面的文字被模型當成標籤外的指令（同 Ch04 的 <document>）。
     */
    private function neutralizeTags(string $content): string
    {
        return preg_replace('/<(\/?)reference>/i', '＜$1reference＞', $content);
    }
}
