<?php

declare(strict_types=1);

use App\Mcp\Servers\CrmServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Servers
|--------------------------------------------------------------------------
|
| The crm verb contract, reachable by clients that have no shell (Claude
| Desktop, claude.ai). Local = stdio, launched by the client itself via
| `php artisan mcp:start crm-solo`. A hosted transport swaps this for Mcp::web
| with auth middleware; the tools stay untouched.
|
*/

Mcp::local('crm-solo', CrmServer::class);
