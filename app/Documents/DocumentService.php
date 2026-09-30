<?php

namespace App\Documents;

use App\Documents\Exceptions\InvalidStatusTransitionException;
use App\Documents\Exceptions\StaleDocumentStatusException;
use App\Jobs\ParseDocumentJob;
use App\Models\Document;
use App\Repositories\DocumentRepository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;

/**
 * 文件的上傳、列表與重新處理。解析本身由 ParseDocumentJob → DocumentParsingService 在背景執行。
 */
class DocumentService
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly Filesystem $disk,
        private readonly string $directory,
    ) {}

    /** 存檔、建立 uploaded 狀態的文件並排入 Queue，立即回傳（不等解析完成） */
    public function upload(UploadedFile $file): Document
    {
        // 以隨機檔名存放，原始檔名只記在資料庫：避免檔名衝突，也避免使用者控制磁碟上的路徑
        $path = $this->disk->putFile($this->directory.'/'.now()->format('Y/m'), $file);

        $document = $this->documents->create([
            'name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'path' => $path,
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
        ]);

        ParseDocumentJob::dispatch($document->id);

        return $document;
    }

    /**
     * 重新處理：狀態改回 uploaded 後重新排入 Queue。只允許已完成或失敗的文件，
     * 處理中的文件不可重新處理（避免同一份文件同時有兩個 Job）。
     *
     * @throws InvalidStatusTransitionException 文件目前的狀態不可重新處理
     * @throws StaleDocumentStatusException 狀態剛好被其他程序改變
     */
    public function reprocess(Document $document): void
    {
        $this->documents->transition($document, DocumentStatus::Uploaded, ['error_message' => null]);

        ParseDocumentJob::dispatch($document->id);
    }

    /**
     * 文件列表，並附上「與哪些文件內容相同」（依 sha256）。
     *
     * @return array{0: LengthAwarePaginator, 1: array<int, list<int>>} [分頁結果, 文件 id → 內容相同的其他文件 id]
     */
    public function paginateWithDuplicates(int $perPage = 20): array
    {
        $page = $this->documents->paginate($perPage);
        $groups = $this->documents->idsBySha256($page->getCollection()->pluck('sha256')->unique()->values()->all());

        $duplicates = [];

        foreach ($page->getCollection() as $document) {
            $duplicates[$document->id] = array_values(array_diff($groups[$document->sha256] ?? [], [$document->id]));
        }

        return [$page, $duplicates];
    }
}
