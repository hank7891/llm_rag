<?php

use App\Ai\Chat\Providers\FakeChatProvider;

/*
 * LLM 抽象層設定。檔名不用 ai.php，避免與官方套件 laravel/ai 的 config/ai.php 衝突。
 */
return [

    'chat' => [

        // 呼叫端未指定 provider 時使用的名稱
        'default' => env('LLM_CHAT_PROVIDER', 'fake'),

        // provider 名稱 → 實作 ChatProviderInterface 的類別
        'providers' => [
            'fake' => FakeChatProvider::class,
        ],

    ],

];
