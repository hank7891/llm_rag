<?php

namespace App\Http\Controllers;

use App\Ai\Chat\ChatProviderCatalog;
use App\Ai\Chat\ChatService;
use App\Rag\Conversation\QueryRewriter;
use Illuminate\View\View;

/**
 * 知識問答頁。問答本身走 /api/knowledge/ask（/stream），頁面只負責畫面與 Provider 清單。
 */
class KnowledgeChatController extends Controller
{
    public function __invoke(ChatProviderCatalog $catalog, QueryRewriter $rewriter, ChatService $chat): View
    {
        // 只列出有改寫設定的 Provider：沒有改寫設定時追問一律以原問題檢索，連續追問會失準
        $providers = array_filter($catalog->all(), fn (array $provider, string $name) => $rewriter->supports($name), ARRAY_FILTER_USE_BOTH);

        return view('knowledge.chat', [
            'providers' => $providers,
            'defaultProvider' => isset($providers[$chat->defaultProvider()]) ? $chat->defaultProvider() : array_key_first($providers),
        ]);
    }
}
