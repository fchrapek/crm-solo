# Quick DB / time-log ops (CRM Solo)

Copy-paste recipes for the ad-hoc DB work done from a side terminal — logging time, fixing hours, quick lookups — **so you (or the agent) don't have to re-read `TimeEntriesController` every time.** Semantics here mirror that controller + the `TimeEntry` model; if they ever diverge, the controller wins and this doc should be updated.

## How to run

- **Hands-on:** `php artisan tinker` (interactive) — fine for manual work.
- **Scripted / agent:** `php artisan tinker --execute "..."` **hangs intermittently here** (see the tinker memory). For a repeatable or agent-run op, write a throwaway `php artisan make:command OneOff` and put the code in `handle()`, or use `DB::` in a tiny command — don't rely on `--execute`.

## Fastest: `php artisan time:log` (agent-safe, mirrors the UI)

For **logging time, prefer this command** over raw tinker — it derives account/project/client, and keeps `end_time` + `duration_minutes` consistent, exactly like `TimeEntriesController::store()`. And it runs headlessly (unlike `tinker --execute`, which hangs here).

```bash
# 90 min against a task (account/project/client derived from it)
php artisan time:log 90 --task=912 --desc="Fixed retainer hours"
php artisan time:log 90 --task="retainer hours"       # resolve by name (errors if ambiguous)

# 60 min to a client → logs to their "General" project
php artisan time:log 60 --client=Acme --desc="Ad-hoc DB work"

# a project, no task
php artisan time:log 45 --project="Acme site"

# backdated (start = end − minutes), non-billable
php artisan time:log 120 --task=912 --end="2026-07-13 17:00" --not-billable
```

### `--end` is LOCAL time, storage is UTC

`--end` is read in **`config('app.display_timezone')`** (`APP_DISPLAY_TIMEZONE`, `Europe/Warsaw` locally, `UTC` by default) and converted to UTC before saving; the confirmation line prints local time with its zone (`… → 11:00 CEST`). So `--end="11:00"` means 11:00 *your* time — no mental offset maths. An explicit offset in the string (`2026-07-21T17:00:00+00:00`) always wins.

Two things to keep straight when writing raw entries yourself:

- **DB columns are UTC.** `app.timezone` stays `UTC`; only the human-facing surface shifts. When reading rows in SQL, convert for display: `CONVERT_TZ(start_time,'+00:00','+02:00')`.
- **Eloquent will NOT convert for you.** It formats a Carbon in *that instance's own* timezone, so assigning a `Europe/Warsaw` Carbon stores the wall-clock string and silently backdates the row by the offset. Always `->setTimezone(config('app.timezone'))` before saving (that's what the command does).

The browser is unaffected — the UI sends absolute ISO instants (`localInputToIso()`) and renders with `toLocaleString()`, so it was always correct.

### Days and months are local too

