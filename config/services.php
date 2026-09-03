<?php

declare(strict_types=1);

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

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    /*
    | Figma Personal Access Token (https://www.figma.com/developers/api#access-tokens).
    | Read scope is enough for `mcp-server/figma-fetch.mjs` to pull frame PNGs.
    | When unset, FigmaDesignReferenceProvider degrades to URL passthrough — the
    | brief still mentions the design URL, but the agent doesn't get a cached PNG.
    */
    'figma' => [
        'api_token' => env('FIGMA_API_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        // Retainer report narrative composer. gpt-4o follows the strict
        // markdown shape (h1 + hours line + h2 categories) reliably; mini
        // tends to wrap the response in JSON or add a preamble.
        'report_model' => env('OPENAI_REPORT_MODEL', 'gpt-4o'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        // Sonnet 4.6 — strong tool-selection reasoning, fluent in Polish,
        // cheaper than Opus 4.7. Override via ANTHROPIC_MODEL env if you want
        // to try `claude-opus-4-7` for harder synthesis tasks.
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
    ],

    'trello' => [
        'api_key' => env('TRELLO_API_KEY'),
        'api_token' => env('TRELLO_API_TOKEN'),
    ],

    'ai' => [
        'default_provider' => env('AI_DEFAULT_PROVIDER', 'openai'),
    ],

    /*
    | Lead pull from a WordPress site (kiwwwi:sync-leads). The endpoint is a
    | read-only mu-plugin on that site; auth is a WP Application Password for
    | a dedicated user. Note that multisite logins accept lowercase letters
    | and numbers only, so the username cannot carry a hyphen. Plain config
    | rather than an Integration row: no OAuth, no UI, one bespoke endpoint.
    */
    'kiwwwi' => [
        'leads' => [
            'base_url' => env('KIWWWI_LEADS_BASE_URL'),
            'username' => env('KIWWWI_LEADS_USERNAME'),
            'app_password' => env('KIWWWI_LEADS_APP_PASSWORD'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Infakt
    |--------------------------------------------------------------------------
    |
    | API credentials live per-account in the integrations table; this is only
    | the web-app URL used to deep-link an invoice from the CRM. The v3 API
    | returns no link for a document (just `id` and `uuid`), so the path is an
    | assumption - override it here if Infakt changes it. `{id}` is replaced
    | with the invoice's external_id.
    |
    */

    'infakt' => [
        'invoice_url' => env('INFAKT_INVOICE_URL', 'https://app.infakt.pl/app/faktury/{id}'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile
    |--------------------------------------------------------------------------
    |
    | Bot protection on the login form. The sitekey is public by design and
    | ships with a default; the secret comes exclusively from the environment
    | (TURNSTILE_SECRET) and is never committed. The whole gate - widget and
    | server-side siteverify - activates only when the secret is set, so local
    | dev and CI run without it.
    |
    */

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY', '0x4AAAAAAEDvi_D_T2D6Hxfn'),
        'secret' => env('TURNSTILE_SECRET'),
    ],

];
