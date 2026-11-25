<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientFieldEvent;
use App\Models\ClientMonthClosure;
use App\Models\ClientMonthSettlementEvent;
use App\Models\ClientReport;
use App\Models\ClientRetainer;
use App\Models\DayPick;
use App\Models\MessageRule;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskBilledMonth;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Clients\Fields\ClientFieldDefinitions;
use App\Services\Clients\Fields\ClientFieldWriter;
use App\Services\Clients\LoggedTimeFreeze;
use App\Services\Clients\MonthSettlement;
use App\Services\Clients\RetainerTerms;
use App\Services\Clients\SettlementOutcome;
use App\Services\Day\DayPlanner;
use App\Services\Day\PlanBusy;
use App\Services\Messaging\MessageDispatcher;
use App\Services\Reports\Composers\StructuredListComposer;
use App\Services\Reports\ReportComposerRegistry;
use App\Services\Reports\ReportStatusTransition;
use App\Services\Reports\ReportWriter;
use App\Services\Time\PeriodSettled;
use App\Support\JsonSettings;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PDO;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Real row locks, two connections, on a MariaDB database of its own. SQLite has no row locks, so the suite proves
 * the sequential guards and this class proves that every financial write waits on the client row another writer holds.
 *
 * Skipped unless SETTLEMENT_LOCK_DB_DATABASE names a throwaway database (never crm_solo or crm_solo_demo); host, port,
 * user and password come from SETTLEMENT_LOCK_DB_HOST/PORT/USERNAME/PASSWORD. The database is migrated fresh per test.
 */
final class MariaDbLockingTest extends TestCase
{
    private const array PROTECTED_DATABASES = ['crm_solo', 'crm_solo_demo', 'crm_solo_frozen_do_not_use'];

    /** @var list<PDO> */
    private static array $inherited = [];

    private mixed $firstAnswer = null;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $database = (string) getenv('SETTLEMENT_LOCK_DB_DATABASE');
        if ($database === '') {
            $this->markTestSkipped('Set SETTLEMENT_LOCK_DB_DATABASE to a throwaway MariaDB database to run the locking tests.');
        }
        if (in_array($database, self::PROTECTED_DATABASES, true)) {
            $this->fail("Refusing to migrate {$database} fresh: point SETTLEMENT_LOCK_DB_DATABASE at a throwaway database.");
        }

        $connection = [
            'driver' => 'mariadb',
            'host' => getenv('SETTLEMENT_LOCK_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('SETTLEMENT_LOCK_DB_PORT') ?: '33061',
            'database' => $database,
            'username' => (string) getenv('SETTLEMENT_LOCK_DB_USERNAME'),
            'password' => (string) getenv('SETTLEMENT_LOCK_DB_PASSWORD'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
        config([
            'database.connections.lock_writer' => $connection,
            'database.connections.lock_holder' => $connection,
            'database.default' => 'lock_writer',
            'app.display_timezone' => 'Europe/Warsaw',
        ]);
        DB::purge('lock_writer');
        DB::purge('lock_holder');
        Artisan::call('migrate:fresh', ['--database' => 'lock_writer', '--force' => true]);

        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'UTC'));
        $account = Account::create(['name' => 'Locks', 'settings' => ['settlement_from' => '2026-09']]);
        $this->user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $this->client = $account->clients()->create(['name' => 'Locked', 'currency' => 'PLN']);
        ClientRetainer::create(['account_id' => $account->id, 'client_id' => $this->client->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01', 'currency' => 'PLN']);

        $this->app->bind(ReportComposerRegistry::class, function ($app): ReportComposerRegistry {
            $registry = new ReportComposerRegistry;
            $registry->register($app->make(StructuredListComposer::class), default: true);

            return $registry;
        });
        DB::connection('lock_writer')->statement('SET SESSION innodb_lock_wait_timeout = 1');
        // As on MariaDB 11.8 in production, where it is on by default.
        DB::connection('lock_writer')->statement('SET SESSION innodb_snapshot_isolation = ON');
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'lock_writer') {
            DB::connection('lock_holder')->rollBack();
            DB::disconnect('lock_holder');
            DB::disconnect('lock_writer');
        }
        parent::tearDown();
    }

    public function test_a_close_waits_on_the_client_row_another_writer_holds(): void
    {
        $settlement = app(MonthSettlement::class);
        $hash = $settlement->preview($this->client, '2026-09')['hash'];

        $this->whileHolding('clients', $this->client->id, fn () => $settlement->close($this->client, '2026-09', $hash, $this->user));

        $this->assertSame(0, ClientMonthClosure::query()->count());
        $this->assertSame(SettlementOutcome::Applied, $settlement->close($this->client, '2026-09', $hash, $this->user)->outcome, 'once the row is free the close goes through');
    }

    public function test_a_report_composed_outside_the_lock_still_waits_to_write(): void
    {
        $this->whileHolding('clients', $this->client->id, fn () => app(ReportWriter::class)->generate(
            $this->client, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), 'en', 'structured_list',
        ));

        $this->assertSame(0, ClientReport::query()->count());
    }

