<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/*', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'https://app.passions.in',
        'https://passions.in',
        'https://api.passions.in',
        'https://www.passions.in',
        'https://www.api.passions.in',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true, // Set to true to allow cookies/Sanctum

];