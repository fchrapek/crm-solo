<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientReport;
use App\Models\DayClose;
use App\Models\DayPick;
use App\Models\MonthCloseRun;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskBrief;
use App\Models\TaskSession;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Day\DayPlanner;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The demo reseeds every night, so its day screens must be full whatever day the reset lands on.
 */
final class DemoSeederScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['inertia.ssr.enabled' => false]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string, list<int>}>
     */
    public static function resetDays(): array
    {
        $full = [30, 45, 20];

        return [
            'a mid-month Wednesday' => ['2026-10-14 08:00:00', 'UTC', $full],
            'the first of a month' => ['2026-12-01 03:30:00', 'UTC', $full],
            'a Monday' => ['2026-10-12 03:30:00', 'UTC', $full],
            'a Saturday' => ['2026-10-17 03:30:00', 'UTC', $full],
            'the reset hour on the night summer time ends in Warsaw' => ['2026-10-25 03:30:00', 'Europe/Warsaw', $full],
            'just after local midnight' => ['2026-10-14 22:30:00', 'Europe/Warsaw', [25]],
            'a new local month while UTC is still in the old one' => ['2026-11-30 23:30:00', 'Europe/Warsaw', [25]],
            'an old local month while UTC is already in the new one' => ['2026-12-01 03:30:00', 'America/New_York', $full],
            'exactly local midnight' => ['2026-12-01 03:30:00', 'America/St_Johns', []],
        ];
    }

    /**
     * @param  list<int>  $todayLengths
     */
    #[DataProvider('resetDays')]
    public function test_the_day_screens_are_full_on_any_reset_day(string $instant, string $displayTimezone, array $todayLengths): void
    {
        config(['app.display_timezone' => $displayTimezone]);
        $this->travelTo(CarbonImmutable::parse($instant, 'UTC'));

        $this->seed(DemoSeeder::class);

        $owner = User::query()->sole();
        $accountId = $owner->account_id;
        $planner = app(DayPlanner::class);
        $today = LocalCalendar::today();

        $picks = $planner->picks($accountId, $today);
        $this->assertCount(3, $picks);
        $pickedTasks = Task::query()->with('project')->whereIn('id', DayPick::query()->whereDate('date', $today->toDateString())->pluck('task_id'))->get();
        $this->assertCount(3, $pickedTasks->pluck('project.client_id')->unique(), 'today picks span three clients');
        $this->assertSame(1, $pickedTasks->whereNotNull('finished_at')->count(), 'exactly one pick is ticked');
        $this->assertSame(1, Task::query()->where('name', 'Aktualizacja cennika PDF')->count(), 'the ticked pick spawns no successor');
        $minutesByTask = $picks->pluck('minutes', 'name');
        $this->assertSame(0, $minutesByTask['Aktualizacja cennika PDF'], 'the ticked pick logged nothing today');
        if (LocalCalendar::now()->diffInMinutes($today, true) >= 120) {
            $this->assertGreaterThan(0, $minutesByTask['Konfiguracja płatności BLIK'], 'the BLIK pick shows time today');
            $this->assertGreaterThan(0, $minutesByTask['Makiety: strona główna i podstrona projektu'], 'the mockups pick shows time today');
            $this->assertNotEmpty($planner->offPlan($accountId, $today), 'one entry today is off the plan');
        }
        $requested = [
            'BLIK: konfiguracja bramki w sandboxie' => 30, 'Makiety: poprawki po uwagach fundacji' => 45, 'Galeria: kompresja zdjęć realizacji' => 20,
            'Naprawa galerii po aktualizacji wtyczki' => 50, 'Formularz wyceny: walidacja pól' => 75, 'BLIK: research bramek i sandbox' => 45, 'Makiety strony głównej' => 180,
        ];
        foreach (TimeEntry::query()->whereIn('title', array_keys($requested))->get() as $entry) {
            $this->assertLessThanOrEqual($requested[$entry->title], $entry->duration_minutes, "{$entry->title} is never longer than asked");
            $this->assertSame($entry->duration_minutes, (int) round($entry->start_time->diffInMinutes($entry->end_time, true)));
        }
        $todayEntries = TimeEntry::query()->whereIn('title', array_slice(array_keys($requested), 0, 3))->orderBy('start_time')->get();
        foreach ($todayEntries->slice(1)->values() as $i => $entry) {
            $this->assertTrue($entry->start_time->greaterThanOrEqualTo($todayEntries[$i]->end_time), 'today\'s entries do not overlap');
        }
        $this->assertSame($todayLengths, $todayEntries->pluck('duration_minutes')->all(), 'today\'s entries have exactly the room the seed instant leaves');
        $this->assertCount(1, $planner->picks($accountId, $today->addDay()));

        $this->assertSame(0, TimeEntry::query()->whereNull('end_time')->count(), 'no timer runs on the demo');
        foreach (TimeEntry::all() as $entry) {
            $this->assertTrue($entry->end_time->greaterThan($entry->start_time), "entry {$entry->id} has a positive duration");
            $this->assertFalse($entry->end_time->greaterThan(now()), "entry {$entry->id} ends in the future");
        }

        $expected = [];
        for ($day = $today->startOfMonth(); $day->lessThan($today); $day = $day->addDay()) {
            if (! $day->isWeekend()) {
                $expected[] = $day->toDateString();
            }
        }
        $closed = DayClose::query()->pluck('date')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->sort()->values()->all();
        $this->assertSame($expected, $closed, 'every weekday before today this month is closed, nothing else');

        $this->assertSame(
            [Task::AGENT_LANE_BACKLOG, Task::AGENT_LANE_DONE, Task::AGENT_LANE_IN_PROGRESS, Task::AGENT_LANE_IN_REVIEW],
            Task::query()->onAgentBoard()->pluck('agent_lane')->unique()->sort()->values()->all(),
        );
        $this->assertNotNull(Task::query()->where('name', 'Migracja hostingu na PHP 8.3')->sole()->finished_at);
        $this->assertTrue(
            Task::query()->onAgentBoard()->where('agent_lane', '!=', Task::AGENT_LANE_DONE)->whereNotNull('finished_at')->doesntExist(),
            'only the done lane holds finished cards',
        );

        $sessions = TaskSession::query()->with('timeEntry')->get();
        $this->assertCount(2, $sessions);
        $this->assertCount(2, $sessions->pluck('time_entry_id')->unique());
        foreach ($sessions as $session) {
            $this->assertTrue($session->started_at->equalTo($session->timeEntry->start_time));
            $this->assertTrue($session->ended_at->equalTo($session->timeEntry->end_time));
            $this->assertSame('stopped', $session->ended_reason);
            $this->assertSame($accountId, $session->account_id);
            $this->assertSame($session->task_id, $session->timeEntry->task_id);
            $this->assertSame('terminal_session', $session->timeEntry->source);
            $this->assertStringStartsWith('/home/demo/', $session->worktree_path);
        }

        $formularz = Task::query()->where('name', 'Formularz wyceny mebli na wymiar')->sole();
        $this->assertNull($formularz->session_pid);
        $this->assertNull($formularz->session_port);
        $this->assertNull($formularz->session_token);
        $brief = TaskBrief::query()->where('task_id', $formularz->id)->sole();
        foreach (TaskBrief::FIELDS as $field => $column) {
            $this->assertNotNull($brief->{$column}, "brief field {$field} is filled");
            $this->assertSame($owner->id, $brief->confirmations[$field]['user_id'] ?? null, "brief field {$field} is confirmed by the owner");
        }

        $attachment = TaskAttachment::query()->sole();
        $this->assertSame($formularz->id, $attachment->task_id);
        $pdf = Storage::disk('local')->get($attachment->file_path);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertSame(mb_strlen($pdf, '8bit'), $attachment->size);
        $this->assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF/', $pdf, $m));
        $this->assertSame('xref', mb_substr($pdf, (int) $m[1], 4, '8bit'), 'startxref points at the cross-reference table');
        $this->assertSame(1, preg_match('/xref\n0 (\d+)\n0000000000 65535 f \n((?:\d{10} 00000 n \n)+)trailer/', $pdf, $table));
        $offsets = array_map('intval', preg_split('/ 00000 n \n/', mb_rtrim($table[2], " n\n"), -1, PREG_SPLIT_NO_EMPTY));
        $this->assertCount((int) $table[1] - 1, $offsets);
        foreach ($offsets as $i => $offset) {
            $this->assertSame(($i + 1).' 0 obj', mb_substr($pdf, $offset, mb_strlen(($i + 1).' 0 obj'), '8bit'), 'xref entry '.($i + 1).' points at its object');
        }
        $this->assertSame(1, preg_match('#/Length (\d+) >>\nstream\n(.*?)\nendstream#s', $pdf, $stream));
        $this->assertSame((int) $stream[1], mb_strlen($stream[2], '8bit'), 'the stream length is exact');
        foreach (['Brief: formularz wyceny mebli na wymiar', 'Dokument przykladowy do wersji demo.'] as $line) {
            $this->assertStringContainsString("({$line}) Tj", $stream[2]);
        }
        $this->assertSame(1, preg_match('/^[\x09\x0A\x0D\x20-\x7E]*$/', $pdf), 'the PDF is plain ASCII');
        $this->assertDoesNotMatchRegularExpression('#/(Info|Author|Creator|Producer|CreationDate|ModDate)\b#', $pdf);
        $download = $this->actingAs($owner)->get(route('task-attachments.show', $attachment->id))->assertOk();
        $this->assertSame($pdf, $download->streamedContent() ?: file_get_contents($download->baseResponse->getFile()->getPathname()));

        $run = MonthCloseRun::query()->whereHas('client', fn ($q) => $q->where('name', 'Pracownia Mebli Przykładowa'))->sole();
        $this->assertSame(LocalCalendar::previousMonth(), $run->period);
        $this->assertSame([
            ['db_archived', 'done', $formularz->project_id],
            ['local_db_import', 'skipped', $formularz->project_id],
            ['wp_updates', 'done', $formularz->project_id],
            ['local_verify', 'done', $formularz->project_id],
            ['commit_merge', 'pending', $formularz->project_id],
            ['live_deploy', 'pending', $formularz->project_id],
            ['reconcile_log', 'done', null],
            ['report', 'pending', null],
            ['draft_invoice', 'pending', null],
        ], $run->steps()->get()->map(fn ($step) => [$step->step_key, $step->state, $step->project_id])->all());
        foreach ($run->steps()->where('state', 'done')->get() as $step) {
            $this->assertTrue($step->completed_at->equalTo(now()->subDays(5)->setTime(9, 30)), "{$step->step_key} is ticked five days ago");
            $this->assertSame($owner->id, $step->completed_by);
        }
        $this->assertSame(2, Client::query()->where('include_in_month_close', true)->count());

        $furniture = Client::query()->where('name', 'Pracownia Mebli Przykładowa')->sole();
        [$from, $to] = LocalCalendar::monthRange(LocalCalendar::previousMonth());
        $minutes = TimeEntry::query()->where('client_id', $furniture->id)->where('start_time', '>=', $from)->where('start_time', '<', $to)->sum('duration_minutes');
        $draft = ClientReport::query()->where('client_id', $furniture->id)->where('status', 'draft')->sole();
        $this->assertEquals($draft->actual_hours * 60, $minutes, 'last month\'s entries match its draft report');

        $project = $formularz->project;
        foreach (['/', '/jutro', '/zadania', '/month-close', "/clients/{$project->client_id}/projects/{$project->id}/agent-board"] as $page) {
            $this->actingAs($owner)->get($page)->assertOk();
        }
    }
}
