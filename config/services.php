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
        // Optional fallback provider chain — tried when the primary is
        // rate-limited (429), returns a 5xx, or is unreachable. An entry is used
        // only when it has an api_key, base_url and model (OpenAI-compatible).
        // None is configured by default: the free option (GitHub Models) was
        // retired on 2026-07-30, and a paid provider is never a default.
        'fallback' => [
            [
                'api_key' => env('AI_FALLBACK_KEY'),
                'base_url' => env('AI_FALLBACK_URL'),
                'model' => env('AI_FALLBACK_MODEL'),
            ],
        ],
        // restaurants:ai-enrich queues at most this many jobs per 6-hourly
        // run, spread over the 6 hours. Groq's free tier caps gpt-oss-120b at
        // 200k tokens a day (~350 enrichments), shared with the ingestion,
        // grid and hygiene dispatches; anything more is queued to fail.
        'enrich_per_run' => (int) env('AI_ENRICH_PER_RUN', 75),
        // A row the AI already tried becomes eligible again after this many
        // days (the model has no browsing, so a quick retry repeats itself).
        'enrich_retry_days' => (int) env('AI_ENRICH_RETRY_DAYS', 30),
    ],

    'google_custom_search' => [
        'api_key' => env('GOOGLE_CSE_API_KEY'),
        'cx' => env('GOOGLE_CSE_CX'),
        // Queries per UTC day; the free tier is 100.
        'daily_cap' => (int) env('GOOGLE_CSE_DAILY_CAP', 90),
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
