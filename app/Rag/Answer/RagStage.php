<?php

namespace App\Rag\Answer;

/**
 * 問答的處理階段（串流時以 stage 事件告知前端目前進度）。
 */
enum RagStage: string
{
    case Rewriting = 'rewriting';
    case Retrieving = 'retrieving';
    case Generating = 'generating';
}
