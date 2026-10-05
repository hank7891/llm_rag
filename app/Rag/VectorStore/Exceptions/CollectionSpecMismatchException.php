<?php

namespace App\Rag\VectorStore\Exceptions;

use LogicException;

/**
 * 既有 Collection 的維度或距離與 Embedding 模型規格不符。不自動刪除重建：
 * Collection 裡可能有已建好的索引，是否重建應由人決定（rag:index --all 前先手動刪除）。
 */
class CollectionSpecMismatchException extends LogicException
{
    public static function of(string $collection, string $model, int $expectedSize, int $actualSize, string $actualDistance): self
    {
        return new self(sprintf(
            'Collection [%s] does not match model [%s]: expected size %d / Cosine, found size %d / %s. Delete the collection manually and re-index if this is intended.',
            $collection, $model, $expectedSize, $actualSize, $actualDistance,
        ));
    }
}
