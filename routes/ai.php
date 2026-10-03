<?php

declare(strict_types=1);

use App\Http\Controllers\Agent\AttachmentDownloadController;
use App\Http\Controllers\Agent\RemoteVerbController;
use App\Mcp\Servers\CrmServer;
use App\Services\Agent\AgentCall;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| Agent transports
|--------------------------------------------------------------------------
|
| The crm verb contract, three ways in, one set of services:
| - local stdio MCP (`php artisan mcp:start crm-solo`), launched by the client;
| - MCP over HTTPS at /mcp, for agents reaching a hosted CRM;
| - the verb endpoint /agent/verb, which `bin/crm` calls in remote mode,
|   and /agent/attachments/{id}, a task's file for an agent off the server.
| The two HTTP routes take a personal access token (agent-tokens:issue) and
| nothing else: no session, no CSRF. The token decides account and abilities.
|
*/

Mcp::local('crm-solo', CrmServer::class);

// The group also covers the GET and DELETE routes Mcp::web() registers beside the POST it returns.
Route::middleware('not-in-demo')->group(function () {
    Mcp::web('/mcp', CrmServer::class)
        ->middleware(['agent.token:'.AgentCall::VIA_MCP_WEB, 'throttle:agent'])
        ->name('mcp.crm');

    Route::post('/agent/verb', RemoteVerbController::class)
        ->middleware(['agent.token:'.AgentCall::VIA_CLI_REMOTE, 'throttle:agent'])
        ->name('agent.verb');

    Route::get('/agent/attachments/{attachment}', AttachmentDownloadController::class)
        ->whereNumber('attachment')
        ->middleware(['agent.token:'.AgentCall::VIA_CLI_REMOTE, 'throttle:agent'])
        ->name('agent.attachment');
});
