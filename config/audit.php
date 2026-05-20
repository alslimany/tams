<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Master Switch
    |--------------------------------------------------------------------------
    | NEVER enable in production. Only enable in local/staging environments
    | when running an accounting audit session.
    */
    'enabled' => env('ACCOUNTING_AUDIT_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Watched Route Prefixes
    |--------------------------------------------------------------------------
    | Any request whose path starts with one of these prefixes will be
    | intercepted and recorded by AuditRecorderMiddleware.
    */
    'watch_routes' => [
        // REST API v1 routes
        'api/v1/flights',
        'api/v1/hotels',
        'api/v1/insurance',
        'api/v1/esim',
        'api/v1/orders',
        // Tenant web routes (path-based: agency/{tenant}/...)
        'agency',
        // Accounting management routes
        'accounting/wallets',
        'accounting/settlement',
        'accounting/cancellations',
    ],

    /*
    |--------------------------------------------------------------------------
    | Capture Provider API Responses
    |--------------------------------------------------------------------------
    | Set to false if provider responses are too large to store in the log.
    */
    'capture_provider_api' => env('AUDIT_CAPTURE_PROVIDER_API', true),

    /*
    |--------------------------------------------------------------------------
    | Log Directory
    |--------------------------------------------------------------------------
    */
    'log_path' => storage_path('logs/audit'),

    /*
    |--------------------------------------------------------------------------
    | Redacted Fields
    |--------------------------------------------------------------------------
    | These fields will be replaced with [REDACTED] in captured payloads.
    */
    'redact_fields' => [
        'password',
        'token',
        'api_key',
        'secret',
        'card_number',
        'cvv',
        'passport_number',
        'national_id',
    ],
];
