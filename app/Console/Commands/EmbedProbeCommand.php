<?php

namespace App\Console\Commands;

use App\Ai\Embedding\DTO\EmbeddingInputType;
use App\Ai\Embedding\DTO\EmbeddingOptions;
use App\Ai\Embedding\DTO\EmbeddingResult;
use App\Ai\Embedding\EmbeddingModels;
use App\Ai\Embedding\EmbeddingService;
use App\Ai\Embedding\Support\VectorMath;
use App\Repositories\DocumentChunkRepository;
use Illuminate\Console\Command;

/**
 * 實驗用：確認 Embedding 模型的實際維度、Token 比例、前綴，並計算幾組句子的 Cosine Similarity。
 */
class EmbedProbeCommand extends Command
{
    protected $signature = 'llm:embed-probe
        {--provider= : embedding provider 名稱，未填使用預設值}
        {--model= : 模型名稱，未填使用 Provider 的預設模型}
        {--document= : 另外以這份文件的所有 Chunk 實測 Token／字元比例}';

    protected $description = '實測 Embedding 模型的維度、Token 比例、前綴與句子之間的 Cosine Similarity';

    /** [問句（query）, 文件句（document）, 預期] */
    private const PAIRS = [
        ['我的假沒休完怎麼辦？', '年度特別休假未休畢者，得遞延至次一年度。', '應該很高'],
        ['如何取消訂單', '訂單退訂流程', '應該很高'],
        ['我的假沒休完怎麼辦？', '停車場收費標準', '低，但不會是 0'],
    ];

    public function handle(EmbeddingService $embedding, EmbeddingModels $models, DocumentChunkRepository $chunks): int
    {
        $provider = $this->option('provider');
        $model = $this->option('model');

        // 每一句單獨送出：/api/embed 批次送出時只回傳整批的 Token 總數，拿不到每一句的
        $sentences = [];
        foreach (self::PAIRS as [$query, $document]) {
            $sentences[$query] ??= $this->embedOne($embedding, $query, EmbeddingInputType::Query, $model, $provider);
            $sentences[$document] ??= $this->embedOne($embedding, $document, EmbeddingInputType::Document, $model, $provider);
        }

        $first = reset($sentences)['result'];
        $spec = $models->spec($first->model);
        $this->info("模型：{$first->model}｜實際維度：{$first->dimension}｜規格維度：{$spec['dimension']}｜最大輸入：{$spec['max_tokens']} Token");
        $this->line('query 前綴：'.($spec['query_prefix'] === '' ? '（無）' : json_encode($spec['query_prefix'], JSON_UNESCAPED_UNICODE)));
        $this->line('document 前綴：'.($spec['document_prefix'] === '' ? '（無）' : json_encode($spec['document_prefix'], JSON_UNESCAPED_UNICODE)));

        $this->table(['類型', '送出的字串（含前綴）', '字數', 'prompt_eval_count', 'Token ÷ 字數'], array_map(fn (array $s) => [
            $s['type']->value,
            mb_strimwidth(str_replace("\n", '⏎', $s['sent']), 0, 70, '…'),
            $s['chars'],
            $s['result']->inputTokens,
            sprintf('%.2f', $s['result']->inputTokens / $s['chars']),
        ], array_values($sentences)));

        $this->table(['問句', '文件句', 'Cosine', '預期'], array_map(fn (array $pair) => [
            $pair[0],
            $pair[1],
            sprintf('%.4f', VectorMath::cosine($sentences[$pair[0]]['result']->vectors[0], $sentences[$pair[1]]['result']->vectors[0])),
            $pair[2],
        ], self::PAIRS));

        if ($this->option('document') !== null) {
            $this->documentRatio($embedding, $chunks, (int) $this->option('document'), $model, $provider);
        }

        return self::SUCCESS;
    }

    /** @return array{type: EmbeddingInputType, sent: string, chars: int, result: EmbeddingResult} */
    private function embedOne(EmbeddingService $embedding, string $text, EmbeddingInputType $type, ?string $model, ?string $provider): array
    {
        $result = $embedding->embed([$text], new EmbeddingOptions($type, $model), $provider);
        $spec = app(EmbeddingModels::class)->spec($result->model);
        $prefix = $type === EmbeddingInputType::Query ? $spec['query_prefix'] : $spec['document_prefix'];

        return ['type' => $type, 'sent' => $prefix.$text, 'chars' => mb_strlen($text), 'result' => $result];
    }

    /** 以整份文件的 Chunk 實測 Token／字元比例，檢查 Ch05 的 Chunk 長度估算是否安全 */
    private function documentRatio(EmbeddingService $embedding, DocumentChunkRepository $chunks, int $documentId, ?string $model, ?string $provider): void
    {
        $list = $chunks->forDocument($documentId);

        if ($list->isEmpty()) {
            $this->warn("文件 #{$documentId} 沒有 Chunk。");

            return;
        }

        $result = $embedding->embed($list->pluck('content')->all(), new EmbeddingOptions(EmbeddingInputType::Document, $model), $provider);
        $chars = $list->sum('char_count');
        $estimated = $list->sum('token_count');

        $this->table(['文件', 'Chunk 數', '總字數', 'Ch05 估算 Token', '實際 Token', '實際 ÷ 字數', '實際 ÷ 估算'], [[
            "#{$documentId}",
            $list->count(),
            $chars,
            $estimated,
            $result->inputTokens,
            sprintf('%.2f', $result->inputTokens / $chars),
            sprintf('%.0f%%', $result->inputTokens / $estimated * 100),
        ]]);
    }
}
