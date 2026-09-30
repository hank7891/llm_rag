<?php

namespace App\Repositories;

use App\Documents\DocumentStatus;
use App\Documents\Exceptions\InvalidStatusTransitionException;
use App\Documents\Exceptions\StaleDocumentStatusException;
use App\Models\Document;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class DocumentRepository
{
    /** @param array{name: string, mime_type: string, path: string, size: int, sha256: string} $attributes */
    public function create(array $attributes): Document
    {
        return Document::create([...$attributes, 'status_key' => DocumentStatus::Uploaded]);
    }

    public function find(int $id): ?Document
    {
        return Document::find($id);
    }

    public function findOrFail(int $id): Document
    {
        return Document::findOrFail($id);
    }

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Document::query()->latest('id')->paginate($perPage);
    }

    /**
     * 相同內容（sha256）的其他文件 id，依 id 排序。
     *
     * @param  list<string>  $hashes
     * @return Collection<string, list<int>> sha256 → 文件 id 清單
     */
    public function idsBySha256(array $hashes): Collection
    {
        return Document::query()
            ->whereIn('sha256', $hashes)
            ->orderBy('id')
            ->get(['id', 'sha256'])
            ->groupBy('sha256')
            ->map(fn (Collection $documents) => $documents->pluck('id')->all());
    }

    /**
     * 依狀態機轉換狀態。非法轉換丟出例外，避免例如「已解析」被誤改回「解析中」。
     *
     * 以條件式 UPDATE（WHERE status_key = 讀到的狀態）寫入，而不是先讀再 save()：
     * 兩個 Job 同時處理同一份文件時，只有一個能轉換成功，另一個會收到 StaleDocumentStatusException，
     * 不會覆寫掉對方的結果（Compare-and-Set / 樂觀鎖）。
     *
     * @param  array<string, mixed>  $attributes  一併更新的欄位（如 page_count、error_message）
     *
     * @throws InvalidStatusTransitionException 狀態機不允許這個轉換
     * @throws StaleDocumentStatusException 狀態已被其他程序改變
     */
    public function transition(Document $document, DocumentStatus $to, array $attributes = []): void
    {
        $from = $document->status_key;

        if (! $from->canTransitionTo($to)) {
            throw InvalidStatusTransitionException::between($document->id, $from, $to);
        }

        $values = [...$attributes, 'status_key' => $to, 'updated_at' => now()];
        $updated = Document::whereKey($document->id)
            ->where('status_key', $from->value)
            ->update([...$values, 'status_key' => $to->value]);

        if ($updated === 0) {
            throw StaleDocumentStatusException::for($document->id, $from);
        }

        $document->forceFill($values)->syncOriginal();
    }
}
