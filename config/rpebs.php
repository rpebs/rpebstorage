<?php

return [

    /*
    | Quota usage percent at which an account is flagged on the dashboard.
    */
    'quota_warning_percent' => env('QUOTA_WARNING_PERCENT', 90),

    /*
    | Telegram (MTProto) credentials from my.telegram.org.
    */
    'telegram' => [
        'api_id' => env('TELEGRAM_API_ID'),
        'api_hash' => env('TELEGRAM_API_HASH'),
        'session_disk' => 'local',
        'session_path' => 'telegram-sessions',
        // Max size of a single MTProto upload before splitting (1.5 GB).
        'chunk_size' => env('TELEGRAM_CHUNK_SIZE', 1024 * 1024 * 1024 * 1.5),
        // Seconds to wait between uploads per account (FLOOD_WAIT mitigation).
        'upload_delay' => env('TELEGRAM_UPLOAD_DELAY', 2),
        // Run telegram:listen daemon automatically during dev runner (composer dev)
        'dev_daemon' => env('TELEGRAM_DEV_DAEMON', (bool) (env('TELEGRAM_API_ID') && env('TELEGRAM_API_HASH'))),
    ],

    /*
    | OAuth client credentials for the cloud providers.
    */
    'oauth' => [
        'google_drive' => [
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect' => '/accounts/callback/google_drive',
        ],
        'dropbox' => [
            'client_id' => env('DROPBOX_CLIENT_ID'),
            'client_secret' => env('DROPBOX_CLIENT_SECRET'),
            'redirect' => '/accounts/callback/dropbox',
        ],
        'onedrive' => [
            'client_id' => env('MICROSOFT_CLIENT_ID'),
            'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
            'redirect' => '/accounts/callback/onedrive',
        ],
    ],

    /*
    | Local temp dir (inside storage/app) for uploads/downloads in flight.
    */
    'temp_disk' => 'local',
    'temp_path' => 'temp',

    /*
    | Thumbnail cache directory and dimensions.
    */
    'thumbnails' => [
        'disk' => 'local',
        'path' => 'thumbnails',
        'max_width' => 320,
        'max_height' => 320,
        'quality' => 80,
    ],

    /*
    | Preview cache directory for streaming video, audio, pdf, and images.
    */
    'preview_cache' => [
        'disk' => 'local',
        'path' => 'preview_cache',
        'max_age_hours' => 24,
    ],

    // pickBestAccount: an unlimited account is only used when no quota-limited
    // account has at least this many bytes free.
    'unlimited_fallback_threshold' => 1024 * 1024 * 1024,
];
