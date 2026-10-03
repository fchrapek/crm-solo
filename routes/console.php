<?php

declare(strict_types=1);

use App\Models\Integration;
use Illuminate\Support\Facades\Schedule;

// Every integration pull is gated on that integration actually being
// configured — a fresh install must not error its scheduler tick to death
// on services it never connected. The `when()` closure runs at tick time,
// so connecting an integration starts its sync without a deploy.
$integrationConfigured = fn (string $provider): Closure => fn (): bool => Integration::query()->where('provider', $provider)->exists();

// demo:reset rebuilds the database; it runs only under DEMO_MODE, so the demo heals overnight.
if (config('app.demo')) {
    Schedule::command('demo:reset')->dailyAt('03:30');
}

// Integrations are not part of the public demo, so nothing there pulls from a third party.
if (! config('app.demo')) {
    Schedule::command('trello:sync --force --sync')->everyFiveMinutes()->when($integrationConfigured('trello'));
    // Leads land only while the scheduler runs; the email notification is the real-time channel.
    Schedule::command('kiwwwi:sync-leads')->everyFifteenMinutes()->withoutOverlapping(10)->when(fn (): bool => config('services.kiwwwi.leads.app_password') !== null);
    Schedule::command('infakt:sync-invoices')->dailyAt('03:00')->when($integrationConfigured('infakt'));
}
// Local installs dump nightly; a hosted stack opts in with BACKUP_SCHEDULE_AT (UTC, the scheduler's zone) and ships the dumps off the box itself.
$backupAt = config('backup.schedule_at') ?: (app()->isLocal() ? '02:00' : null);
if (is_string($backupAt)) {
    // dailyAt() casts each part to int, so '0230' would become hour 230 and break every scheduler tick, not just this one.
    if (preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $backupAt) !== 1) {
        throw new InvalidArgumentException("BACKUP_SCHEDULE_AT must be HH:MM in UTC, got '{$backupAt}'.");
    }
    Schedule::command('db:backup')->dailyAt($backupAt);
}

// Reap sessions whose ttyd died without a Stop (reboot/crash) — otherwise the
// auto-opened billable TimeEntry accrues until someone notices. Five-minute
// cadence bounds the billing drift on a crashed session to five minutes.
Schedule::command('sessions:reap')->everyFiveMinutes();
