<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Display Timezone
    |--------------------------------------------------------------------------
    |
    | Storage stays UTC (above). This is the human-facing zone: the one CLI
    | commands read wall-clock input in and print times back in. The browser
    | converts on its own (inputs are sent as absolute ISO instants), so this
    | only affects server-side surfaces that take or show a bare "15:30".
    | Defaults to UTC, which keeps behaviour identical when unset.
    |
    */

    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Allowed Hosts
    |--------------------------------------------------------------------------
    |
    | Every request whose Host (or X-Forwarded-Host) is not APP_URL's host,
    | localhost, 127.0.0.1, [::1] or one of these is refused with a 400, in
    | every environment. Comma-separated hostnames, no scheme or port, for
    | example a LAN name the browser uses to reach this machine.
    |
    */

    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('APP_ALLOWED_HOSTS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Seeded Owner Password
    |--------------------------------------------------------------------------
    |
    | The local owner DatabaseSeeder creates. Unset, the seeder generates a
    | random password and prints it once.
    |
    */

    'seed_owner_password' => env('SEED_OWNER_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Demo Mode
    |--------------------------------------------------------------------------
    |
    | The public demo instance (demo.crm-solo.com) runs with DEMO_MODE=true:
    | demo:reset is allowed to wipe + reseed the database (scheduled nightly)
    | and the login page shows the demo credentials. Never enable this on an
    | instance holding real data.
    |
    */

    'demo' => (bool) env('DEMO_MODE', false),

    'demo_password' => env('DEMO_PASSWORD'),

    // A staging stack that runs the fictional DemoSeeder data without the rest
    // of DEMO_MODE: demo:reset may run there by hand, but nothing schedules it.
    // The command's own guard (every account is_test) still applies.
    'demo_reset_allowed' => (bool) env('DEMO_RESET_ALLOWED', false),

    // Demo visitors share one login, so uploads are capped per file and per
    // IP each hour (App\Support\UploadLimits); demo:reset deletes the files.
    'demo_uploads' => [
        'max_kb' => (int) env('DEMO_UPLOAD_MAX_KB', 2048),
        'per_hour' => (int) env('DEMO_UPLOADS_PER_HOUR', 20),
    ],

    // Every state-changing request on the demo, per IP (App\Support\DemoWriteBudget).
    'demo_writes' => [
        'per_minute' => (int) env('DEMO_WRITES_PER_MINUTE', 30),
        'per_hour' => (int) env('DEMO_WRITES_PER_HOUR', 200),
        'max_text_kb' => (int) env('DEMO_WRITE_MAX_TEXT_KB', 16),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'pl'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'pl'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'pl_PL'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
