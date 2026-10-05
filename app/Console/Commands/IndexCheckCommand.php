<?php

namespace App\Console\Commands;

use App\Ai\Embedding\EmbeddingModels;
use App\Ai\Embedding\EmbeddingService;
use App\Models\Document;
use App\Rag\VectorStore\CollectionResolver;
use App\Rag\VectorStore\QdrantClient;
use Illuminate\Console\Command;

/**
 * 比對 MySQL（來源）與 Qdrant（衍生索引）：每份文件的 Chunk 數與 Point 數，以及 Qdrant 中來源已不存在的孤兒 Points。
 */
class IndexCheckCommand extends Command
{
    protected $signature = 'rag:index-check {--model= : Embedding 模型，未填使用目前設定的模型}';

    protected $description = '檢查 MySQL 的 document_chunks 與 Qdrant 的 Points 是否一致';

    public function handle(QdrantClient $qdrant, CollectionResolver $resolver, EmbeddingService $embedding): int
    {
        $model = EmbeddingModels::canonical($this->option('model') ?? $embedding->defaultModel());
        $collection = $resolver->name($model);

        if ($qdrant->collection($collection) === null) {
            $this->warn("Collection [{$collection}] 不存在。");

            return self::FAILURE;
        }

        // facet：一次取得每個 document_id 的 Point 數（需要 document_id 的 Payload Index）
        $points = $qdrant->facet($collection, 'document_id');
        $documents = Document::query()->withCount('chunks')->orderBy('id')->get();
        $rows = [];
        $problems = 0;

        foreach ($documents as $document) {
            $count = $points[$document->id] ?? 0;
            $result = match (true) {
                $count === $document->chunks_count => '一致',
                $count === 0 => '未建索引',
                default => '不一致',
            };
            $problems += $result === '不一致' ? 1 : 0;
            $rows[] = ["#{$document->id}", mb_strimwidth($document->name, 0, 30, '…'), $document->status_key->label(), $document->chunks_count, $count, $result];
        }

        $this->info("Collection：{$collection}（points_count {$qdrant->count($collection)}）");
        $this->table(['文件', '檔名', '狀態', 'Chunks', 'Points', '結果'], $rows);

        $orphans = array_diff_key($points, $documents->keyBy('id')->all());

        if ($orphans === []) {
            $this->info('孤兒 Points：無');
        } else {
            $problems += count($orphans);
            $this->error('孤兒 Points（document_id 已不存在於 MySQL）：');
            $this->table(['document_id', 'Points'], array_map(fn ($id, $count) => [$id, $count], array_keys($orphans), $orphans));
        }

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }
}
