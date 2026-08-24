<?php

return [
    'uri' => env('LARAVEL_ERD_URI', 'laravel-erd'),

    // Keep the generated schema with the project documentation.
    'storage_path' => base_path('docs/erd'),

    'extension' => env('LARAVEL_ERD_EXTENSION', 'sql'),

    'middleware' => [],

    'binary' => [
        'erd-go' => env('LARAVEL_ERD_GO', '/usr/local/bin/erd-go'),
        'dot' => env('LARAVEL_ERD_DOT', '/usr/local/bin/dot'),
    ],

    'connections' => [],
];
