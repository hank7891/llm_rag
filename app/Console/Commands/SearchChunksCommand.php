<?php

namespace App\Console\Commands;

use App\Rag\VectorStore\SearchHit;
use App\Rag\VectorStore\VectorSearcher;
use Illuminate\Console\Command;

/**
 * 向量搜尋（Ch07 只用來確認寫入正確，不設 score_threshold）。
 */
class SearchChunksCommand extends Command
{
    protected $signature = 'rag:search {query : 問題} {--model= : Embedding 模型，未填使用目前設定的模型} {--limit=5 : 回傳筆數}';

    protected $description = '以問題搜尋 Chunk，列出分數、檔名、條號、頁碼與內容開頭';

    public function handle(VectorSearcher $searcher): int
    {
        $hits = $searcher->search($this->argument('query'), (int) $this->option('limit'), $this->option('model'));

        if ($hits === []) {
            $this->warn('沒有任何結果（Collection 是空的，或沒有這個模型的 Points）。');

            return self::SUCCESS;
        }

        $this->table(['分數', '檔名', 'section', '頁碼', '內容開頭'], array_map(fn (SearchHit $hit) => [
            sprintf('%.4f', $hit->score),
            $hit->payload['document_name'] ?? '?',
            $hit->payload['section'] ?? '—',
            ($hit->payload['page_start'] ?? '?') === ($hit->payload['page_end'] ?? '?') ? $hit->payload['page_start'] : "{$hit->payload['page_start']}–{$hit->payload['page_end']}",
            mb_strimwidth(str_replace("\n", ' ', $hit->payload['content'] ?? ''), 0, 40, '…'),
        ], $hits));

        return self::SUCCESS;
    }
}
