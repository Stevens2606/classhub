<?php

return [
    // Internal microservice (Go) that stores course materials and tryouts.
    'content_service' => [
        'url' => env('CONTENT_SERVICE_URL', 'http://content-service:8080'),
        'key' => env('CONTENT_SERVICE_KEY'),
        'timeout' => (int) env('CONTENT_SERVICE_TIMEOUT', 3),
    ],

    // Midtrans payment gateway. Sandbox by default: no real money is ever moved.
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'snap_url' => env('MIDTRANS_SNAP_URL', 'https://app.sandbox.midtrans.com/snap/v1/transactions'),
    ],

    'otp' => [
        'ttl_minutes' => 10,
        'max_attempts' => 5,
    ],
];
