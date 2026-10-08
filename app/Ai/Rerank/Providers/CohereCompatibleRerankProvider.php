<?php

namespace App\Ai\Rerank\Providers;

use App\Ai\Exceptions\LlmResponseFormatException;
use App\Ai\Rerank\Contracts\RerankProviderInterface;
use App\Ai\Rerank\DTO\RerankOptions;
use App\Ai\Rerank\DTO\RerankResult;
use App\Ai\Rerank\DTO\RerankScore;
use App\Ai\Support\LlmHttp;
use Illuminate\Support\Facades\Http;

/**
 * Cohere 相容的 Rerank API（POST {base_url}/v1/rerank）：llama.cpp llama-server、TEI、Jina、Cohere 共用同一種格式。
 *
 * 實測（llama-server 0.6.0 + bge-reranker-v2-m3）：results 依分數由高到低排序、不是送出順序，
 * 必須以 results[].index 對回 documents；relevance_score 為 logit（相關 +1.1、無關 -10.9）。
 */
class CohereCompatibleRerankProvider implements RerankProviderInterface
{
    /** @param array{base_url: string, model: string, timeout: int|float, name?: string} $config */
    public function __construct(private readonly array $config) {}

    public function rerank(string $query, array $documents, RerankOptions $options): RerankResult
    {
        $provider = $this->config['name'] ?? 'rerank';
        $startedAt = hrtime(true);

        $json = LlmHttp::postJson(
            $provider,
            Http::baseUrl($this->config['base_url'])->acceptJson()->timeout($this->config['timeout']),
            '/v1/rerank',
            ['model' => $options->model ?? $this->config['model'], 'query' => $query, 'documents' => $documents]
                + ($options->topN === null ? [] : ['top_n' => $options->topN]),
        );

        $scores = array_map(fn (mixed $row) => $this->score($provider, $row, count($documents)), $json['results'] ?? throw new LlmResponseFormatException("[{$provider}] Response has no results."));

        if (count(array_unique(array_map(fn (RerankScore $s) => $s->index, $scores))) !== count($scores)) {
            throw new LlmResponseFormatException("[{$provider}] Response contains duplicate indexes.");
        }

        usort($scores, fn (RerankScore $a, RerankScore $b) => $b->score <=> $a->score);

        return new RerankResult($scores, (string) ($json['model'] ?? $options->model ?? $this->config['model']), intdiv(hrtime(true) - $startedAt, 1_000_000));
    }

    /** index 必須落在送出的 documents 範圍內，否則來源引用會整個錯亂而不報錯 */
    private function score(string $provider, mixed $row, int $count): RerankScore
    {
        $index = is_array($row) ? ($row['index'] ?? null) : null;
        $score = is_array($row) ? ($row['relevance_score'] ?? null) : null;

        if (! is_int($index) || $index < 0 || $index >= $count || ! is_numeric($score)) {
            throw new LlmResponseFormatException("[{$provider}] Invalid rerank result: ".json_encode($row));
        }

        return new RerankScore($index, (float) $score);
    }
}
