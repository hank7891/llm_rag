<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\DocumentServiceProvider;
use App\Providers\RagServiceProvider;

return [
    AppServiceProvider::class,
    AiServiceProvider::class,
    DocumentServiceProvider::class,
    RagServiceProvider::class,
];
