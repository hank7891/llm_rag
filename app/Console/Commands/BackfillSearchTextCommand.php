<?php

namespace App\Console\Commands;

use App\Models\DocumentChunk;
use App\Rag\Search\SearchTextNormalizer;
use Illuminate\Console\Command;

/**
 * 以目前的 SearchTextNormalizer 重新產生所有 Chunk 的 search_text。
 * 新增 search_text 欄位後、或修改正規化規則後執行；新切出的 Chunk 會在寫入時自動產生。
 */
class BackfillSearchTextCommand extends Command
{
    protected $signature = 'rag:backfill-search-text';

    protected $description = '重新產生所有 Chunk 的 search_text（關鍵字搜尋用）';

    public function handle(SearchTextNormalizer $normalizer): int
    {
        $updated = 0;

        DocumentChunk::query()->select(['id', 'content'])->chunkById(200, function ($chunks) use ($normalizer, &$updated) {
            foreach ($chunks as $chunk) {
                $updated += DocumentChunk::whereKey($chunk->id)->update(['search_text' => $normalizer->normalize($chunk->content)]);
            }
        });

        $this->info("已更新 {$updated} 段。");

        return self::SUCCESS;
    }
}
