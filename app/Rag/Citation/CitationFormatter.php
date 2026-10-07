<?php

namespace App\Rag\Citation;

/**
 * 依 config/rag.php 的 citation 設定產生來源的顯示文字，例如「員工管理辦法.pdf　第十二條　第 2 頁」。
 */
class CitationFormatter
{
    /** @param array{single: string, range: string} $pageFormat */
    public function __construct(
        private readonly string $labelFormat,
        private readonly array $pageFormat,
        private readonly bool $mergeSameSource,
    ) {}

    public function label(Citation $citation): string
    {
        $pages = strtr($citation->pageStart === $citation->pageEnd ? $this->pageFormat['single'] : $this->pageFormat['range'], [
            '{start}' => $citation->pageStart,
            '{end}' => $citation->pageEnd,
        ]);

        $label = strtr($this->labelFormat, ['{document}' => $citation->documentName, '{section}' => $citation->section ?? '', '{pages}' => $pages]);

        // section 為空時省略條號，連同多出來的分隔空白
        return trim(preg_replace('/(\x{3000}|\s){2,}/u', '　', $label));
    }

    /**
     * 來源清單，依編號排序。merge_same_source 時，同檔、同條、同頁的編號合併成一行：「[3][4] 員工差勤管理規章.pdf　…」
     *
     * @param  list<Citation>  $citations  依編號排序
     * @return list<string>
     */
    public function lines(array $citations): array
    {
        $groups = [];
        foreach ($citations as $citation) {
            // 以 document_id 而不是檔名判斷「同檔」：同名的兩份文件（例如副本）不能合併
            $key = $this->mergeSameSource
                ? "{$citation->documentId}|{$citation->section}|{$citation->pageStart}|{$citation->pageEnd}"
                : (string) $citation->ref;
            $groups[$key]['refs'][] = "[{$citation->ref}]";
            $groups[$key]['label'] = $this->label($citation);
        }

        return array_map(fn (array $group) => implode('', $group['refs']).' '.$group['label'], array_values($groups));
    }
}
