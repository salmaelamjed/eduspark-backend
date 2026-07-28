<?php

return [

    'paths' => ['https://eduspark-20sfydw9.b4a.run/api/','api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'register', 'verify-email', 'resend-verification-code','stripe/webhook'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'https://eduspark-frontend-one.vercel.app'
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*', 'X-CSRF-TOKEN', 'X-XSRF-TOKEN', 'Authorization'],

    'exposed_headers' => ['X-CSRF-TOKEN'],

    'max_age' => 3600,

    'supports_credentials' => true,

];
