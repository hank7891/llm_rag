<?php

namespace Tests\Support;

use App\Models\DocumentChunk;
use App\Rag\Retrieval\RetrievedChunk;
use App\Repositories\DocumentRepository;

/**
 * 在測試資料庫建立文件與 Chunk，回傳對應的 RetrievedChunk：Citation 以 chunk_id 向 MySQL 查詢來源，
 * 檢索結果必須指向真的存在的 Chunk。
 */
trait SeedsRetrievedChunks
{
    /**
     * @param  list<array{string, string|null, float}>  $chunks  [內容, section, 分數]
     * @return list<RetrievedChunk>
     */
    private function seedChunks(array $chunks, string $documentName = '員工管理辦法.pdf'): array
    {
        $document = (new DocumentRepository)->create(['name' => $documentName, 'mime_type' => 'application/pdf', 'path' => 'x', 'size' => 1, 'sha256' => str_repeat('0', 64)]);

        return array_map(function (array $chunk, int $i) use ($document, $documentName) {
            [$content, $section, $score] = $chunk;
            $row = DocumentChunk::create(['document_id' => $document->id, 'chunk_index' => $i, 'content' => $content, 'char_count' => mb_strlen($content), 'token_count' => mb_strlen($content),
                'page_start' => 2, 'page_end' => 2, 'section' => $section, 'chunk_strategy' => 's', 'created_at' => now()]);

            return new RetrievedChunk($row->id, $document->id, $documentName, $section, 2, 2, $content, $score);
        }, $chunks, array_keys($chunks));
    }
}