    public function test_a_status_move_on_an_archived_clients_report_waits_on_its_client_row(): void
    {
        $report = $this->client->reports()->create([
            'account_id' => $this->client->account_id, 'period_type' => 'month', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'contracted_hours' => 10, 'actual_hours' => 2, 'opening_balance_hours' => 0, 'currency' => 'PLN', 'composer_key' => 'structured_list',
            'body_markdown' => 'x', 'status' => ClientReport::STATUS_FINALIZED, 'finalized_at' => now(),
        ]);
        $this->client->delete();

        $this->whileHolding('clients', $this->client->id, fn () => app(ReportStatusTransition::class)->markSent($report, $this->user));

        $this->assertNull($report->fresh()->sent_at);
    }

    public function test_the_report_row_is_taken_after_the_client_row(): void
    {
        $report = $this->client->reports()->create([
            'account_id' => $this->client->account_id, 'period_type' => 'month', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'contracted_hours' => 10, 'actual_hours' => 2, 'opening_balance_hours' => 0, 'currency' => 'PLN', 'composer_key' => 'structured_list',
            'body_markdown' => 'x', 'status' => ClientReport::STATUS_DRAFT,
        ]);

        $this->whileHolding('client_reports', $report->id, fn () => app(ReportWriter::class)->updateBody($report, 'edited', $report->version(), $this->user));

        $this->assertSame('x', $report->fresh()->body_markdown);
    }

    public function test_a_retainer_change_waits_on_the_client_row(): void
    {
        $this->whileHolding('clients', $this->client->id, fn () => app(RetainerTerms::class)->change($this->client, fn (Client $client) => $client->retainers()->update(['label' => 'changed'])));

        $this->assertNull(ClientRetainer::query()->sole()->label);
    }

    public function test_generate_queued_behind_a_close_is_refused_on_its_revalidated_figures(): void
    {
        $settlement = app(MonthSettlement::class);
        $hash = $settlement->preview($this->client, '2026-09')['hash'];

        $second = $this->interleave(
            fn () => $settlement->close($this->client, '2026-09', $hash, $this->user),
            fn (): array => self::outcome(app(ReportWriter::class)->generate($this->client, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), 'en', 'structured_list')),
        );

