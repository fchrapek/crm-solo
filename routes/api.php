<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SessionEventsController;
use Illuminate\Support\Facades\Route;

// Per-task session events posted by the CLI's hook (claude `Notification` etc.)
// Authenticated by the per-session token in the URL — generated in
// TerminalSessionLauncher, rotated on every launch, cleared on stop. No web
// session, no CSRF — the caller is curl from inside the worktree's hook script.
Route::post('/session-events/{token}', [SessionEventsController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('api.session-events.store');
