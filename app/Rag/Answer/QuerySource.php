<?php

namespace App\Rag\Answer;

/**
 * 提問來源（rag_query_logs.source_key）。依實際問答重新校準門檻時，要排除 eval 的測試題。
 */
enum QuerySource: string
{
    case Api = 'api';
    case Cli = 'cli';
    case Eval = 'eval';
}
