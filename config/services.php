<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gmail' => [
        'client_id' => env('GOOGLE_GMAIL_CLIENT_ID'),
        'client_secret' => env('GOOGLE_GMAIL_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_GMAIL_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/settings/connections/gmail/callback'),
        'oauth_publishing_status' => env('GOOGLE_GMAIL_OAUTH_PUBLISHING_STATUS', 'testing'),
    ],

    'ai_classifier' => [
        'url' => env('AI_CLASSIFIER_URL'),
        'token' => env('AI_CLASSIFIER_TOKEN'),
        'version' => env('AI_CLASSIFIER_VERSION'),
    ],

    'openclaw' => [
        'launcher_url' => env('OPENCLAW_LAUNCHER_URL'),
        'capability' => [
            'key_id' => env('OPENCLAW_CAPABILITY_KEY_ID'),
            'public_key' => env('OPENCLAW_CAPABILITY_PUBLIC_KEY'),
            'agent_id' => env('OPENCLAW_CAPABILITY_AGENT_ID'),
            'account_id' => env('OPENCLAW_CAPABILITY_ACCOUNT_ID'),
            'conversation_id' => env('OPENCLAW_CAPABILITY_CONVERSATION_ID'),
            'owner_sender_id' => env('OPENCLAW_CAPABILITY_OWNER_SENDER_ID'),
            'rate_limit_per_minute' => env('OPENCLAW_CAPABILITY_RATE_LIMIT_PER_MINUTE', 60),
        ],
        'hook' => [
            'url' => env('OPENCLAW_HOOK_URL'),
            'token' => env('OPENCLAW_HOOK_TOKEN'),
        ],
    ],

];