Every human boundary (today, overdue, this month's hours, a report's month, the default period of a close, a draft invoice or `reports:generate`) is the display timezone's calendar, computed in `App\Support\LocalCalendar` and queried as UTC instants. So with `Europe/Warsaw`, work logged at 00:30 on the 1st belongs to the new month's report, and at 00:30 on the 1st the month just ended is the previous one. When querying by hand, bound a Warsaw month as `[first day 00:00 local, last day 23:59:59 local]` converted to UTC (`LocalCalendar::monthRange('2026-11')`), not `'2026-11-01'..'2026-11-30'`. A month given as text (`--period`, the month-close picker) is read only by `LocalCalendar::monthFrom()`: exactly `YYYY-MM` with month 01 to 12, built with the reset modifier so no field comes from the clock (`Carbon::createFromFormat('Y-m', ...)` takes today's day and turns September into October on the 31st). Anything else is refused before anything is written or sent.

Overdue is one server rule, `Task::isOverdue()`, sent to every page as `is_overdue`: a task is overdue once its due day has ended locally. A CRM due date is a calendar date stored as its midnight and read as written; a Trello card's due is an instant, read in the display timezone (`Task::dueDay()`).

An entry belongs wholly to the day (and month) it **started** on: a session from 23:00 to 01:00 local counts two hours on its start day, and an entry started on the 31st and running past midnight counts in that month. Entries are never split at midnight.

Target with **exactly one** of `--task` / `--client` / `--project`. Ambiguous names error with the matches listed. `source` is always `manual`; `billable` defaults true. Full flags: `php artisan time:log --help`. For anything the command doesn't cover (edits, adjustments, lookups), drop to the tinker recipes below.

## Model cheat-sheet — `App\Models\TimeEntry`

- `source`: `'manual'` (hand-logged) · `'terminal_session'` (agent sessions) · `'clockify'` (historical imports; the integration was removed 2026-10-01, nothing creates them now). Constants: `TimeEntry::SOURCE_MANUAL` etc.
- Fillable: `account_id, project_id, client_id, task_id, source, title, description, start_time, end_time, duration_minutes, billable, tags`.
- `title` = short label (shown in the list) · `description` = detail (defaults to the task name). `tags` is an array-cast JSON column.
- **Closed entries carry BOTH `end_time` and `duration_minutes`, kept consistent:** `duration_minutes = TimeEntry::minutesBetween(start, end)` (seconds rounded up to whole minutes; every stop path uses it). Aggregation/reports read `duration_minutes`. A **running** entry has `end_time = null`, `duration_minutes = 0`.
- **Everything is account-scoped.** Always set `account_id` — derive it from the task/project/client (below), never guess. Owner account if you truly need it raw: `App\Models\User::where('role','owner')->value('account_id')`.

## Recipes

### Log past time against a task (the common one)
```php
$task = App\Models\Task::with('project')->find(123);
App\Models\TimeEntry::create([
    'account_id'       => $task->project->account_id,
    'project_id'       => $task->project_id,
    'client_id'        => $task->project->client_id,
    'task_id'          => $task->id,
    'source'           => 'manual',
    'title'            => 'DB tweak',           // optional short label
    'description'      => 'Fixed retainer hours',// omit → defaults to task name
    'start_time'       => now()->subMinutes(90),
    'end_time'         => now(),
    'duration_minutes' => 90,                    // keep = ceil(diff/60)
    'billable'         => true,
]);
```

### Log time to a client with no specific task
Each client auto-gets a **"General"** private project. Log against it (task_id null is allowed):
```php
$client  = App\Models\Client::where('name','like','%Acme%')->first();
$project = $client->projects()->where('name','General')->first() ?? $client->projects()->first();
App\Models\TimeEntry::create([
    'account_id'=>$client->account_id, 'project_id'=>$project->id, 'client_id'=>$client->id,
    'task_id'=>null, 'source'=>'manual', 'description'=>'Ad-hoc work',
    'start_time'=>now()->subHour(), 'end_time'=>now(), 'duration_minutes'=>60, 'billable'=>true,
]);
```

### Fix / adjust hours on an existing entry
Keep `end_time` and `duration_minutes` consistent:
```php
$e = App\Models\TimeEntry::find(456);
$mins = 120;
$e->update(['duration_minutes' => $mins, 'end_time' => (clone $e->start_time)->addMinutes($mins)]);
```
⚠️ If `$e->source === 'terminal_session'`, also fix the linked `TaskSession` (the Time list shows *its* times):
```php
App\Models\TaskSession::where('time_entry_id',$e->id)->update(['started_at'=>$e->start_time,'ended_at'=>$e->end_time]);
```

### Start / stop a running timer
```php
// start (running stopwatch) - the same call the web, the agent verbs and terminal sessions use
$e = App\Models\TimeEntry::startFor($task);
// stop
$e->stopNow();
```

### List running (open) entries
```php
App\Models\TimeEntry::whereNull('end_time')->with('task:id,name','client:id,name')->get(['id','description','start_time','source','task_id','client_id']);
```

### Quick totals / lookups
```php
// hours logged for a client this month
App\Models\TimeEntry::where('client_id',$c->id)
    ->whereBetween('start_time',[now()->startOfMonth(), now()->endOfMonth()])
    ->sum('duration_minutes') / 60;

// this client's tasks
$c->projects()->with('tasks:id,project_id,name')->get();
```

## Gotchas
- **`tinker --execute` hangs** here — use interactive tinker or a throwaway command for anything scripted/agent-run.
- **Account scoping** — always set `account_id`; derive from task/project/client.
- **TaskSession sync** — only `terminal_session` entries have a `TaskSession`; edit both when changing times. Manual entries have none.
- **Report hours ≠ raw time.** Client-report `actual_hours` counts only time entries whose task has `is_reportable = true` (see `ReportDataAggregator`). Recurring/baseline tasks are usually `is_reportable=false`. So a client's raw time total can exceed report hours by design.
- **Finances source of truth** is the Infakt RZiS (`finances:import-rzis`), not these entries — see the finances memory.