        $this->assertSame(['refused', 'The figures changed, try again.'], $second);
        $this->assertSame(0, ClientReport::query()->count());
        $this->assertSame(1, ClientMonthClosure::query()->count());
        $this->assertSame(1, ClientMonthSettlementEvent::query()->count());
    }

    public function test_a_close_queued_behind_generate_is_refused_on_its_preview_hash(): void
    {
        $hash = app(MonthSettlement::class)->preview($this->client, '2026-09')['hash'];

        $second = $this->interleave(
            fn () => app(ReportWriter::class)->generate($this->client, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), 'en', 'structured_list'),
            fn (): array => self::outcome(app(MonthSettlement::class)->close($this->client, '2026-09', $hash, $this->user)),
        );

        $this->assertSame(['refused', 'The figures changed, check them again.'], $second);
        $this->assertSame(1, ClientReport::query()->count());
        $this->assertSame(0, ClientMonthClosure::query()->count());
        $this->assertSame(0, ClientMonthSettlementEvent::query()->count());
    }

    public function test_regenerate_queued_behind_a_send_saves_nothing(): void
    {
        $report = $this->septemberReport(ClientReport::STATUS_FINALIZED);

        $second = $this->interleave(
            fn () => app(ReportStatusTransition::class)->markSent($report, $this->user),
            fn (): array => self::outcome(app(ReportWriter::class)->regenerate($report, 'en', 'structured_list', by: $this->user)),
        );

        $this->assertSame('refused', $second[0]);
        $fresh = $report->fresh();
        $this->assertSame(ClientReport::STATUS_SENT, $fresh->status);
        $this->assertSame('seed body', $fresh->body_markdown);
        $this->assertSame(['sent'], $fresh->revisions->pluck('reason')->all());
    }

    public function test_a_send_queued_behind_regenerate_sends_the_regenerated_report(): void
    {
        $report = $this->septemberReport(ClientReport::STATUS_FINALIZED);

        $second = $this->interleave(
            fn () => app(ReportWriter::class)->regenerate($report, 'en', 'structured_list', by: $this->user),
            fn (): array => self::outcome(app(ReportStatusTransition::class)->markSent($report, $this->user)),
        );

        $this->assertSame(['applied', 'Report regenerated.'], self::outcome($this->firstAnswer));
        $this->assertSame('applied', $second[0]);
        $fresh = $report->fresh();
        $this->assertSame(ClientReport::STATUS_SENT, $fresh->status);
        $this->assertNotSame('seed body', $fresh->body_markdown);
        $this->assertSame(['sent', 'regenerate'], $fresh->revisions->pluck('reason')->all());
    }

    public function test_a_close_queued_behind_a_later_reports_finalize_is_refused(): void
    {
        $october = $this->report('2026-10-01', '2026-10-31', ClientReport::STATUS_DRAFT);
        $hash = app(MonthSettlement::class)->preview($this->client, '2026-09')['hash'];

        $second = $this->interleave(
            fn () => app(ReportStatusTransition::class)->finalize($october, $this->user),
            fn (): array => self::outcome(app(MonthSettlement::class)->close($this->client, '2026-09', $hash, $this->user)),
        );

        $this->assertSame(['refused', 'The report for October 2026 is already finalized or sent; reopen it first.'], $second);
        $this->assertSame(0, ClientMonthClosure::query()->count());
        $this->assertSame(ClientReport::STATUS_FINALIZED, $october->fresh()->status);
    }

    public function test_a_later_reports_finalize_queued_behind_a_close_is_refused_on_the_flag(): void
    {
        $october = $this->report('2026-10-01', '2026-10-31', ClientReport::STATUS_DRAFT);
        $hash = app(MonthSettlement::class)->preview($this->client, '2026-09')['hash'];

        $second = $this->interleave(
            fn () => app(MonthSettlement::class)->close($this->client, '2026-09', $hash, $this->user),
            fn (): array => self::outcome(app(ReportStatusTransition::class)->finalize($october, $this->user)),
        );

        $this->assertSame(['refused', 'Regenerate this report with the opening balance recalculated first: an earlier month changed.'], $second);
        $this->assertSame(1, ClientMonthClosure::query()->count());
        $fresh = $october->fresh();
        $this->assertSame(ClientReport::STATUS_DRAFT, $fresh->status);
        $this->assertTrue($fresh->needs_recalculation);
        $this->assertCount(0, $fresh->revisions);
    }

    public function test_a_close_queued_behind_its_own_reports_send_freezes_the_sent_report(): void
    {
        $report = $this->septemberReport(ClientReport::STATUS_FINALIZED);
        $hash = app(MonthSettlement::class)->preview($this->client, '2026-09')['hash'];

        $second = $this->interleave(
            fn () => app(ReportStatusTransition::class)->markSent($report, $this->user),
            fn (): array => self::outcome(app(MonthSettlement::class)->close($this->client, '2026-09', $hash, $this->user)),
        );

        $this->assertSame('applied', $second[0]);
        $snapshot = ClientMonthClosure::query()->sole()->snapshot;
        $this->assertSame(ClientReport::STATUS_SENT, $snapshot['report_status']);
        $this->assertEquals(2, $snapshot['used']);
    }

    public function test_a_send_queued_behind_its_months_close_still_goes_out(): void
    {
        $report = $this->septemberReport(ClientReport::STATUS_FINALIZED);
        $hash = app(MonthSettlement::class)->preview($this->client, '2026-09')['hash'];

        $second = $this->interleave(
            fn () => app(MonthSettlement::class)->close($this->client, '2026-09', $hash, $this->user),
            fn (): array => self::outcome(app(ReportStatusTransition::class)->markSent($report, $this->user)),
        );

        $this->assertSame('applied', $second[0]);
        $this->assertSame(ClientReport::STATUS_FINALIZED, ClientMonthClosure::query()->sole()->snapshot['report_status']);
        $this->assertSame(ClientReport::STATUS_SENT, $report->fresh()->status);
    }

    public function test_a_timer_start_waits_on_the_client_row(): void
    {
        $task = $this->task();

        $this->whileHolding('clients', $this->client->id, fn () => TimeEntry::startFor($task));

        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_a_move_inside_one_client_waits_on_the_client_row_before_its_time_moves(): void
    {
        $task = $this->task();
        $entry = TimeEntry::create([
            'account_id' => $this->client->account_id, 'project_id' => $task->project_id, 'client_id' => $this->client->id, 'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => '2026-10-05 08:00:00', 'end_time' => '2026-10-05 09:00:00', 'duration_minutes' => 60,
        ]);
        $gallery = Project::create(['account_id' => $this->client->account_id, 'client_id' => $this->client->id, 'name' => 'Gallery']);

        $this->whileHolding('clients', $this->client->id, fn () => app(\App\Services\Agent\TaskEditor::class)
            ->edit((int) $this->client->account_id, (string) $task->id, ['project' => (string) $gallery->id, 'move_time' => true, 'within_client' => true]));

        $this->assertSame($task->project_id, (int) $entry->fresh()->project_id);
    }

    public function test_a_timer_start_queued_behind_a_finalize_of_today_is_refused(): void
    {
        $october = $this->report('2026-10-01', '2026-10-31', ClientReport::STATUS_DRAFT);
        $task = $this->task();

        $second = $this->interleave(
            fn () => app(ReportStatusTransition::class)->finalize($october, $this->user),
            function () use ($task): array {
                try {
                    TimeEntry::startFor($task);

                    return ['started', ''];
                } catch (PeriodSettled $settled) {
                    return ['refused', $settled->reason.' '.$settled->month];
                }
            },
        );

        $this->assertSame(ClientReport::STATUS_FINALIZED, $october->fresh()->status);
        $this->assertSame(['refused', LoggedTimeFreeze::REPORTED.' 2026-10'], $second);
        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_a_finalize_queued_behind_a_timer_start_is_refused_on_the_running_timer(): void
    {
        $october = $this->report('2026-10-01', '2026-10-31', ClientReport::STATUS_DRAFT);
        $task = $this->task();

        $second = $this->interleave(
            fn () => TimeEntry::startFor($task),
            fn (): array => self::outcome(app(ReportStatusTransition::class)->finalize($october, $this->user)),
        );

        $this->assertSame(['refused', "Stop the timer running in this report's period first."], $second);
        $this->assertSame(ClientReport::STATUS_DRAFT, $october->fresh()->status);
        $this->assertNull(TimeEntry::query()->sole()->end_time);
    }

    public function test_a_start_that_loses_the_race_for_its_request_id_returns_the_winner(): void
    {
        $task = $this->task()->load('project');
        $request = '7c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f';
        $raced = false;
        TimeEntry::creating(function (TimeEntry $entry) use (&$raced, $request, $task): void {
            if ($raced) {
                return;
            }
            $raced = true;
            DB::connection('lock_holder')->table('time_entries')->insert([
                'account_id' => $task->project->account_id, 'project_id' => $task->project_id, 'client_id' => $task->project->client_id, 'task_id' => $task->id,
                'source' => TimeEntry::SOURCE_MANUAL, 'title' => 'Work', 'description' => 'Work', 'start_time' => now(), 'duration_minutes' => 0, 'billable' => true,
                'start_request_id' => $request, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $entry = TimeEntry::startFor($task, requestId: $request);

        $this->assertFalse($entry->wasRecentlyCreated);
        $this->assertSame($request, $entry->start_request_id);
        $this->assertSame(1, TimeEntry::query()->count());
    }

    public function test_a_second_move_of_a_task_waits_for_the_first_and_moves_it_from_where_the_first_put_it(): void
    {
        $planner = app(DayPlanner::class);
        $task = $this->task();
        $accountId = (int) $this->client->account_id;
        $today = $planner->today();
        $planner->pick($accountId, $today, $task->id);
        $live = fn (): array => DayPick::query()->live()->where('task_id', $task->id)->orderBy('date')->get()->map(fn (DayPick $pick): string => $pick->date->toDateString())->all();

        $second = $this->interleave(
            fn () => $planner->pick($accountId, $today->addDay(), $task->id),
            function () use ($planner, $accountId, $today, $task, $live): array {
                $planner->pick($accountId, $today->addDays(2), $task->id);

                return $live();
            },
            'day_plan_locks',
        );

        $this->assertSame([$today->addDays(2)->toDateString()], $second);
        $this->assertSame([$today->addDays(2)->toDateString()], $live());
        $this->assertSame([$today->addDay()->toDateString(), $today->addDays(2)->toDateString()], DayPick::query()->where('task_id', $task->id)->whereNotNull('moved_to')->orderBy('date')->get()->map(fn (DayPick $pick): string => $pick->moved_to->toDateString())->all(), 'the second move read the first one\'s pick and moved it on');
    }

    public function test_a_move_that_waits_past_its_bound_for_the_plan_is_refused_with_nothing_written(): void
    {
        $planner = app(DayPlanner::class);
        $task = $this->task();
        $accountId = (int) $this->client->account_id;
        $today = $planner->today();
        $planner->pick($accountId, $today, $task->id);

        $holder = DB::connection('lock_holder');
        $holder->beginTransaction();
        $holder->table('day_plan_locks')->where('account_id', $accountId)->lockForUpdate()->first();
        try {
            $planner->pick($accountId, $today->addDay(), $task->id);
            $this->fail('The move went through while another connection held the plan.');
        } catch (PlanBusy $e) {
            $this->assertSame(['task_id' => ['The plan is being changed. Try again.']], $e->errors());
        } finally {
            $holder->rollBack();
        }

        $this->assertSame([[$today->toDateString(), null]], DayPick::query()->where('task_id', $task->id)->get()->map(fn (DayPick $pick): array => [$pick->date->toDateString(), $pick->moved_to])->all());
    }

    public function test_a_close_refused_for_a_timer_never_shows_its_stamp_to_a_move_waiting_on_the_plan(): void
    {
        $planner = app(DayPlanner::class);
        $task = $this->task();
        $accountId = (int) $this->client->account_id;
        $today = $planner->today();
        $planner->pick($accountId, $today, $task->id);
        TimeEntry::startFor($task);
        $live = fn (): array => DayPick::query()->live()->where('task_id', $task->id)->orderBy('date')->get()->map(fn (DayPick $pick): string => $pick->date->toDateString())->all();

        [$dir, $pid, $child] = $this->forkSecond(function (string $dir) use ($planner, $accountId, $today, $task, $live): array {
            self::awaitFile("{$dir}/held", 'the close to hold the plan');
            $planner->pick($accountId, $today->addDay(), $task->id);

            return $live();
        });
        $this->onceQueried(['`day_plan_locks`', 'for update'], function () use ($dir, $child): void {
            file_put_contents("{$dir}/held", '1');
            $this->awaitQueued($child, '%`day_plan_locks`%for update%');
        });
        // The move runs to its end before the close goes on, at the moment its turn is given back.
        $this->onceTransactionEnds(fn () => self::awaitFile("{$dir}/answer", 'the move to answer'));

        try {
            $planner->close($accountId, $today);
            $this->fail('The day closed with a timer running.');
        } catch (ValidationException $e) {
            $this->assertSame(['close' => ['Stop the running timer before closing the day.']], $e->errors());
        }

        $this->assertSame([$today->addDay()->toDateString()], $this->secondResult($dir, $pid)['answer'], 'the move saw today open and moved its pick');
        $this->assertSame([$today->addDay()->toDateString()], $live());
        $this->assertFalse($planner->isClosed($accountId, $today));
    }

    public function test_a_timer_started_while_the_close_waits_to_stamp_refuses_the_close_and_its_stamp_never_shows(): void
    {
        $planner = app(DayPlanner::class);
        $task = $this->task();
        $accountId = (int) $this->client->account_id;
        $today = $planner->today();

        [$dir, $pid, $child] = $this->forkSecond(function (string $dir) use ($task): mixed {
            self::awaitFile("{$dir}/held", 'the close to hold the plan');
            $close = (int) self::awaitFile("{$dir}/close", 'the close to name its connection');
            // The timer has written its entry and taken back today's stamp; it commits once the close waits to stamp.
            Event::listen(TransactionCommitting::class, function () use ($dir, $close): void {
                file_put_contents("{$dir}/unstamped", '1');
                $this->awaitQueued($close, '%insert into `day_closes`%');
            });

            return TimeEntry::startFor($task)->id;
        });
        file_put_contents("{$dir}/close", (string) DB::connection('lock_writer')->selectOne('select connection_id() as id')->id);
        $this->onceQueried(['`day_plan_locks`', 'for update'], function () use ($dir): void {
            file_put_contents("{$dir}/held", '1');
            self::awaitFile("{$dir}/unstamped", 'the timer to take back the stamp');
        });
        $stampShown = null;
        $this->onceTransactionEnds(function () use (&$stampShown, $accountId): void {
            $stampShown = DB::connection('lock_holder')->table('day_closes')->where('account_id', $accountId)->exists();
        });

        try {
            $planner->close($accountId, $today);
            $this->fail('The day closed with a timer running.');
        } catch (ValidationException $e) {
            $this->assertSame(['close' => ['Stop the running timer before closing the day.']], $e->errors());
        }

        $this->assertIsInt($this->secondResult($dir, $pid)['answer'], 'the timer started');
        $this->assertFalse($stampShown, 'the refused close committed no stamp');
        $this->assertFalse($planner->isClosed($accountId, $today));
        $this->assertSame(1, TimeEntry::query()->whereNull('end_time')->count());
    }

    public function test_a_timer_started_after_the_close_stamped_never_runs_on_the_closed_day(): void
    {
        $planner = app(DayPlanner::class);
        $task = $this->task();
        $accountId = (int) $this->client->account_id;
        $today = $planner->today();

        [$dir, $pid, $child] = $this->forkSecond(function (string $dir) use ($task): mixed {
            self::awaitFile("{$dir}/stamped", 'the close to stamp the day');

            return TimeEntry::startFor($task)->id;
        });
        $this->onceQueried(['insert into `day_closes`'], function () use ($dir, $child): void {
            file_put_contents("{$dir}/stamped", '1');
            $this->awaitQueued($child, '%delete from `day_closes`%');
        });

        $planner->close($accountId, $today);
        $timer = $this->secondResult($dir, $pid)['answer'];

        // Under snapshot isolation the timer's stamp removal reads a row committed after its snapshot and is refused, start and all.
        $this->assertSame(['error', true], [$timer[0] ?? null, str_contains((string) ($timer[1] ?? ''), '1020 Record has changed since last read')]);
        $this->assertTrue($planner->isClosed($accountId, $today));
        $this->assertSame(0, TimeEntry::query()->count(), 'the refused start wrote nothing');
    }

    public function test_a_plan_change_behind_another_first_use_of_the_plan_row_is_refused_within_its_bound(): void
    {
        $planner = app(DayPlanner::class);
        $task = $this->task();
        $accountId = (int) $this->client->account_id;
        DB::table('day_plan_locks')->where('account_id', $accountId)->delete();

        // Another request inserted the row and holds it uncommitted.
        $holder = DB::connection('lock_holder');
        $holder->beginTransaction();
        $holder->table('day_plan_locks')->insert(['account_id' => $accountId]);
        try {
            $planner->pick($accountId, $planner->today(), $task->id);
            $this->fail('The pick went through while another connection held the plan row.');
        } catch (PlanBusy $e) {
            $this->assertSame(['task_id' => ['The plan is being changed. Try again.']], $e->errors());
        } finally {
            $holder->rollBack();
        }

        $this->assertSame(0, DayPick::query()->count());
    }

    public function test_the_settlement_migrations_go_down_and_up_again(): void
    {
        // Everything from the first settlement migration on, so later migrations go down and up with them.
        $files = collect(glob(database_path('migrations/*.php')))->map(fn (string $path): string => basename($path))->sort()->values();
        $first = $files->search(fn (string $file): bool => str_contains($file, 'create_client_month_closures'));
        Artisan::call('migrate:rollback', ['--database' => 'lock_writer', '--step' => $files->count() - $first, '--force' => true]);
        $schema = Schema::connection('lock_writer');
        $this->assertFalse($schema->hasTable('client_month_closures'));
        $this->assertFalse($schema->hasTable('client_month_settlement_events'));
        $this->assertFalse($schema->hasColumn('client_reports', 'needs_recalculation'));

        Artisan::call('migrate', ['--database' => 'lock_writer', '--force' => true]);
        $this->assertTrue($schema->hasTable('client_month_closures'));
        $this->assertTrue($schema->hasTable('client_month_settlement_events'));
        $this->assertTrue($schema->hasColumn('client_reports', 'needs_recalculation'));
        $this->assertSame('2026-09', Account::query()->sole()->settlementFrom());
    }

    public function test_settings_keys_are_written_in_place_on_mariadb_a_null_column_included(): void
    {
        $account = Account::create(['name' => 'Settings']);
        JsonSettings::write('accounts', $account->id, ['report_baseline' => true]);
        $stale = $account->fresh();
        JsonSettings::write('accounts', $account->id, ['month_close' => true]);
        JsonSettings::write('accounts', $stale->id, ['report_baseline' => false, 'count' => 3, 'label' => 'Zakres "bazowy"']);
        JsonSettings::forget('accounts', $account->id, 'count');

        $this->assertSame(['report_baseline' => false, 'month_close' => true, 'label' => 'Zakres "bazowy"'], $account->fresh()->settings);
    }

    public function test_a_custom_field_write_waits_on_the_client_row(): void
    {
        app(ClientFieldDefinitions::class)->define($this->client->account_id, 'visit_status', ['label' => 'Wizja', 'type' => 'select', 'options' => [['key' => 'booked', 'label' => 'Umówiona']]]);

        $this->whileHolding('clients', $this->client->id, fn () => app(ClientFieldWriter::class)->set($this->client, ['visit_status' => 'booked']));

        $this->assertNull($this->client->fresh()->custom_fields);
        $this->assertSame(0, ClientFieldEvent::query()->count());
    }

    public function test_a_field_definition_edit_waits_on_the_definition_row(): void
    {
        $field = app(ClientFieldDefinitions::class)->define($this->client->account_id, 'scope', ['label' => 'Zakres', 'type' => 'multiselect', 'options' => [['key' => 'walls', 'label' => 'Ściany']]]);

        $this->whileHolding('client_field_definitions', $field->id, fn () => app(ClientFieldDefinitions::class)->define($this->client->account_id, 'scope', ['options' => [['key' => 'roof', 'label' => 'Dach']]]));

        $this->assertSame(['walls'], array_keys($field->fresh()->optionLabels()));
    }

    public function test_a_message_another_dispatcher_claimed_is_not_sent_again(): void
    {
        $this->client->forceFill(['phone' => '600100200'])->save();
        $template = MessageTemplate::create(['account_id' => $this->client->account_id, 'key' => 't', 'name' => 'T', 'body' => 'Hello']);
        $rule = MessageRule::create(['account_id' => $this->client->account_id, 'key' => 'r', 'template_id' => $template->id, 'trigger_type' => MessageRule::FIELD_VALUE, 'trigger_config' => ['field' => 'x', 'values' => null]]);
        $message = OutboundMessage::create(['account_id' => $this->client->account_id, 'client_id' => $this->client->id, 'rule_id' => $rule->id, 'occurrence' => 'event:1', 'status' => OutboundMessage::PENDING, 'scheduled_for' => now()->subMinute(), 'expires_at' => now()->addHour()]);

        $holder = DB::connection('lock_holder');
        $holder->beginTransaction();
        $holder->table('outbound_messages')->where('id', $message->id)->where('status', OutboundMessage::PENDING)->update(['status' => OutboundMessage::SENDING]);
        try {
            app(MessageDispatcher::class)->dispatchDue();
            $this->fail('The dispatcher claimed a message another connection was claiming.');
        } catch (QueryException $e) {
            $this->assertSame(1205, (int) ($e->errorInfo[1] ?? 0), $e->getMessage());
        }
        $holder->commit();

        $this->assertSame([], app(MessageDispatcher::class)->dispatchDue(), 'once the other claim commits, nothing is left to send');
        $this->assertSame(OutboundMessage::SENDING, $message->fresh()->status);
    }

    /** @return array{string, string} */
    private static function outcome(object $result): array
    {
        $outcome = $result->outcome;

        return [is_string($outcome) ? $outcome : $outcome->value, $result->message];
    }

    private static function awaitFile(string $path, string $what): string
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path) || filesize($path) === 0) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Timed out after 10 seconds waiting for {$what}.");
            }
            usleep(10_000);
            clearstatcache(true, $path);
        }

        return (string) file_get_contents($path);
    }

    /**
     * The parent releases its lock only after it sees the child's locking read waiting in PROCESSLIST.
     *
     * @return array{string, string}
     */
    private function interleave(Closure $first, Closure $second, string $table = 'clients'): array
    {
        [$dir, $pid, $child] = $this->forkSecond(function (string $dir) use ($second): mixed {
            self::awaitFile("{$dir}/held", 'the first operation to hold its lock');

            return $second();
        });
        $committingAt = null;
        Event::listen(TransactionCommitting::class, function () use (&$committingAt, $dir, $child, $table): void {
            if ($committingAt !== null) {
                return;
            }
            file_put_contents("{$dir}/held", '1');
            $this->awaitQueued($child, "%`{$table}`%for update%");
            $committingAt = microtime(true);
        });

        try {
            $this->firstAnswer = $first();
        } finally {
            $result = $this->secondResult($dir, $pid);
        }

        $this->assertNotNull($committingAt, 'the first operation reached its commit holding the lock');
        $this->assertGreaterThan($committingAt, $result['at'], 'the second operation finished only after the first committed');

        return $result['answer'];
    }

    /**
     * Runs $second($dir) on a connection of its own in a child process; the two sides signal each other through files in $dir.
     *
     * @return array{string, int, int} the directory, the child's pid and its connection id
     */
    private function forkSecond(Closure $second): array
    {
        $dir = sys_get_temp_dir().'/settlement-race-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $inherited = [DB::connection('lock_writer')->getPdo()];

        $pid = pcntl_fork();
        if ($pid === 0) {
            // The parent's socket stays referenced, so no destructor here ever closes it.
            self::$inherited = $inherited;
            DB::purge('lock_writer');
            DB::purge('lock_holder');
            try {
                DB::connection('lock_writer')->statement('SET SESSION innodb_snapshot_isolation = ON');
                file_put_contents("{$dir}/child", (string) DB::connection('lock_writer')->selectOne('select connection_id() as id')->id);
                $answer = ['answer' => $second($dir), 'at' => microtime(true)];
            } catch (Throwable $e) {
                $answer = ['answer' => ['error', $e::class.': '.$e->getMessage()], 'at' => microtime(true)];
            }
            file_put_contents("{$dir}/answer", serialize($answer));
            posix_kill((int) getmypid(), SIGKILL);
        }

        return [$dir, $pid, (int) self::awaitFile("{$dir}/child", 'the second connection to open')];
    }

    /** @return array{answer: mixed, at: float} */
    private function secondResult(string $dir, int $pid): array
    {
        pcntl_waitpid($pid, $status);
        $result = unserialize(self::awaitFile("{$dir}/answer", 'the second operation to answer'));
        array_map(unlink(...), glob("{$dir}/*") ?: []);
        rmdir($dir);

        return $result;
    }

    /**
     * Runs $then once, right after this process's first query that contains every one of $parts.
     *
     * @param  list<string>  $parts
     */
    private function onceQueried(array $parts, Closure $then): void
    {
        $done = false;
        DB::listen(function (QueryExecuted $query) use (&$done, $parts, $then): void {
            if ($done || array_filter($parts, fn (string $part): bool => ! str_contains($query->sql, $part)) !== []) {
                return;
            }
            $done = true;
            $then();
        });
    }

    /** Runs $then once, when this process's outermost transaction has committed or rolled back. */
    private function onceTransactionEnds(Closure $then): void
    {
        $done = false;
        Event::listen([TransactionCommitted::class, TransactionRolledBack::class], function () use (&$done, $then): void {
            if ($done || DB::connection('lock_writer')->transactionLevel() > 0) {
                return;
            }
            $done = true;
            $then();
        });
    }

    /** Fails unless the given connection is running a statement like $statement, i.e. waiting on a lock the other side holds. */
    private function awaitQueued(int $connectionId, string $statement): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $waiting = DB::connection('lock_holder')->selectOne(
                "select count(*) as waiting from information_schema.PROCESSLIST where ID = ? and COMMAND in ('Query', 'Execute') and INFO like ?",
                [$connectionId, $statement],
            )->waiting;
            if ((int) $waiting > 0) {
                return;
            }
            usleep(20_000);
        }

        $seen = DB::connection('lock_holder')->select('select ID, COMMAND, STATE, INFO from information_schema.PROCESSLIST');
        throw new RuntimeException("Connection {$connectionId} never queued on {$statement} within 10 seconds. Seen: ".json_encode($seen));
    }

    private function task(): Task
    {
        $project = Project::query()->firstOrCreate(['account_id' => $this->client->account_id, 'client_id' => $this->client->id, 'name' => 'Site']);

        return Task::query()->firstOrCreate(['project_id' => $project->id, 'name' => 'Work'], ['source' => 'manual']);
    }

    private function septemberReport(string $status): ClientReport
    {
        return $this->report('2026-09-01', '2026-09-30', $status);
    }

    private function report(string $start, string $end, string $status): ClientReport
    {
        // A report finalizes or sends only when its hours are the sum of its task figures.
        TaskBilledMonth::query()->updateOrCreate(['task_id' => $this->task()->id, 'client_id' => $this->client->id, 'period' => mb_substr($start, 0, 7)], ['billed_minutes' => 120]);

        return $this->client->reports()->create([
            'account_id' => $this->client->account_id, 'period_type' => 'month', 'period_start' => $start, 'period_end' => $end,
            'contracted_hours' => 10, 'actual_hours' => 2, 'opening_balance_hours' => 0, 'currency' => 'PLN', 'composer_key' => 'structured_list',
            'body_markdown' => 'seed body', 'status' => $status, 'finalized_at' => $status !== ClientReport::STATUS_DRAFT ? now() : null,
        ]);
    }

    /** Holds the row on a second connection and expects $write, on the first, to give up waiting for it. */
    private function whileHolding(string $table, int $id, Closure $write): void
    {
        $holder = DB::connection('lock_holder');
        $holder->beginTransaction();
        $holder->table($table)->where('id', $id)->lockForUpdate()->first();

        try {
            $write();
            $this->fail("The write went through while {$table} #{$id} was locked by another connection.");
        } catch (QueryException $e) {
            $this->assertSame(1205, (int) ($e->errorInfo[1] ?? 0), $e->getMessage());
        } finally {
            $holder->rollBack();
        }
    }
}
