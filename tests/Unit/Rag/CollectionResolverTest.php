<?php

namespace Tests\Unit\Rag;

use App\Rag\VectorStore\CollectionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CollectionResolverTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function models(): array
    {
        return [
            '連字號' => ['bge-m3', 'company_docs_bge_m3'],
            '冒號與點' => ['qwen3-embedding:0.6b', 'company_docs_qwen3_embedding_0_6b'],
            ':latest 視為同一個模型' => ['bge-m3:latest', 'company_docs_bge_m3'],
            '大寫轉小寫' => ['BGE-M3', 'company_docs_bge_m3'],
            '連續符號只留一個底線' => ['org/model--v2', 'company_docs_org_model_v2'],
        ];
    }

    #[DataProvider('models')]
    public function test_collection_name_is_derived_from_model(string $model, string $collection): void
    {
        $this->assertSame($collection, (new CollectionResolver('company_docs'))->name($model));
    }

    public function test_owns_only_collections_with_prefix(): void
    {
        $resolver = new CollectionResolver('company_docs');

        $this->assertSame(
            [true, true, false, false],
            [$resolver->owns('company_docs_bge_m3'), $resolver->owns('company_docs_qwen3_embedding_0_6b'), $resolver->owns('test_company_docs_fake'), $resolver->owns('company_docsx')],
        );
    }
}
