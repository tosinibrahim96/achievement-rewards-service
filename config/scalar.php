<?php

declare(strict_types=1);

return [
    'domain' => null,
    'path' => '/docs',
    'middleware' => ['web'],
    'url' => '/openapi.yaml',
    'cdn' => 'https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.66.1',
    'configuration' => [
        'theme' => 'laravel',
        'layout' => 'modern',
        'persistAuth' => false,
        'metaData' => [
            'title' => config('app.name').' API Reference',
        ],
        'defaultHttpClient' => [
            'targetId' => 'shell',
            'clientKey' => 'curl',
        ],
        'authentication' => [
            'preferredSecurityScheme' => 'bearerAuth',
        ],
    ],
];
