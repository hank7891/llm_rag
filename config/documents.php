<?php

return [

    // 上傳檔案存放的 Disk（local 為私有 Disk：storage/app/private，不對外公開）
    'disk' => 'local',

    'directory' => 'documents',

    // 上傳大小上限（KB）。不可超過 php.ini 的 upload_max_filesize（目前 8M），否則超過時 Laravel 收不到檔案
    'max_upload_kb' => 8 * 1024,

    'pdftotext' => [
        // Homebrew 預設路徑為 /opt/homebrew/bin/pdftotext。用 ?: 而非 env() 的預設值參數：
        // .env 寫了 PDFTOTEXT_PATH= 但沒填值時，env() 會回傳空字串而不是預設值
        'binary' => env('PDFTOTEXT_PATH') ?: 'pdftotext',
        'timeout' => 60,
    ],

    // 平均每頁可見字元數低於此值時，視為掃描型 PDF（沒有文字層）
    'scanned_min_chars_per_page' => 20,

    'qa' => [
        // System Prompt 獨立成檔案，修改規則不用改程式
        'system_prompt' => resource_path('prompts/document-qa.md'),
    ],

];
