<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/') . '/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Tài liệu (video HLS, PDF, sách) — riêng tư, chỉ mở bằng link tạm có chữ ký.
        // Dev / test: ổ này (storage/app/private/documents, link /private-docs/... có chữ ký).
        // Production: DOCUMENTS_DISK=documents_s3 (link tạm = presigned URL của S3).
        'documents_local' => [
            'driver' => 'local',
            'root' => storage_path('app/private/documents'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/') . '/private-docs',
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // ── Production (AWS): một bucket riêng tư (Block Public Access bật, ACL tắt), chia 2 thư mục ──
        // ACL luôn "bucket-owner-full-control": bucket tắt ACL từ chối public-read; quyền xem do bucket policy / link tạm quyết định.

        // Ảnh công khai (MEDIA_DISK=media_s3): thư mục media/ của bucket, xem qua CloudFront (AWS_URL, VD https://media.ellso.edu.vn).
        'media_s3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'root' => 'media',
            'url' => env('AWS_URL'),
            'visibility' => 'private',
            'options' => ['ACL' => 'bucket-owner-full-control', 'CacheControl' => 'public, max-age=31536000'],
            'throw' => false,
            'report' => false,
        ],

        // Tài liệu riêng tư (DOCUMENTS_DISK=documents_s3): thư mục documents/, chỉ mở bằng link tạm (presigned).
        // Livewire để file tạm khi upload cũng ở đây (documents/livewire-tmp/, LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=documents_s3)
        // → trình duyệt tải thẳng lên S3, video lớn không đi qua server; lưu video = move ngay trong S3.
        'documents_s3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'root' => 'documents',
            'visibility' => 'private',
            'options' => ['ACL' => 'bucket-owner-full-control'],
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
