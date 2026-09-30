<?php

namespace App\Documents\Parsing;

/**
 * 解析後文字的正規化。原則：不確定就保留原樣（寧可少改，不可改錯正文）。
 *
 * 1. 每頁：統一換行字元、全形英數轉半形、清理空白（Markdown 保留縮排與行尾空白）
 * 2. 跨頁：移除在多數頁面邊界重複出現的頁首頁尾（至少 3 頁才做）
 * 3. 每頁（僅限有硬換行的來源，如 PDF）：接回排版造成的斷行
 * 4. 每頁：連續空行壓成一個，去掉頭尾空行
 *
 * 不使用 NFKC：它會連同部分中文標點與相容字元一起轉換，改動正文。
 */
class TextNormalizer
{
    /** 頁首頁尾偵測所需的最少頁數；頁數太少時「重複出現」不具統計意義，容易誤刪正文 */
    private const MIN_PAGES_FOR_HEADER_FOOTER = 3;

    /** 每頁各檢查前後幾個非空行 */
    private const HEADER_FOOTER_LINES = 2;

    /** 中日韓文字、中文標點、全形符號 */
    private const CJK = '\x{3000}-\x{303F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF00}-\x{FFEF}';

    /**
     * @param  list<ParsedPage>  $pages
     * @return list<ParsedPage>
     */
    public function normalize(array $pages, bool $joinWrappedLines = false, bool $preserveLayout = false): array
    {
        $lines = array_map(fn (ParsedPage $page) => $this->cleanLines($page->content, $preserveLayout), $pages);

        if (! $preserveLayout && count($pages) >= self::MIN_PAGES_FOR_HEADER_FOOTER) {
            $lines = $this->removeHeadersAndFooters($lines, fromTop: true);
            $lines = $this->removeHeadersAndFooters($lines, fromTop: false);
        }

        return array_map(function (ParsedPage $page, array $pageLines) use ($joinWrappedLines) {
            if ($joinWrappedLines) {
                $pageLines = $this->joinWrappedLines($pageLines);
            }

            $content = preg_replace("/\n{3,}/", "\n\n", implode("\n", $pageLines));

            return new ParsedPage($page->pageNumber, trim($content, "\n"));
        }, $pages, $lines);
    }

    /** 全形英數字轉半形；英數字之間的全形連字號（如 ＨＲ－２０２６）轉為半形。中文標點不動。 */
    public function toHalfwidth(string $text): string
    {
        $text = preg_replace_callback(
            '/[\x{FF10}-\x{FF19}\x{FF21}-\x{FF3A}\x{FF41}-\x{FF5A}]/u',
            fn (array $m) => mb_chr(mb_ord($m[0]) - 0xFEE0),
            $text,
        );

        return preg_replace('/(?<=[A-Za-z0-9])\x{FF0D}(?=[A-Za-z0-9])/u', '-', $text);
    }

    /** @return list<string> */
    private function cleanLines(string $content, bool $preserveLayout): array
    {
        $content = $this->toHalfwidth(str_replace(["\r\n", "\r"], "\n", $content));

        if ($preserveLayout) {
            return explode("\n", $content);
        }

        return array_map(function (string $line) {
            // NBSP 與 Tab 視為一般空白；保留行首縮排，只壓縮行內連續空白並去掉行尾空白
            $line = rtrim(str_replace(["\u{00A0}", "\t"], ' ', $line));

            return preg_replace('/(?<=\S) {2,}/u', ' ', $line);
        }, explode("\n", $content));
    }

    /**
     * 頁首（或頁尾）分開統計：每頁只看最前（或最後）幾個非空行，同一行在過半頁面的同一邊界出現，
     * 才視為頁首頁尾並只從該邊界移除。頁碼格式的行比較前先把數字換成 #（第1頁/共3頁 與 第2頁/共3頁 視為相同）；
     * 其他行不遮罩數字，避免「第1條」「第2條」被當成同一行。
     *
     * @param  list<list<string>>  $pages
     * @return list<list<string>>
     */
    private function removeHeadersAndFooters(array $pages, bool $fromTop): array
    {
        $candidates = [];
        $counts = [];

        foreach ($pages as $pageIndex => $lines) {
            $indexes = array_keys(array_filter($lines, fn (string $line) => trim($line) !== ''));
            $edge = $fromTop
                ? array_slice($indexes, 0, self::HEADER_FOOTER_LINES)
                : array_slice($indexes, -self::HEADER_FOOTER_LINES);

            foreach ($edge as $lineIndex) {
                $signature = $this->signature($lines[$lineIndex]);
                $candidates[$pageIndex][$lineIndex] = $signature;
                $counts[$signature][$pageIndex] = true;
            }
        }

        $threshold = max(2, (int) ceil(count($pages) / 2));

        foreach ($candidates as $pageIndex => $edgeLines) {
            foreach ($edgeLines as $lineIndex => $signature) {
                if (count($counts[$signature]) >= $threshold) {
                    unset($pages[$pageIndex][$lineIndex]);
                }
            }

            $pages[$pageIndex] = array_values($pages[$pageIndex]);
        }

        return $pages;
    }

    private function signature(string $line): string
    {
        $line = trim($line);

        return $this->isPageNumberLine($line) ? preg_replace('/\d+/', '#', $line) : $line;
    }

    /** 只由數字與頁碼常用字組成的行，例如「第 1 頁 / 共 3 頁」、「- 3 -」、「Page 2 of 10」 */
    private function isPageNumberLine(string $line): bool
    {
        return preg_match('/\d/', $line) === 1
            && preg_match('/^[\s\-–—|\/.:：第頁共次PpAaGgEeOoFf]*$/u', preg_replace('/\d+/', '', $line)) === 1;
    }

    /**
     * 接回排版造成的斷行。以下情況保留換行：空行（段落）、上一行以句末標點結尾、
     * 任一行是條文標題或清單項目。無法判斷的一律保留。
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function joinWrappedLines(array $lines): array
    {
        $result = [];

        foreach ($lines as $line) {
            $last = array_key_last($result);

            if ($last !== null && $this->isWrapped($result[$last], $line)) {
                $result[$last] .= $this->joiner($result[$last], ltrim($line)).ltrim($line);
            } else {
                $result[] = $line;
            }
        }

        return $result;
    }

    private function isWrapped(string $previous, string $next): bool
    {
        return trim($previous) !== ''
            && trim($next) !== ''
            && preg_match('/[。！？；：!?;:.][」』"\')）]*$/u', $previous) !== 1
            && ! $this->isStructural($previous)
            && ! $this->isStructural($next);
    }

    /** 條文／章節標題（第十二條、第三章）或清單項目（1.、一、、(一)、- ） */
    private function isStructural(string $line): bool
    {
        return preg_match(
            '/^\s*(第[一二三四五六七八九十百千零〇\d]+[條章節款項]|\d+[.、)]|[一二三四五六七八九十]+、|[（(][一二三四五六七八九十\d]+[）)]|[-*•・]\s)/u',
            $line,
        ) === 1;
    }

    /** 中文與中文、中文與英文之間直接相接；英文與英文之間補一個空白；英文斷字（full-\ntime）直接相接 */
    private function joiner(string $previous, string $next): string
    {
        if (preg_match('/[A-Za-z]-$/', $previous) === 1) {
            return '';
        }

        $cjk = '/['.self::CJK.']/u';

        return preg_match($cjk, mb_substr($previous, -1)) === 1 || preg_match($cjk, mb_substr($next, 0, 1)) === 1 ? '' : ' ';
    }
}
