<?php

namespace App\Rag\Citation;

use App\Rag\Answer\Reference;
use App\Repositories\DocumentChunkRepository;
use Psr\Log\LoggerInterface;

/**
 * 把回答中的 [n] 以固定規則對應回來源：驗證編號 → 以 chunk_id 向 MySQL 查詢 → 移除不合規標記。
 * 不呼叫 LLM。編號沿用 ContextBuilder 的排名編號，不重新編號：之後改為串流時文字已經顯示在畫面上，事後重編會對不上。
 */
class CitationResolver
{
    public function __construct(
        private readonly CitationParser $parser,
        private readonly DocumentChunkRepository $chunks,
        private readonly LoggerInterface $logger,
        private readonly bool $stripInvalid,
    ) {}

    /**
     * @param  list<Reference>  $references  本次送給 LLM 的編號對照表
     * @param  bool  $insufficient  資料不足的回答：不顯示任何來源，標記一律移除
     */
    public function resolve(string $answer, array $references, bool $insufficient): CitationResult
    {
        $markers = $this->parser->parse($answer);
        $byNumber = collect($references)->keyBy('number');

        // 來源以 MySQL 為準（Qdrant Payload 只是衍生的索引），一次查完本次被引用的 Chunk
        $requested = $insufficient ? [] : collect($markers)->pluck('numbers')->flatten()->filter(fn ($n) => $byNumber->has($n))->unique()->values();
        $sources = $this->chunks->findWithDocuments(collect($requested)->map(fn (int $n) => $byNumber[$n]->chunkId)->all());

        $citations = [];
        $invalid = [];
        $replacements = [];

        foreach ($markers as $marker) {
            $valid = [];

            if ($marker->numbers === null) {
                $invalid[] = new InvalidRef($marker->raw, InvalidReason::NonNumeric);
            }

            foreach ($marker->numbers ?? [] as $number) {
                $reference = $byNumber->get($number);
                $source = $reference === null ? null : $sources->get($reference->chunkId);

                $reason = match (true) {
                    $insufficient => InvalidReason::InsufficientAnswer,
                    $reference === null => InvalidReason::OutOfRange,
                    $source === null => InvalidReason::SourceMissing,
                    default => null,
                };

                if ($reason === InvalidReason::SourceMissing) {
                    // MySQL 查不到：文件已刪除但 Qdrant 仍有 Point，或索引不同步（Ch07 雙寫問題的偵測點）
                    $this->logger->warning('Citation 來源不存在', ['ref' => $number, 'chunk_id' => $reference->chunkId]);
                }

                if ($reason !== null) {
                    $invalid[] = new InvalidRef($marker->raw, $reason, $number);

                    continue;
                }

                $valid[] = $number;
                $citations[$number] ??= new Citation($number, $source->id, $source->document_id, $source->document->name, $source->section, $source->page_start, $source->page_end, $reference->score);
            }

            // [1、3]、［１］ 這類寫法統一改寫成 [1][3]；不合規的編號從標記中移除
            $replacements[] = [$marker, implode('', array_map(fn (int $n) => "[{$n}]", $valid))];
        }

        ksort($citations);

        return new CitationResult(
            $this->stripInvalid ? $this->rewrite($answer, $replacements) : $answer,
            array_values($citations),
            $invalid,
            ! $insufficient && $citations === [],
        );
    }

    /** @param list<array{CitationMarker, string}> $replacements */
    private function rewrite(string $answer, array $replacements): string
    {
        $removed = false;

        foreach (array_reverse($replacements) as [$marker, $replacement]) {
            $removed = $removed || $replacement === '';
            $answer = mb_substr($answer, 0, $marker->offset).$replacement.mb_substr($answer, $marker->offset + mb_strlen($marker->raw));
        }

        // 移除標記後留下的空白：「休假 [資料不足]。」→「休假。」
        return $removed ? trim(preg_replace('/[ \t\x{3000}]+(?=[。，、；：！？.,;:!?\n]|$)/u', '', $answer)) : $answer;
    }
}
