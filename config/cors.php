<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // 只允許本系統的網址（Ch14）。API 沒有登入機制，允許 * 時任何網站都能借用內部同仁的瀏覽器查詢知識庫並讀到回答。
    // 同源的頁面（/knowledge/chat）本來就不受 CORS 限制。注意：這只能防止跨站「讀取回應」，不能阻擋盲送請求；根本解法是加入登入
    'allowed_origins' => [rtrim((string) env('APP_URL', 'http://localhost'), '/')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
