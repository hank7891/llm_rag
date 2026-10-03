<?php

namespace App\Console\Commands;

use App\Models\DocumentChunk;
use App\Repositories\DocumentChunkRepository;
use Illuminate\Console\Command;

/**
 * 列出一份文件的所有 Chunk 與長度統計，用於目視檢查切段結果。
 */
class ListChunksCommand extends Command
{
    protected $signature = 'rag:chunks {document : 文件 id} {--preview=30 : 內容預覽字數}';

    protected $description = '列出文件的 Chunk（條號、頁碼、長度、內容開頭）與長度統計';

    public function handle(DocumentChunkRepository $chunks): int
    {
        $list = $chunks->forDocument((int) $this->argument('document'));

        if ($list->isEmpty()) {
            $this->warn('這份文件還沒有 Chunk。');

            return self::FAILURE;
        }

        $preview = (int) $this->option('preview');
        $max = config('rag.chunking.max_tokens');

        $this->table(['#', 'section', '頁碼', '字數', 'Token', '內容開頭'], $list->map(fn (DocumentChunk $c) => [
            $c->chunk_index,
            $c->section ?? '—',
            $c->page_start === $c->page_end ? $c->page_start : "{$c->page_start}–{$c->page_end}",
            $c->char_count,
            $c->token_count,
            mb_substr(str_replace("\n", ' ', $c->content), 0, $preview),
        ])->all());

        $tokens = $list->pluck('token_count');
        $this->table(['策略', 'Chunk 數', '最短', '平均', '最長', "超過上限（{$max}）", '跨頁'], [[
            $list->first()->chunk_strategy,
            $list->count(),
            $tokens->min(),
            (int) round($tokens->avg()),
            $tokens->max(),
            $tokens->filter(fn (int $t) => $t > $max)->count(),
            $list->filter(fn (DocumentChunk $c) => $c->page_end > $c->page_start)->count(),
        ]]);

        return self::SUCCESS;
    }
}
