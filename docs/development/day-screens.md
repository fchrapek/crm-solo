# Day screens

The home screen (`/`) since 2026-09-28, built on the Solo design system (Figma file
`5penQt55PcSGuuZ4LRasgd`, pages `Solo · Foundations` and `Solo · Components`). The first
surface of the planned rewrite; everything else still runs on the old sidebar layout.

## The day as data

- `day_picks` (`DayPick`): tasks picked for a date, **at most 5** (`DayPlanner::MAX_PICKS`),
  today or tomorrow only, one row per task per date, account-scoped (route binding too). Each
  pick holds one of the five `slot`s, unique per account and date, so the cap holds in the
  database when two requests add a pick at once; `position` stays the display order.
- `day_closes` (`DayClose`): the day's stamp, unique per account and date.
- Dates are the **display timezone's** calendar day (`DayPlanner::today()`); storage stays UTC.
- `DayPlanner` (`app/Services/Day`) holds every rule: picks, the full open list, off-plan work,
  closing, the month card, totals.

## Rules

- **Open task** = `Task::scopeOpen()`, the one definition `/zadania`, picks, `crm today`,
  `crm brief` and timer-start name resolution share: not finished by the owner (`finished_at`
  null), not completed, not archived, and not sitting on the `Done` list
  (`TrelloListMapper::LANE_DONE`): a card left on Done is finished even unticked. Its project
  must be live too: not archived, and its client (when it has one) not deleted.
- **Close** is refused while any timer runs. **Starting any timer reopens the day**:
  `TimeEntry::booted()` deletes today's `DayClose` on a running entry (every source, so
  Horizon must restart after changes there). Close stamps first and then checks for timers,
  taking the stamp back if one runs; a start writes its entry before it removes the stamp. In
  any interleaving of the two, a closed day never has a timer running.
- **Ticking a task** (pick or off plan) goes through `TaskCompletion::finish()`, the path every
  surface shares (task pages, agent board, `crm task-done`, MCP `task_done`): it stops the
  task's running timers, then a manual task moves to Done while a Trello card only records
  `finished_at` (Trello keeps its list). The row shows ticked either way. Stop alone is a pause.
  Clicking a ticked box again unticks it (`DELETE /day/tasks/{task}/complete`,
  `TaskCompletion::unfinish()`): a manual task goes back to To-Do, a card only loses
  `finished_at`. The timer the tick stopped stays stopped. A card completed on its board
  (`can_untick` false, `Task::canUnfinish()`) stays ticked, and the route refuses it.
- **A Trello card's finish and the sync** (`TrelloService::syncCard`, `CardFinishReconciler`):
  each card is written under its task's row lock in one transaction, the card's fields and the
  owner-layer changes in one statement, decided on the row as it is under the lock. A fetch
  older than the card version the row holds (`trello_activity_at`, the card's
  `dateLastActivity`) is ignored, and one board syncs at a time (a cache lock per board that
  expires after 10 minutes, so a crashed run frees it; the Sync button says so when it is held).
  - The sync fills `finished_at` the first time it sees the card turn completed.
  - It never clears the finish of a card that is still completed (a due-complete card moved
    back to Doing stays finished).
  - Otherwise it clears it only when the card sits on an active lane (not Testing, not Done)
    it moved to, **and** the card's last list move is later than the finish by more than
    `CardFinishReconciler::MOVE_SKEW_SECONDS` (60 s, for clock skew). The move time comes from
    the card's own history (`GET /cards/{id}/actions?filter=updateCard:idList`), asked only
    for a finished card in that position. Comments and other activity do not count.
  - A failed lookup keeps the tick and sets `trello_move_pending_at`: every later sync asks
    again until Trello answers (the move time, or no move), even though the stored list id
    already matches. Any answer, or the card leaving the active lanes, clears the marker.
  - The work of one board sync is bounded inside its 10-minute lock: at most 10 move lookups per
    run, 10 s each (the rest stay pending and are asked next run), and the sync stops taking on
    cards 8 minutes in; a pass cut short archives nothing. The lock lives in the cache, so the
    web server, the scheduler and the workers must share one cache store (Redis in the
    documented setup); a per-process store such as `array` would let two syncs overlap.
  - A row with no recorded list yet just records it. `trello_due_complete` is null until a sync
    has seen the card; a list-mapping change leaves the completion of such a card alone unless
    it maps to Done.
  - A list-mapping change holds the same board lock as a sync (the save is refused while a sync
    runs) and reads each card's lane from the list the card is on and the mapping as saved, both
    under the card's row lock. The lock needs a cache store shared by the web server, the
    scheduler and the workers: Redis in the documented setup.
  - A card the board no longer returns (deleted in Trello) has its task archived, never deleted,
    once a fetch is known complete: a successful list of cards that all carry ids and stop short
    of the 1000 Trello caps a card list at. A failed or possibly cut fetch archives nothing. The
    task is restored when the card comes back. A card moved to another synced board of the same
    client moves its task there. Moved to another client's board (or to or from a client-less
    one) it gets a new task there, while the old task stays with its client, its time and its
    reports and is archived as a card that left its board; if the card returns, the sync finds
    that project's own task first and restores it. Time entries never change client or project,
    and a report counts an entry by the client it was logged for, whatever project its task is in
    now.
  - Blind spots: a card moved away and back between two syncs looks unmoved, so the tick
    stands; a move within 60 s after the tick is read as before it.
