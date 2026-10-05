<?php

namespace App\Console\Commands;

use App\Ai\Embedding\EmbeddingService;
use App\Models\Document;
use App\Rag\DocumentIndexingService;
use Illuminate\Console\Command;

/**
 * 重建向量索引（同步執行，不經過 Queue）。
 * 指定 --model 且不是目前設定的模型時，只寫入該模型的 Collection，不修改 documents.status（Ch08 比較模型用）。
 */
class IndexDocumentsCommand extends Command
{
    protected $signature = 'rag:index
        {document? : 文件 id}
        {--all : 重建所有已切段的文件}
        {--model= : Embedding 模型，未填使用目前設定的模型}';

    protected $description = '重建文件的向量索引（先刪除該文件的舊 Points 再寫入）';

    public function handle(DocumentIndexingService $indexing, EmbeddingService $embedding): int
    {
        if ($this->argument('document') === null && ! $this->option('all')) {
            $this->error('請指定文件 id，或使用 --all。');

            return self::FAILURE;
        }

        $model = $this->option('model');
        $ids = $this->option('all')
            ? Document::query()->whereHas('chunks')->orderBy('id')->pluck('id')->all()
            : [(int) $this->argument('document')];

        $this->info(sprintf('模型：%s%s', $model ?? $embedding->defaultModel(), $indexing->isCurrentModel($model) ? '（目前設定，會更新文件狀態）' : '（非目前設定，不修改文件狀態）'));

        $failed = 0;

        foreach ($ids as $id) {
            $count = $indexing->index($id, $model);

            if ($count === null) {
                $failed++;
                $this->warn("#{$id}：略過（文件不存在，或狀態不能建索引；需為「切段完成」或「索引完成」）");
            } else {
                $this->line("#{$id}：寫入 {$count} 個 Points");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
