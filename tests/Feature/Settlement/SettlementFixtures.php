<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientMonthClosure;
use App\Models\ClientMonthSettlementEvent;
use App\Models\ClientReport;
use App\Models\ClientRetainer;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

trait SettlementFixtures
{
    protected Account $account;

    protected User $user;

    protected Client $client;

    protected Project $project;

    protected Task $task;

    protected function setUpSettlement(string $baseline = '2026-09', string $nowUtc = '2026-10-20 10:00:00'): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(Carbon::parse($nowUtc, 'UTC'));

        $this->account = Account::create(['name' => 'Acme', 'settings' => ['settlement_from' => $baseline]]);
        $this->user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = $this->account->clients()->create(['name' => 'Acme', 'currency' => 'PLN']);
        $this->project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Site']);
        $this->task = Task::create(['project_id' => $this->project->id, 'name' => 'Feature', 'is_reportable' => true]);
    }

    protected function retainer(float $hours, string $from, ?string $to = null, ?float $cap = null, ?Client $client = null, bool $active = true): ClientRetainer
    {
        $client ??= $this->client;

        return ClientRetainer::create([
            'account_id' => $client->account_id,
            'client_id' => $client->id,
            'monthly_hours' => $hours,
            'rollover_cap_hours' => $cap,
            'effective_from' => $from,
            'effective_to' => $to,
            'is_active' => $active,
            'currency' => 'PLN',
        ]);
    }

    protected function report(string $month, float $opening, ?float $contracted, float $actual, string $status = ClientReport::STATUS_DRAFT, ?Client $client = null, ?string $start = null, ?string $end = null, ?float $cap = null, string $type = ClientReport::PERIOD_MONTH): ClientReport
    {
        $first = Carbon::parse($month.'-01');
        $client ??= $this->client;

        return ClientReport::create([
            'account_id' => $client->account_id,
            'client_id' => $client->id,
            'period_type' => $type,
            'status' => $status,
            'period_start' => $start ?? $first->toDateString(),
            'period_end' => $end ?? $first->copy()->endOfMonth()->toDateString(),
            'contracted_hours' => $contracted,
            'actual_hours' => $actual,
            'opening_balance_hours' => $opening,
            'rollover_cap_hours' => $cap,
            'currency' => 'PLN',
            'composer_key' => 'structured_list',
            'body_markdown' => "# {$month}\n\nWork done.",
            'generated_at' => now(),
            'finalized_at' => $status !== ClientReport::STATUS_DRAFT ? now() : null,
            'sent_at' => $status === ClientReport::STATUS_SENT ? now() : null,
        ]);
    }

    protected function entry(string $startUtc, int $minutes, bool $reportable = true, ?Client $client = null, bool $running = false): TimeEntry
    {
        $start = Carbon::parse($startUtc, 'UTC');
        $client ??= $this->client;

        return TimeEntry::create([
            'account_id' => $client->account_id,
            'project_id' => $this->project->id,
            'client_id' => $client->id,
            'task_id' => $reportable ? $this->task->id : null,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => $start,
            'end_time' => $running ? null : $start->copy()->addMinutes($minutes),
            'duration_minutes' => $running ? 0 : $minutes,
        ]);
    }

    /** A closure written straight to the table, with its close event, for tests that only read settlement state. */
    protected function closure(string $period, float $opening, ?float $contracted, float $used, ?float $cap = null, ?Client $client = null): ClientMonthClosure
    {
        $client ??= $this->client;
        $available = \App\Services\Clients\HoursBank::available($opening, $contracted ?? 0.0, $cap);
        $snapshot = [
            'schema_version' => ClientMonthClosure::SNAPSHOT_SCHEMA_VERSION,
            'period' => $period,
            'eligible' => true,
            'source' => 'entries',
            'report_id' => null,
            'report_status' => null,
            'contracted' => $contracted,
            'opening' => $opening,
            'cap' => $cap,
            'available' => $available,
            'used' => $used,
            'forfeited' => \App\Services\Clients\HoursBank::forfeited($opening, $contracted ?? 0.0, $cap),
            'closing' => \App\Services\Clients\HoursBank::closing($available, $used),
            'logged' => $used,
        ];
        $closure = ClientMonthClosure::create(['client_id' => $client->id, 'period' => $period, 'closed_at' => now(), 'snapshot' => $snapshot]);
        ClientMonthSettlementEvent::create([
            'client_id' => $client->id, 'period' => $period, 'action' => ClientMonthSettlementEvent::ACTION_CLOSE,
            'closure_id' => $closure->id, 'snapshot' => $snapshot, 'created_at' => now(),
        ]);

        return $closure;
    }
}
