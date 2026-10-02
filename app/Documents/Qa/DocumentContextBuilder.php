<?php

namespace App\Documents\Qa;

use App\Ai\Support\TokenEstimator;
use App\Models\DocumentPage;
use App\Repositories\DocumentPageRepository;

/**
 * 依頁碼順序組合文件全文，每頁前面加上【第 N 頁】，讓模型回答時能指出頁碼。
 */
class DocumentContextBuilder
{
    public function __construct(private readonly DocumentPageRepository $pages) {}

    public function build(int $documentId): DocumentContext
    {
        $pages = $this->pages->forDocument($documentId);

        $text = $pages
            ->map(fn (DocumentPage $page) => "【第 {$page->page_number} 頁】\n".$this->neutralizeTags($page->content))
            ->implode("\n\n");

        return new DocumentContext(
            text: $text,
            pageCount: $pages->count(),
            chars: mb_strlen($text),
            estimatedTokens: TokenEstimator::estimate($text),
        );
    }

    /**
     * 文件內容若剛好含有 </document>，會提前結束標籤，讓後面的文字被模型當成標籤外的指令。
     * 把標籤改成全形角括號，內容仍看得懂，但不再是有效的標籤。
     */
    private function neutralizeTags(string $content): string
    {
        return preg_replace('/<(\/?)document>/i', '＜$1document＞', $content);
    }
}