- **Off plan** = tasks with a timer today (running, or logged since local midnight) that are
  not the date's picks. Shown under the plan; ticks there never count toward the plan.
- **Parallel timers are valid**; the page receives all of them (`timers`), and the focus view
  takes `?timer={id}`.
- **Every running timer is visible and stoppable**, whatever state its task is in. A pick or
  off-plan row carries the timer it shows (`running_id`); every other running entry (a second
  timer on one task, a deleted task, no task at all) comes as `looseTimers` and renders under
  "Inne włączone timery" on Dziś and Podsumowanie with its client and project, Stop and the
  focus link (`DayPlanner::looseTimers`). A done pick keeps its running timer on its row: the
  sync or a list-mapping change can complete a card, but never stops the owner's timer; only
  his own tick or Stop does.
- **One click, one timer**: Start sends a `request_id` (UUID) and the server returns the timer
  that id already opened (`time_entries.start_request_id`, unique per account) instead of a
  second one (409 if that id started a timer on another task). An unanswered start keeps its
  id for that task until a response or a successful reload settles it, so a retry after a lost
  response replays it. Start and Stop allow one request in flight, failures show as a toast (the page
  mounts the shared Sonner toaster in Solo colours), and the day reloads all its props every
  30 s and on tab focus, so a timer an agent starts or stops from the CLI shows up unprompted
  and a page left open past midnight moves to the new day (date, picks, month).
- **Month card tiles** (`DayPlanner::tileState`): today = `today` / `stamped` once closed; any
  other closed day = `closed` (weekends too); weekends = `rest`; past weekdays = `open`,
  upcoming ones `future`. The count is closed weekdays over all weekdays.

## Views (navigation never writes)

| URL | View |
|---|---|
| `/` | Dziś (paper). The day list stays the default while timers run; Podsumowanie only when closed and nothing runs |
| `/?widok=dzis` | Dziś, even after a close |
| `/?widok=gotowe` | Podsumowanie: "Gotowe?" with the close button before a close, the stamp after |
| `/?widok=timer[&timer=id]` | The vermilion focus view of a running timer (falls back to Dziś) |
| `/jutro` | Plan tomorrow |
| `/zadania[?na=jutro]` | Full list of open tasks grouped by client; the only place picks are chosen |
| `/sesje` | The previous dashboard (agent session card, demo crm prompt) |

The day trail (Dziś → Podsumowanie → Jutro) and the date sit in one caps row above the
poster; the poster shows the weekday only, never the date number.

## Frontend

- Tokens: `resources/css/solo.css` (`--solo-*`), surface modes via `data-day="paper|timer|done"`,
  mirroring the Figma `Solo / Surface` modes. The token linter reads it as a second source.
- Components: `resources/js/components/solo/` (masthead, primitives, task row, month card,
  day trail/row, logos exported from Figma). Pages: `resources/js/pages/today/`.
- The layout resolver gives `today/*` and `auth/login` no shell; they draw their own masthead.
- Fonts: Archivo (variable, `wdth 62` for posters) self-hosted; **Switzer loads from the
  Fontshare CDN** in `app.blade.php` because its licence may not allow committing the files to a
  public repo. Inter is the fallback.
- Login is the Figma v1 (full wordmark on vermilion).

## Not built yet

- The ⌘K search pill from the design (no global search exists).
- Month card range switch (3 months, year).
- Picks carried across days, tile history before 2026-09-28 (closes only exist from here on).
