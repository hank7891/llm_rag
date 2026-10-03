<?php

namespace App\Console\Commands;

use App\Documents\Chunking\ChunkingService;
use App\Documents\Chunking\DocumentChunkingService;
use Illuminate\Console\Command;

/**
 * 實驗用：以指定參數重新切段（同步執行，不經過 Queue）。不帶參數時使用 config/rag.php 的設定。
 */
class ChunkDocumentCommand extends Command
{
    protected $signature = 'rag:chunk
        {document : 文件 id}
        {--strategy= : structure（結構優先）或 fixed（固定大小）}
        {--max-tokens= : 單一 Chunk 的估算 Token 上限}
        {--overlap= : 重疊比例，例如 0.15}';

    protected $description = '重新切段一份文件（先刪除舊的 Chunk），用於比較不同的切段參數';

    public function handle(DocumentChunkingService $documents, ChunkingService $chunking): int
    {
        $options = $chunking->defaults()->with(
            strategy: $this->option('strategy'),
            maxTokens: $this->option('max-tokens') === null ? null : (int) $this->option('max-tokens'),
            overlapRatio: $this->option('overlap') === null ? null : (float) $this->option('overlap'),
        );

        $drafts = $documents->chunk((int) $this->argument('document'), $options);

        if ($drafts === null) {
            $this->error('文件不存在，或目前的狀態不能切段（需為「解析完成」或「切段完成」）。');

            return self::FAILURE;
        }

        $this->info(sprintf('已切成 %d 段（%s）。用 php artisan rag:chunks %s 檢視。', count($drafts), $options->label(), $this->argument('document')));

        return self::SUCCESS;
    }
}
