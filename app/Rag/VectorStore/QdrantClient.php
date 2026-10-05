<?php

namespace App\Rag\VectorStore;

use App\Rag\VectorStore\Exceptions\QdrantException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Qdrant REST API 的薄封裝（不引入 SDK），只提供本專案用到的操作。
 * 寫入與刪除一律帶 wait=true：Qdrant 預設非同步寫入，回應時資料可能還沒落地。
 */
class QdrantClient
{
    public function __construct(
        private readonly string $url,
        private readonly int $timeout,
    ) {}

    /**
     * Collection 資訊；不存在時回傳 null。
     *
     * @return array<string, mixed>|null
     */
    public function collection(string $name): ?array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get("/collections/{$name}"), allowNotFound: true);

        return $response === null ? null : $response->json('result');
    }

    /** @return list<string> */
    public function collections(): array
    {
        return array_column($this->send(fn ($http) => $http->get('/collections'))->json('result.collections') ?? [], 'name');
    }

    public function createCollection(string $name, int $size, string $distance = 'Cosine'): void
    {
        $this->send(fn ($http) => $http->put("/collections/{$name}", ['vectors' => ['size' => $size, 'distance' => $distance]]));
    }

    /** 刪除整個 Collection（只用於整合測試清理；正式流程不自動刪除 Collection） */
    public function deleteCollection(string $name): void
    {
        $this->send(fn ($http) => $http->delete("/collections/{$name}"), allowNotFound: true);
    }

    /** @param  'integer'|'keyword'  $schema */
    public function createPayloadIndex(string $collection, string $field, string $schema): void
    {
        $this->send(fn ($http) => $http->put("/collections/{$collection}/index?wait=true", ['field_name' => $field, 'field_schema' => $schema]));
    }

    /** @param list<array{id: int|string, vector: list<float>, payload: array<string, mixed>}> $points */
    public function upsert(string $collection, array $points): void
    {
        $this->send(fn ($http) => $http->put("/collections/{$collection}/points?wait=true", ['points' => $points]));
    }

    /** @param array<string, mixed> $filter */
    public function deleteByFilter(string $collection, array $filter): void
    {
        $this->send(fn ($http) => $http->post("/collections/{$collection}/points/delete?wait=true", ['filter' => $filter]));
    }

    /** @param array<string, mixed> $filter */
    public function count(string $collection, array $filter = []): int
    {
        $body = ['exact' => true] + ($filter === [] ? [] : ['filter' => $filter]);

        return (int) $this->send(fn ($http) => $http->post("/collections/{$collection}/points/count", $body))->json('result.count');
    }

    /**
     * 某個 payload 欄位每個值的 Point 數（需要該欄位有 Payload Index）。用來找出孤兒 Points。
     *
     * @return array<int|string, int> 值 → Point 數
     */
    public function facet(string $collection, string $key, int $limit = 10000): array
    {
        $hits = $this->send(fn ($http) => $http->post("/collections/{$collection}/facet", ['key' => $key, 'limit' => $limit, 'exact' => true]))->json('result.hits') ?? [];

        // 實測（Qdrant 1.19）：Points 刪除後，facet 仍會回傳該值、count 為 0，要過濾掉，否則會被誤判成孤兒
        return array_filter(array_column($hits, 'count', 'value'), fn (int $count) => $count > 0);
    }

    /**
     * 向量搜尋。
     *
     * @param  list<float>  $vector
     * @param  array<string, mixed>  $filter
     * @return list<array{id: int|string, score: float, payload: array<string, mixed>}>
     */
    public function query(string $collection, array $vector, int $limit, array $filter = []): array
    {
        $body = ['query' => $vector, 'limit' => $limit, 'with_payload' => true] + ($filter === [] ? [] : ['filter' => $filter]);

        return array_map(
            fn (array $point) => ['id' => $point['id'], 'score' => (float) $point['score'], 'payload' => $point['payload'] ?? []],
            $this->send(fn ($http) => $http->post("/collections/{$collection}/points/query", $body))->json('result.points') ?? [],
        );
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(callable $call, bool $allowNotFound = false): ?Response
    {
        try {
            $response = $call(Http::baseUrl($this->url)->acceptJson()->timeout($this->timeout));
        } catch (ConnectionException $e) {
            throw new QdrantException("[qdrant] Could not connect to {$this->url}.", previous: $e);
        }

        if ($allowNotFound && $response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            // Qdrant 的錯誤格式：{"status": {"error": "..."}}
            throw new QdrantException(sprintf('[qdrant] HTTP %d: %s', $response->status(), $response->json('status.error') ?? mb_substr($response->body(), 0, 200)));
        }

        return $response;
    }
}
