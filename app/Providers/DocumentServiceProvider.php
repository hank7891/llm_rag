<?php

namespace App\Providers;

use App\Ai\Chat\ChatService;
use App\Documents\Chunking\ChunkingOptions;
use App\Documents\Chunking\ChunkingService;
use App\Documents\Chunking\PageTextAssembler;
use App\Documents\Chunking\StructureSplitter;
use App\Documents\DocumentParsingService;
use App\Documents\DocumentService;
use App\Documents\Parsing\ParserResolver;
use App\Documents\Parsing\PdfParser;
use App\Documents\Parsing\TextNormalizer;
use App\Documents\Qa\DocumentContextBuilder;
use App\Documents\Qa\DocumentQaService;
use App\Repositories\DocumentPageRepository;
use App\Repositories\DocumentRepository;
use Illuminate\Support\ServiceProvider;

/**
 * 文件處理元件的組裝處：唯一讀取 config/documents.php 的地方。
 */
class DocumentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PdfParser::class, fn ($app) => new PdfParser(
            $app['config']->get('documents.pdftotext.binary'),
            $app['config']->get('documents.pdftotext.timeout'),
        ));

        $this->app->bind(DocumentService::class, fn ($app) => new DocumentService(
            $app->make(DocumentRepository::class),
            $app['filesystem']->disk($app['config']->get('documents.disk')),
            $app['config']->get('documents.directory'),
        ));

        $this->app->bind(ChunkingService::class, fn ($app) => new ChunkingService(
            $app->make(PageTextAssembler::class),
            $app->make(StructureSplitter::class),
            ChunkingOptions::fromConfig($app['config']->get('rag.chunking')),
            $app['config']->get('rag.chunking.token_estimate.cjk_per_char'),
            $app['config']->get('rag.chunking.token_estimate.other_per_char'),
        ));

        $this->app->bind(DocumentQaService::class, fn ($app) => new DocumentQaService(
            $app->make(ChatService::class),
            $app->make(DocumentContextBuilder::class),
            file_get_contents($app['config']->get('documents.qa.system_prompt')),
        ));

        $this->app->bind(DocumentParsingService::class, fn ($app) => new DocumentParsingService(
            $app->make(DocumentRepository::class),
            $app->make(DocumentPageRepository::class),
            $app->make(ParserResolver::class),
            $app->make(TextNormalizer::class),
            $app['filesystem']->disk($app['config']->get('documents.disk')),
            $app['config']->get('documents.scanned_min_chars_per_page'),
        ));
    }
}
