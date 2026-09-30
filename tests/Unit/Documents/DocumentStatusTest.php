<?php

namespace Tests\Unit\Documents;

use App\Documents\DocumentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocumentStatusTest extends TestCase
{
    /** @return array<string, array{DocumentStatus, DocumentStatus}> */
    public static function allowedTransitions(): array
    {
        return [
            'uploaded → parsing' => [DocumentStatus::Uploaded, DocumentStatus::Parsing],
            'parsing → parsed' => [DocumentStatus::Parsing, DocumentStatus::Parsed],
            'parsing → failed' => [DocumentStatus::Parsing, DocumentStatus::Failed],
            'parsed → indexing' => [DocumentStatus::Parsed, DocumentStatus::Indexing],
            'indexing → indexed' => [DocumentStatus::Indexing, DocumentStatus::Indexed],
            'failed → uploaded（重新處理）' => [DocumentStatus::Failed, DocumentStatus::Uploaded],
            'parsed → uploaded（重新處理）' => [DocumentStatus::Parsed, DocumentStatus::Uploaded],
        ];
    }

    #[DataProvider('allowedTransitions')]
    public function test_allowed_transition(DocumentStatus $from, DocumentStatus $to): void
    {
        $this->assertTrue($from->canTransitionTo($to));
    }

    /** @return array<string, array{DocumentStatus, DocumentStatus}> */
    public static function forbiddenTransitions(): array
    {
        return [
            'uploaded → parsed（跳過解析）' => [DocumentStatus::Uploaded, DocumentStatus::Parsed],
            'parsed → parsing（未經重新處理就重跑）' => [DocumentStatus::Parsed, DocumentStatus::Parsing],
            'failed → parsed' => [DocumentStatus::Failed, DocumentStatus::Parsed],
            'indexed → indexing' => [DocumentStatus::Indexed, DocumentStatus::Indexing],
            'uploaded → uploaded' => [DocumentStatus::Uploaded, DocumentStatus::Uploaded],
            // Job 重試時停在 parsing 繼續處理，不經過狀態轉換（MySQL 對值未改變的 UPDATE 回報 0 筆，會被誤判為被搶先）
            'parsing → parsing' => [DocumentStatus::Parsing, DocumentStatus::Parsing],
        ];
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_forbidden_transition(DocumentStatus $from, DocumentStatus $to): void
    {
        $this->assertFalse($from->canTransitionTo($to));
    }

    public function test_only_finished_states_can_be_reprocessed(): void
    {
        $this->assertSame(
            [DocumentStatus::Parsed, DocumentStatus::Indexed, DocumentStatus::Failed],
            array_values(array_filter(DocumentStatus::cases(), fn (DocumentStatus $s) => $s->canReprocess())),
        );
    }

    public function test_every_status_has_a_label(): void
    {
        $this->assertCount(6, array_unique(array_map(fn (DocumentStatus $s) => $s->label(), DocumentStatus::cases())));
    }
}
