<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | AhaSend API
    |--------------------------------------------------------------------------
    |
    | Used by the "ahasend" mailer, which sends through the AhaSend API v2. Both
    | are in the AhaSend dashboard under API Keys. "services.ahasend.key" and
    | "services.ahasend.account_id" work as well.
    |
    */

    'api_key' => env('AHASEND_API_KEY'),

    'account_id' => env('AHASEND_ACCOUNT_ID'),

    /*
    |--------------------------------------------------------------------------
    | AhaSend SMTP
    |--------------------------------------------------------------------------
    |
    | Used by the "ahasend-smtp" mailer, which sends through AhaSend's SMTP relay
    | with STARTTLS required. These are your AhaSend SMTP credentials.
    |
    */

    'smtp' => [
        'username' => env('AHASEND_SMTP_USERNAME'),
        'password' => env('AHASEND_SMTP_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Every request to the webhook route must be signed with one of these
    | secrets; without one, all of them are refused. They are returned when you
    | create the webhook or the route in AhaSend. Use the complete string,
    | including any prefix. The tolerance is the largest difference, in seconds,
    | allowed between the current time and the signed request's.
    |
    */

    'webhook' => [
        'secret' => env('AHASEND_WEBHOOK_SECRET'),
        'route_secret' => env('AHASEND_ROUTE_SECRET'),
        'tolerance' => env('AHASEND_WEBHOOK_TOLERANCE', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook route
    |--------------------------------------------------------------------------
    |
    | The webhook handler is at POST /{path}/webhook, on the given domain if you
    | set one, with the given middleware. Set "routes" to false to handle
    | webhooks yourself.
    |
    */

    'routes' => env('AHASEND_ROUTES', true),

    'domain' => env('AHASEND_DOMAIN'),

    'path' => env('AHASEND_PATH', 'ahasend'),

    'middleware' => [],
];
