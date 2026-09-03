<?php

declare(strict_types=1);

use App\Models\Integration;
use Illuminate\Support\Facades\Schedule;

// Every integration pull is gated on that integration actually being
// configured — a fresh install must not error its scheduler tick to death
// on services it never connected. The `when()` closure runs at tick time,
// so connecting an integration starts its sync without a deploy.
$integrationConfigured = fn (string $provider): Closure => fn (): bool => Integration::query()->where('provider', $provider)->exists();

// demo:reset wipes + reseeds the whole database — the command itself refuses
// to run unless DEMO_MODE=true, and it is only scheduled under that flag so
// a visitor-trashed demo self-heals overnight.
if (config('app.demo')) {
    Schedule::command('demo:reset')->dailyAt('03:30');
}

Schedule::command('trello:sync --force --sync')->everyFiveMinutes()->when($integrationConfigured('trello'));
Schedule::command('clockify:sync --force --sync')->everyFifteenMinutes()->when($integrationConfigured('clockify'));
// Lead pull from the marketing site. Runs only when KIWWWI_LEADS_* are set,
// which means a dedicated read-only user and its application password.
// Same accepted trade-off as every pull above: leads land only while the
// local stack + scheduler run; the email notification is the real-time channel.
Schedule::command('kiwwwi:sync-leads')->everyFifteenMinutes()->when(fn (): bool => config('services.kiwwwi.leads.app_password') !== null);
Schedule::command('db:backup')->dailyAt('02:00');
Schedule::command('infakt:sync-invoices')->dailyAt('03:00')->when($integrationConfigured('infakt'));

// Reap sessions whose ttyd died without a Stop (reboot/crash) — otherwise the
// auto-opened billable TimeEntry accrues until someone notices. Five-minute
// cadence bounds the billing drift on a crashed session to five minutes.
Schedule::command('sessions:reap')->everyFiveMinutes();
