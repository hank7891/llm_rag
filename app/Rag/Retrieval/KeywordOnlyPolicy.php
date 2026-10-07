<?php

namespace App\Rag\Retrieval;

/**
 * 只被「一般關鍵字」找到（沒有通過 Dense 門檻、也不是精確命中）的 Chunk 能否進入結果。
 */
enum KeywordOnlyPolicy: string
{
    // 只有精確命中才能以「僅關鍵字」身分進入：無答案題最安全；抽不出規則的詞（人名）可能漏掉
    case ExactOnly = 'exact_only';

    // 一般關鍵字結果也可經 RRF 進入 Top-K：召回較高，但中文常見二字詞容易讓不相關的段落混進來
    case Allow = 'allow';
}
