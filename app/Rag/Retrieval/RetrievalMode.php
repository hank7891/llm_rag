<?php

namespace App\Rag\Retrieval;

/**
 * 檢索模式（rag.retrieval.mode），評估時可逐次切換。
 */
enum RetrievalMode: string
{
    // 只用向量檢索（Ch08）
    case Dense = 'dense';

    // 只用關鍵字（MySQL FULLTEXT ngram），評估對照用
    case Keyword = 'keyword';

    // 向量 + 關鍵字，以 RRF 合併排名（Ch11）
    case Hybrid = 'hybrid';
}
