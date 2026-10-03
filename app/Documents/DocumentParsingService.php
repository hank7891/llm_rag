<?php

namespace App\Documents;

use App\Documents\Exceptions\StaleDocumentStatusException;
use App\Documents\Parsing\Exceptions\DocumentParseException;
use App\Documents\Parsing\ParsedPage;
use App\Documents\Parsing\ParserResolver;
use App\Documents\Parsing\TextNormalizer;
use App\Jobs\ChunkDocumentJob;
use App\Repositories\DocumentPageRepository;
use App\Repositories\DocumentRepository;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * 解析一份文件：選 Parser → 逐頁抽文字 → 正規化 → 掃描檔偵測 → 寫入頁面 → 更新狀態。
 * 由 ParseDocumentJob 呼叫；可重複執行（冪等）。
 */
class DocumentParsingService
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentPageRepository $pages,
        private readonly ParserResolver $parsers,
        private readonly TextNormalizer $normalizer,
        private readonly Filesystem $disk,
        private readonly int $scannedMinCharsPerPage,
    ) {}

    /**
     * @throws DocumentParseException 檔案本身無法解析（永久性錯誤，不應重試）
     */
    public function parse(int $documentId): void
    {
        $document = $this->documents->find($documentId);

        if ($document === null) {
            return;
        }

        // uploaded：第一次執行，轉為 parsing；parsing：上一次因暫時性錯誤中斷，這是重試，直接繼續；
        // 其他狀態（已完成、已失敗）：可能是重複的 Job，直接結束
        if ($document->status_key === DocumentStatus::Uploaded) {
            try {
                $this->documents->transition($document, DocumentStatus::Parsing, ['error_message' => null]);
            } catch (StaleDocumentStatusException) {
                return; // 同一時間另一個 Job 已經開始處理
            }
        } elseif ($document->status_key !== DocumentStatus::Parsing) {
            return;
        }

        $parser = $this->parsers->resolve($document->mime_type, pathinfo($document->name, PATHINFO_EXTENSION));
        $pages = $this->normalizer->normalize(
            $parser->parse($this->disk->path($document->path)),
            joinWrappedLines: $parser->hasHardWrappedLines(),
            preserveLayout: $parser->preservesLayout(),
        );

        $this->assertHasTextLayer($pages);

        // 先寫頁面、再改狀態：若改狀態前失敗，重試時 replace() 會整批覆蓋，不會重複
        $this->pages->replace($document, array_combine(
            array_map(fn (ParsedPage $p) => $p->pageNumber, $pages),
            array_map(fn (ParsedPage $p) => $p->content, $pages),
        ));
        $this->documents->transition($document, DocumentStatus::Parsed, ['page_count' => count($pages)]);

        // 解析完成後接著切段（Ch05），在另一個 Job 執行：切段失敗不影響已保存的頁面，也可以單獨重跑
        ChunkDocumentJob::dispatch($document->id);
    }

    /** 標記為失敗並記錄原因（由 Job 在永久性錯誤或重試用盡時呼叫） */
    public function markFailed(int $documentId, string $reason): void
    {
        $document = $this->documents->find($documentId);

        if ($document === null || ! $document->status_key->canTransitionTo(DocumentStatus::Failed)) {
            return;
        }

        try {
            $this->documents->transition($document, DocumentStatus::Failed, ['error_message' => $reason]);
        } catch (StaleDocumentStatusException) {
            // 讀取後狀態已被其他 Job 改變（例如已解析完成）：不覆寫對方的結果
        }
    }

    /**
     * 掃描型 PDF 沒有文字層，抽出來幾乎是空的。以「平均每頁字數」判斷，
     * 允許個別空白頁（例如只有圖片的封面）。
     *
     * @param  list<ParsedPage>  $pages
     */
    private function assertHasTextLayer(array $pages): void
    {
        $chars = array_sum(array_map(fn (ParsedPage $p) => mb_strlen(preg_replace('/\s+/u', '', $p->content)), $pages));

        if ($chars / count($pages) < $this->scannedMinCharsPerPage) {
            throw new DocumentParseException(sprintf(
                '疑似掃描檔：平均每頁只抽出 %d 個字，文件可能沒有文字層（目前不支援 OCR）。',
                intdiv($chars, count($pages)),
            ));
        }
    }
}
