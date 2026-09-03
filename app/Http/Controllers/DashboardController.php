<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

final class DashboardController extends Controller
{
    /**
     * Agent-first dashboard: the daily-session card (herdr states) is the
     * surface. The old "need your attention" task widget was removed
     * 2026-07-29 — attention flows from `crm today` and the session logs.
     */
    public function index()
    {
        $accountId = Auth::user()->account_id;

        return Inertia::render('dashboard', [
            // Live herdr agent states, client-mapped — the card polls this
            // via partial reloads.
            'dailySession' => fn () => app(\App\Services\DailySessionService::class)->snapshot($accountId),
        ]);
    }
}
