<?php

namespace App\Ai\Rerank\Providers;

use App\Ai\Rerank\Contracts\RerankProviderInterface;
use App\Ai\Rerank\DTO\RerankOptions;
use App\Ai\Rerank\DTO\RerankResult;
use App\Ai\Rerank\DTO\RerankScore;

/**
 * 不呼叫任何模型的 Provider：分數 = 段落中出現的問題字元數（相同的輸入得到相同的分數），並記錄收到的參數供測試檢查。
 * 放在 app/ 而非 tests/：它是 phpunit.xml 的預設 provider（同 FakeChatProvider）。
 */
class FakeRerankProvider implements RerankProviderInterface
{
    /** @var list<array{query: string, documents: list<string>, options: RerankOptions}> */
    public array $calls = [];

    public function rerank(string $query, array $documents, RerankOptions $options): RerankResult
    {
        $this->calls[] = ['query' => $query, 'documents' => $documents, 'options' => $options];
        $chars = array_unique(mb_str_split($query));

        $scores = array_map(
            fn (string $document, int $index) => new RerankScore($index, (float) count(array_filter($chars, fn (string $c) => str_contains($document, $c)))),
            $documents,
            array_keys($documents),
        );
        usort($scores, fn (RerankScore $a, RerankScore $b) => [$b->score, $a->index] <=> [$a->score, $b->index]);

        return new RerankResult($options->topN === null ? $scores : array_slice($scores, 0, $options->topN), $options->model ?? 'fake', 0);
    }
}
