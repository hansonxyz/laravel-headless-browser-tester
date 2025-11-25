<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL used when testing routes. If null, defaults to APP_URL.
    |
    */
    'base_url' => env('HEADLESS_TESTER_BASE_URL', null),

    /*
    |--------------------------------------------------------------------------
    | Default Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time in milliseconds to wait for page load and network idle.
    |
    */
    'timeout' => env('HEADLESS_TESTER_TIMEOUT', 30000),

    /*
    |--------------------------------------------------------------------------
    | Screenshot Defaults
    |--------------------------------------------------------------------------
    |
    | Default settings for screenshot capture.
    |
    */
    'screenshot' => [
        'width' => 1920,
        'height' => 1080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Device Presets
    |--------------------------------------------------------------------------
    |
    | Named device configurations for responsive testing.
    |
    */
    'devices' => [
        'mobile' => ['width' => 375, 'height' => 667],
        'tablet' => ['width' => 768, 'height' => 1024],
        'desktop' => ['width' => 1920, 'height' => 1080],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Model
    |--------------------------------------------------------------------------
    |
    | The model class used for user authentication when using --user option.
    |
    */
    'user_model' => env('HEADLESS_TESTER_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Name
    |--------------------------------------------------------------------------
    |
    | The name of the Laravel session cookie.
    |
    */
    'session_cookie' => env('SESSION_COOKIE', 'laravel_session'),
];
