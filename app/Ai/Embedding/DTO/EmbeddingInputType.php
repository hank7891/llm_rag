<?php

namespace App\Ai\Embedding\DTO;

/**
 * 輸入是「問題」還是「文件」。部分模型要求兩者加上不同的前綴，由 Provider 依模型規格處理。
 */
enum EmbeddingInputType: string
{
    case Query = 'query';
    case Document = 'document';
}
