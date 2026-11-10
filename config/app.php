<?php

declare(strict_types=1);

return [
    'name'    => $_ENV['APP_NAME']  ?? 'Forma A',
    'env'     => $_ENV['APP_ENV']   ?? 'production',
    'debug'   => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url'     => $_ENV['APP_URL']   ?? 'http://localhost',

    'session' => [
        'lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 7200),
    ],

    'upload' => [
        'max_size'   => (int)($_ENV['UPLOAD_MAX_SIZE'] ?? 10485760),
        'allowed_ext' => explode(',', $_ENV['UPLOAD_ALLOWED_EXTENSIONS'] ?? 'pdf,doc,docx,xls,xlsx,jpg,jpeg,png,zip'),
        'storage_path' => dirname(__DIR__) . '/storage/uploads',
    ],

    'log_path' => dirname(__DIR__) . '/storage/logs',
];