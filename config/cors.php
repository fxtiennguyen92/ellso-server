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

    // API của app nằm ở /v1/* (không có prefix api/) — bản web của app gọi từ trình duyệt nên cần CORS
    // private-docs/*: link tạm có chữ ký của ổ tài liệu local (dev) — trình đọc sách bản web tải file bằng fetch
    'paths' => ['v1/*', 'sanctum/csrf-cookie', 'private-docs/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
