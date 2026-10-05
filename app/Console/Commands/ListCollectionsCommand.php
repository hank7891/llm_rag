<?php

namespace App\Console\Commands;

use App\Rag\VectorStore\CollectionResolver;
use App\Rag\VectorStore\QdrantClient;
use Illuminate\Console\Command;

class ListCollectionsCommand extends Command
{
    protected $signature = 'rag:collections';

    protected $description = '列出本專案的 Qdrant Collection（維度、distance、Points 數、已建 HNSW 索引的向量數）';

    public function handle(QdrantClient $qdrant, CollectionResolver $resolver): int
    {
        $rows = [];

        foreach (array_filter($qdrant->collections(), fn (string $name) => $resolver->owns($name)) as $name) {
            $info = $qdrant->collection($name) ?? [];
            $vectors = $info['config']['params']['vectors'] ?? [];
            $rows[] = [$name, $vectors['size'] ?? '?', $vectors['distance'] ?? '?', $info['points_count'] ?? 0, $info['indexed_vectors_count'] ?? 0, $info['status'] ?? '?'];
        }

        if ($rows === []) {
            $this->warn('目前沒有本專案的 Collection。');

            return self::SUCCESS;
        }

        sort($rows);
        $this->table(['Collection', '維度', 'distance', 'points_count', 'indexed_vectors_count', 'status'], $rows);

        return self::SUCCESS;
    }
}
