<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * 上傳驗證：副檔名、實際 MIME（以 finfo 讀檔案內容判斷，不相信副檔名）、大小，
 * 並要求兩者一致，擋下「副檔名是 .pdf、內容卻是別的東西」的偽裝檔。
 */
class UploadDocumentRequest extends FormRequest
{
    /** 副檔名 → 允許的實際 MIME。Markdown 通常被 finfo 判斷為 text/plain */
    private const ALLOWED = [
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown'],
        'markdown' => ['text/plain', 'text/markdown'],
    ];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.config('documents.max_upload_kb'),
                'extensions:'.implode(',', array_keys(self::ALLOWED)),
                function (string $attribute, mixed $file, Closure $fail) {
                    if (! $file instanceof UploadedFile) {
                        return;
                    }

                    $allowed = self::ALLOWED[strtolower($file->getClientOriginalExtension())] ?? null;

                    // 副檔名不支援時已由 extensions 規則回報，不再重複提示
                    if ($allowed !== null && ! in_array($file->getMimeType(), $allowed, true)) {
                        $fail("檔案內容與副檔名不符（實際類型為 {$file->getMimeType()}），請確認檔案沒有被改副檔名。");
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => '請選擇要上傳的檔案。',
            'file.max' => '檔案不可超過 :max KB。',
            'file.extensions' => '只支援 PDF、TXT、Markdown 檔案。',
            'file.uploaded' => '檔案上傳失敗，可能超過伺服器允許的大小。',
        ];
    }
}
