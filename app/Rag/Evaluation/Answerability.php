<?php

namespace App\Rag\Evaluation;

/**
 * 題目在語料中能否回答（v2 測試集的 answerable 欄位）。v1 沒有這個欄位，由題型推得。
 *
 * partial：有可引用的規則，但問題有一部分需要語料以外的資料；檢索仍要命中所列條文，不納入門檻選值。
 */
enum Answerability: string
{
    case Yes = 'yes';
    case No = 'no';
    case Partial = 'partial';
}
