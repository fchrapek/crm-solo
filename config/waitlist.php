<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Closed mode and the waitlist
|--------------------------------------------------------------------------
|
| With APP_CLOSED=true guests never reach the app: every page that needs a
| login sends them to the waitlist splash, where an email becomes a lead in
| the account named by WAITLIST_ACCOUNT_ID. No user or account is created
| from it, and /login keeps working for existing users. Off by default, so
| local dev and the public demo behave as before.
*/

return [

    'closed' => (bool) env('APP_CLOSED', false),

    // The account whose funnel receives waitlist leads. Required when closed.
    'account_id' => env('WAITLIST_ACCOUNT_ID'),

    // Pipeline slug from config/leadgen.php; empty means the first one.
    'pipeline' => env('WAITLIST_PIPELINE'),

    // Source slug the leads carry; it must be listed in leadgen.sources.
    'source' => 'waitlist',

    // Submissions per visitor IP.
    'per_minute' => (int) env('WAITLIST_PER_MINUTE', 5),
    'per_hour' => (int) env('WAITLIST_PER_HOUR', 20),

];
