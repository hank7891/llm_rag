<?php

namespace App\Providers;

use App\Ai\Chat\ChatService;
use App\Ai\Embedding\EmbeddingService;
use App\Ai\Rerank\RerankService;
use App\Rag\Answer\RagAnswerService;
use App\Rag\Answer\ReferenceContextBuilder;
use App\Rag\Citation\CitationFormatter;
use App\Rag\Citation\CitationParser;
use App\Rag\Citation\CitationResolver;
use App\Rag\Conversation\HistoryWindow;
use App\Rag\Conversation\QueryRewriter;
use App\Rag\Evaluation\ConversationEvaluator;
use App\Rag\Retrieval\KeywordOnlyPolicy;
use App\Rag\Retrieval\RankFusion;
use App\Rag\Retrieval\RerankStage;
use App\Rag\Retrieval\RetrievalMode;
use App\Rag\Retrieval\RetrieverService;
use App\Rag\Search\ExactTermExtractor;
use App\Rag\Search\SearchTextNormalizer;
use App\Rag\VectorStore\ChunkIndexer;
use App\Rag\VectorStore\CollectionManager;
use App\Rag\VectorStore\CollectionResolver;
use App\Rag\VectorStore\QdrantClient;
use App\Rag\VectorStore\VectorSearcher;
use App\Repositories\ConversationRepository;
use App\Repositories\DocumentChunkRepository;
use App\Repositories\KeywordSearchRepository;
use App\Repositories\RagQueryLogRepository;
use Illuminate\Support\ServiceProvider;

/**
 * 向量資料庫、檢索、問答與引用元件的組裝處：讀取 config/rag.php 的 qdrant、retrieval、answer、citation 區塊。
 */
class RagServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(QdrantClient::class, fn ($app) => new QdrantClient(
            $app['config']->get('rag.qdrant.url'),
            $app['config']->get('rag.qdrant.timeout'),
        ));

        $this->app->bind(CollectionResolver::class, fn ($app) => new CollectionResolver($app['config']->get('rag.qdrant.collection_prefix')));

        $this->app->bind(ChunkIndexer::class, fn ($app) => new ChunkIndexer(
            $app->make(EmbeddingService::class),
            $app->make(CollectionManager::class),
            $app->make(QdrantClient::class),
            $app->make(DocumentChunkRepository::class),
            $app['config']->get('rag.qdrant.upsert_batch_size'),
        ));

        // 門檻以整個陣列注入：模型名稱含「.」（如 qwen3-embedding:0.6b），不能用 config 的點號路徑取值
        $this->app->bind(RetrieverService::class, fn ($app) => new RetrieverService(
            $app->make(VectorSearcher::class),
            $app->make(EmbeddingService::class),
            $app->make(KeywordSearchRepository::class),
            $app->make(ExactTermExtractor::class),
            $app->make(SearchTextNormalizer::class),
            $app->make(DocumentChunkRepository::class),
            $app->make(RankFusion::class),
            $app['config']->get('rag.retrieval.top_k'),
            $app['config']->get('rag.retrieval.score_thresholds'),
            RetrievalMode::from($app['config']->get('rag.retrieval.mode')),
            KeywordOnlyPolicy::from($app['config']->get('rag.retrieval.keyword_only_policy')),
            $app['config']->get('rag.retrieval.dense_candidates'),
            $app['config']->get('rag.retrieval.keyword_candidates'),
            $app['config']->get('rag.retrieval.rrf_k'),
            new RerankStage($app->make(RerankService::class), $app['log'], $app['config']->get('rag.rerank.min_score')),
            $app['config']->get('rag.rerank.enabled'),
            $app['config']->get('rag.rerank.candidates'),
            $app['config']->get('rag.rerank.keep_exact'),
            $app['config']->get('rag.rerank.prefix_metadata'),
        ));

        $this->app->bind(CitationResolver::class, fn ($app) => new CitationResolver(
            $app->make(CitationParser::class),
            $app->make(DocumentChunkRepository::class),
            $app['log'],
            $app['config']->get('rag.citation.strip_invalid'),
        ));

        $this->app->bind(CitationFormatter::class, fn ($app) => new CitationFormatter(
            $app['config']->get('rag.citation.label_format'),
            $app['config']->get('rag.citation.page_format'),
            $app['config']->get('rag.citation.merge_same_source'),
        ));

        $this->app->bind(HistoryWindow::class, fn ($app) => new HistoryWindow(
            $app->make(CitationParser::class),
            $app['config']->get('rag.conversation.window_turns'),
            $app['config']->get('rag.conversation.history_budget_chars'),
        ));

        $this->app->bind(QueryRewriter::class, function ($app) {
            $rewrite = $app['config']->get('rag.conversation.rewrite');

            return new QueryRewriter(
                $app->make(ChatService::class),
                $app['log'],
                resource_path('prompts'),
                $rewrite['prompt_version'],
                $rewrite['provider'],
                $rewrite['providers'],
                $rewrite['max_chars'],
                $app['config']->get('rag.answer.insufficient_message'),
            );
        });

        $this->app->bind(ConversationEvaluator::class, fn ($app) => new ConversationEvaluator(
            $app->make(RetrieverService::class),
            $app->make(QueryRewriter::class),
            $app->make(RagAnswerService::class),
            $app['config']->get('rag.conversation.window_turns'),
            $app['config']->get('rag.conversation.history_budget_chars'),
        ));

        $this->app->bind(RagAnswerService::class, function ($app) {
            $config = $app['config']->get('rag.answer');

            return new RagAnswerService(
                $app->make(RetrieverService::class),
                $app->make(ChatService::class),
                $app->make(ReferenceContextBuilder::class),
                // System Prompt 與固定回覆使用同一個設定值，改其中一邊不會忘了另一邊
                str_replace('{insufficient_message}', $config['insufficient_message'], file_get_contents($config['system_prompt'])),
                $config['top_k'],
                $config['context_budget_chars'],
                $config['insufficient_message'],
                $config['default_provider'],
                $app->make(RagQueryLogRepository::class),
                $app['log'],
                $app->make(CitationResolver::class),
                $app->make(CitationFormatter::class),
                $app->make(ConversationRepository::class),
                $app->make(HistoryWindow::class),
                $app->make(QueryRewriter::class),
                $app['config']->get('rag.conversation.enabled'),
                $app['config']->get('rag.conversation.window_turns'),
            );
        });
    }
}
