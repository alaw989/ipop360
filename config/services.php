<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uptime alerting webhook
    |--------------------------------------------------------------------------
    | Used by uptime:canary to notify when the application status is degraded
    | for N+ consecutive checks. Set to a Slack webhook URL or any generic
    | webhook that accepts JSON POST bodies.
    */
    'alerting' => [
        'webhook_url' => env('ALERTING_WEBHOOK_URL'),
        'consecutive_threshold' => (int) env('ALERTING_CONSECUTIVE_THRESHOLD', 3),
    ],

    'serpapi' => [
        'api_key' => env('SERPAPI_API_KEY'),
    ],

    'socrata' => [
        'app_token' => env('SOCRATA_APP_TOKEN'),
        'endpoints' => [
            'nyc' => [
                'domain' => 'data.cityofnewyork.us',
                'dataset_id' => env('SOCRATA_NYC_DATASET_ID', 'py6s-7cay'),
                'fields' => ['dba', 'address', 'boro', 'zip', 'phone', 'latitude', 'longitude', 'grade', 'score', 'inspection_date'],
            ],
            'sf' => [
                'domain' => 'data.sfgov.org',
                'dataset_id' => env('SOCRATA_SF_DATASET_ID', 'vw6y-z8j6'),
                'fields' => ['business_name', 'street_address', 'city', 'postal_code', 'phone', 'latitude', 'longitude', 'inspection_score', 'inspection_date'],
            ],
        ],
    ],

    'ai' => [
        'api_key' => env('AI_API_KEY'),
        'base_url' => env('AI_BASE_URL', 'https://api.groq.com/openai/v1'),
        'model' => env('AI_MODEL', 'openai/gpt-oss-120b'),
        // Fallback provider chain — tried when primary returns 429 (rate-limited).
        // Each entry needs api_key, base_url, and model. Currently configured for
        // GitHub Models (free with GitHub PAT).
        'fallback' => [
            [
                'api_key' => env('AI_FALLBACK_KEY'),
                'base_url' => env('AI_FALLBACK_URL', 'https://models.inference.ai.azure.com'),
                'model' => env('AI_FALLBACK_MODEL', 'gpt-4o-mini'),
            ],
        ],
    ],

    'google_custom_search' => [
        'api_key' => env('GOOGLE_CSE_API_KEY'),
        'cx' => env('GOOGLE_CSE_CX'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator notifications
    |--------------------------------------------------------------------------
    | Comma-separated list of email addresses that receive operator
    | notifications (e.g. "new user registered"). Empty = no notifications.
    | Kept separate from the user's own verification email.
    */
    'admin_notify_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ADMIN_NOTIFY_EMAILS', ''))
    ))),

];
