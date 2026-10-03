<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Terminal session host
    |--------------------------------------------------------------------------
    |
    | The host the BROWSER uses to reach ttyd iframes (terminal sessions +
    | task previews). ttyd binds 127.0.0.1 on the machine running PHP, so
    | this only needs changing when the browser and server are different
    | machines (LAN box, VPS) — set it to the server's hostname/IP there.
    | Remote setups also need the ttyd ports reachable (firewall/tunnel);
    | see docs/development/terminal-sessions.md.
    |
    */

    'session_host' => env('TERMINAL_SESSION_HOST', 'localhost'),

    /*
    |--------------------------------------------------------------------------
    | Host execution
    |--------------------------------------------------------------------------
    |
    | Whether the app may run processes on this machine: terminal sessions,
    | task previews, repository git calls and the daily-session viewport.
    | Always off under DEMO_MODE (App\Support\HostExec), where every visitor
    | is logged in.
    |
    */

    'host_exec' => (bool) env('CRM_HOST_EXEC', true) && ! (bool) env('DEMO_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Daily session (herdr)
    |--------------------------------------------------------------------------
    |
    | Command the dashboard's daily-session card attaches to (browser ttyd
    | viewport) and reads agent states from. Any terminal agent multiplexer
    | with its own persistence works; herdr is the default. daily_cwd is the
    | working directory the viewport shell starts in.
    |
    */

    'daily_command' => env('DAILY_SESSION_COMMAND', 'herdr'),
    'daily_cwd' => env('DAILY_SESSION_CWD'),

];
