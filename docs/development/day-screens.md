# Day screens

The home screen (`/`) since 2026-09-28, built on the Solo design system (Figma file
`5penQt55PcSGuuZ4LRasgd`, pages `Solo · Foundations` and `Solo · Components`). Since 2026-11-11 Dziś is
rebuilt on the rail and search redesign and every page renders inside its shell: see [shell.md](shell.md).

## The day as data

- `day_picks` (`DayPick`): tasks picked for a date; **5 make a full day** (`DayPlanner::MAX_PICKS`) and more can be picked, with a warning each user can switch off in Mój profil, Zadania (`users.settings.warn_full_day`); a pick moved to another day keeps its row with `moved_to` and leaves the day's plan (`DayPick::scopeLive()`, `DayPlanner::moved()` for Podsumowanie),
  from today to the end of next week (`DayPlanner::isPlannable()`, web and agents alike), one row
  per task per date, account-scoped (route binding too). A move may go to any day ahead, and a pick
  on any day from today on can be removed or moved again (`unpick()`, `crm unplan --day`), never on a
  closed day. Every question "is this task on this day" reads live picks only (`scopeLive()`), so a
  moved task is unpicked on the day it left and comes back to its suggestions. Each
  pick holds one of the five `slot`s, unique per account and date, so the cap holds in the
  database when two requests add a pick at once; `position` stays the display order. Every plan
  change (pick, move, unpick, dismiss) and every close or reopen of an account run in one
  transaction that first locks the account's `day_plan_locks` row (`DayPlanner::holdingPlan()`), and
  a move reads the task's other picks and writes the new row and their `moved_to` inside it, so two
  moves of one task, or a move and a close, never interleave, however long one of them takes. A turn
  not given within five seconds (MariaDB's `FOR UPDATE WAIT 5`) writes nothing and answers "the plan
  is being changed, try again" (`PlanBusy`: a form error on the web, `refused` with `retry: true` for
  `crm plan`/`unplan` and the MCP tools). Nothing references that row and the plan's transaction takes
  no client or report row, so it never waits in a cycle with `ClientFinancialLock`. A close writes
  its stamp first and then checks for a running timer in the same transaction, with that
  transaction's first plain read, so the check's snapshot is taken after the stamp's write; a timer
  refuses the close and the stamp rolls back unseen. A timer's own reopen (`reopenForTimer()`) takes
  no lock: it writes its entry before it removes the stamp, and the removal either runs before the
  close's stamp (which then waits for the timer's commit, so the check sees it) or waits on that
  stamp and removes it once the close commits. On MariaDB 11.8 (snapshot isolation on) that last
  case refuses the timer's start instead, with nothing written.
- Several timers may run at once. The shell shares them all (`timers`) and shows the newest, or the one `?timer=` names (`timer`); with more than one, the timer tile and the phone strip carry "1 z 2" (`TimerSwitch`), a list to pick the shown one from.
- Any day has its own page, `/day/RRRR-MM-DD` (`TodayController::day`): a past day is Podsumowanie for that date and can be closed or reopened from there, a day ahead is its plan, today redirects home. A day past the planning window (a moved pick's) says so instead of offering free places and Propozycje. The month tile marks every day of the month in two rows, each a link to its day; the counter is closed days over weekdays plus closed weekend days. A past day's page shows its own month, with the tile states read against today (`DayPlanner::month()`).
- A pick left unfinished on an earlier day waits on today (`DayPlanner::carried()`, "z czwartku")
  until the task is done, picked again or dismissed; dismissing stamps `dismissed_at` on the task's
  past picks and never deletes them, so a past day's plan stays as it was.
- `day_closes` (`DayClose`): the day's stamp, unique per account and date.
- `day_plan_locks`: one row per account, made with the account (`Account::created`, older accounts by
  migration) and only ever locked (no model); a plan change never inserts it and refuses with
  `PlanBusy` when it is missing.
- Dates are the **display timezone's** calendar day (`DayPlanner::today()`); storage stays UTC.
- `DayPlanner` (`app/Services/Day`) holds every rule: picks, the full open list, off-plan work,
  closing, the month card, totals, the plan page's suggestions and the overdue count.

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
  surface shares (task pages, `crm task-done`, MCP `task_done`): it stops the
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
- **Month states** (`DayPlanner::tileState`): today = `today` / `stamped` once closed; any
  other closed day = `closed` (weekends too); weekends = `rest`; past weekdays = `open`,
  upcoming ones `future`. The count is closed weekdays over all weekdays. Dziś and Tydzień show the month
  as a number tile (`MonthCard look="tile"`: the count and one mark per weekday); its Cały miesiąc button
  opens `MonthCalendar`, the whole month with numbered day cells (closed in ink, open outlined, today in
  cobalt, the weekdays ahead quiet, weekends bare) and a legend, in a popover from 768 px and a bottom
  sheet below. Podsumowanie shows the count alone as its fourth number tile.

## Views (navigation never writes)

| URL | View |
|---|---|
| `/` | Dziś (paper). The day list stays the default while timers run; Podsumowanie only when closed and nothing runs |
| `/?view=today` | Dziś, even after a close |
| `/?view=summary` | Podsumowanie: "Gotowe?" with the close button before a close, the stamp after |
| `/?view=timer[&timer=id]` | The vermilion focus view of a running timer (falls back to Dziś) |
| `/tomorrow` | Plan tomorrow, with Propozycje |
| `/week[?next=1]` | This week or next week, each day with its picks |
| `/all-tasks[?for=tomorrow\|RRRR-MM-DD][&hidden=1][&from=day]` | Full list of open tasks grouped by client; where picks are chosen (Propozycje on Jutro add too). A day's page opens it for its own date with `from=day`, and Gotowe returns to that page |

The day views (Dziś, Podsumowanie, Jutro, Tydzień) are the shell's pills on every day screen (`dayPills()` in
`pages/today/place.ts`); once today is closed the Podsumowanie pill carries a check (`done` on a pill, every day
view gets the `closed` prop). All of them stand on the redesign's tiles (`day.module.css`, `TileGrid`).

- **One day head** (`pages/today/day-header.tsx`, Figma sections 1511:25540, 1515:26853, 1516:27842, 1516:27999,
  1516:28115): every day screen (Dziś, Jutro, a day's page, Tydzień, Podsumowanie, Wszystkie zadania) takes
  `DayHeading`, the heading alone with no date line under it, and `DayMonth`, the month tile whose label carries the
  date ("Listopad · 22.11.2026", Tydzień the range "Listopad · 16.11 – 22.11.2026"; `monthTileLabel`, which
  Podsumowanie's month figure uses too), so a change to the head lands on all of them. The day tile's header carries no
  picked count (the add row says the free places); Tydzień keeps one per weekday, since it says which day is full.
- **Day rows are one line**: every row's title is cut with an ellipsis from 768 px and shows whole on hover; a phone
  wraps it and the row grows, a row with details to two lines at most (clamped, the whole title its tooltip and the
  link's name; the link holds the clamp, so its focus ring is whole).
- **Row details** (Figma Runda zmian (zaakceptowano), Shared · Row details (toggle) 1555:77897, the measurements
  1557:346726): a task row on Dziś (picks, Z poprzednich dni, Poza planem) and in Propozycje keeps client, project,
  due day and logged time out of the line and folds them under it (`Row details`, built by `taskDetails` in
  `day.tsx`): Klient, Projekt, Termin, Czas, label over value with no background, a step under the 16 px title:
  the value at the end meta's 13 px (`--solo-text-meta`, 18 px line, the primary ink), the label at 12 px
  (`--solo-text-label`, 16 px line, the secondary ink) right above it, 8 px between lines of pairs. The pairs are one CSS grid with
  fixed columns in that order, so the rows of a list line up whatever the text: from 768 px a text column shares the
  width between 4 and 15 rem (`--role-detail-text`) and a date or a duration keeps 5.5 rem (`--role-detail-short`,
  `short` on the detail). The columns are decided per list (`listDetails`, where the list is rendered): a column no
  row of the list has a value for is left out of that list (no Termin on a list without due days), a column any row
  has stays for every row, and from 768 px a row's missing value leaves its cell empty so the next row's Czas stays in
  line. On a phone the shown pairs flow two to a line in their order, a missing value taking no cell. A long value is cut with an ellipsis and its whole
  text is the `title` tooltip; only a journal note's Tekst takes the whole line, uncut. A title that is a link
  has no arrow and no underline: with a pointer over it, it takes the secondary ink (not on the selected row), and it
  keeps the focus ring. A task from an earlier day adds Z dnia ("czw. 19.11") before Czas. The row has no
  hover band: only the chevron before the end action shows one, a 32 px disc around its glyph. Start and Stop are the same
  32 px disc (`IconButton` size `row`), and so are the hover and ring of every bare `IconButton` (look `quiet`: the
  more button, the calendar's arrows, the way back), inside their larger target. Czas is every finished entry on the task (`logged_minutes`, `App\Services\Time\LoggedMinutes`, one query
  per list), while the row's own time stays the day's (`minutes`, in the secondary ink). A task a timer runs on
  shows the dot and the live time before the chevron at all times: the row carries `running_id` (picks, Poza
  planem, and the suggestions since this change, `DayPlanner::runningIds`) and the page finds that timer among the
  account's running timers (`timerOf`), whose start the shared `Elapsed` ticks like the rail.
- **The day panel's lower part** (Dziś, Jutro, Podsumowanie): every rule stands 12 px in, the one under Dodaj zadanie
  too; rows and Dodaj zadanie are 16 px, group headings 14; the closing buttons stand 24 px under the last row
  (`TileActions apart`); the plan button is "Plan na jutro" whatever tomorrow holds. Headings, notes, tile headers and
  tile figures have whole-pixel line heights (the day poster `round()`s its caps leading), so every rule starts on a
  pixel.
- **The row menu's calendar** (`pick-menu.tsx`, Figma 1483:12922, phone 1483:13273): Wybierz datę swaps the menu for
  Przenieś na, a second step with a back arrow before the heading (`BottomSheet onBack` on a phone); the arrow and
  Escape go back to the menu's items, a second Escape closes it.

- **Dziś** (`pages/today/day.tsx`, Figma 1075:578, phone 1075:780, rows 3E 1375:27445, phone 1375:27701): one list
  tile with the weekday (no date line: the month tile's label names the date, "Listopad · 21.11.2026"), the picks as
  rows (tick, title, the running dot and time or the day's logged time, the chevron to the details,
  Start or Stop, and "Usuń z dzisiaj" behind the row's more button), the add field with the free places as its quiet
  end meta (not on a closed day), then Z poprzednich dni (the title on one line; the chevron, whose
  details name the day it came from; a quiet Na dziś; Usuń behind the more button), Poza planem (tick, title, time, the
  chevron, Stop on a running one, Do planu behind the more button)
  and Inne włączone timery when there are any, and the tile's actions: Zamknij dzień (posts the close; disabled while a timer runs; a "Dzień zamknięty"
  chip once closed) and Plan na jutro. Beside it from 1100 px, below it otherwise: the vermilion timer tile
  (from 768 px only: a phone has the row and the strip, never a third) and the month number tile.
- **Podsumowanie** (`summary.tsx`, Figma 1091:14556, phone 1091:14778, closed 1091:15438, blocked 1091:15874):
  the poster (Gotowe? before a close, Gotowe. after), four number tiles (zaplanowane or zadania,
  zalogowane, klienci, the month with the date in its label; two by two below 1100 px), then the plan tile: the picks (tick, title, the time
  of a done one or the running dot and time, the client from 768 px, Stop on a running one), Poza planem, Inne
  włączone timery and the close:
  Zamknij dzień with the state beside it (a chip, or the reason while a timer runs), or the Dzień zamknięty chip
  with Otwórz dzień. A close flashes "Dzień zamknięty." Beside it: Czas wg klienta and Jutro (one line and the
  plan button, ink once the day is closed). Czas wg klienta is summed on the page from the pick and off-plan rows
  (`timeByClient`) and is left out when they do not add up to the day's logged total (time with no task).
- **Jutro** (`plan.tsx`, Figma 2E 1375:26662, phone 1375:26876): the weekday poster, the list tile
  (tick, title, client, Usuń; the add field with the free places) and, while a place is free and there is something to suggest, the
  Propozycje group in the same tile (`DayPlanner::suggestions`: today's unfinished picks first, then the soonest due
  day, then work in hand, three at most; each is the title, the running dot and time when a timer runs
  on it, the chevron to Klient, Projekt, Termin and Czas, a quiet Na jutro; the last row opens the full list and has
  no chevron, like every row of Wszystkie zadania, which carries title and project only). Beside it from
  1100 px, below otherwise, the running timer and the month tile. The action is "Wróć do dnia" while proposing,
  else "Zapisz plan".
- **Row context line**: `Row`'s `context` is a line under the title on a phone only, wrapping, for a row whose end
  meta (`wideMeta`) says it from 768 px; `wrap` lets the title wrap from 768 px too, for a row in a narrow side column only.
- **Tydzień** (`week.tsx`, Figma 1091:28683, phone 1091:29016): the poster (the month tile names the week's dates), Ten tydzień
  and Następny as a second row of pills, one tile with the seven dates (each a header with its count and its
  picks; a past unfinished pick offers Na dziś and Usuń behind the more button; a plannable day ends in a row with
  Dopisz zadanie, which opens the add field for that date, and Wybierz, the full list), and beside it the running
  timer and the month tile.
- **The add field** (`add-or-pick.tsx`, Figma 1F, 1412:21781): one combobox that picks an open task or adds a new one
  to the day. At rest a quiet "Dodaj zadanie" row; with the cursor and nothing typed, the tasks worked on last and the
  recent projects (choosing one sets where a new task goes); typed (two characters at least), "Z listy (n)" with the
  open tasks whose name holds the text (`DayPlanner::matching`, the suggestions' order in SQL, hidden groups included,
  the date's picks left out; `LikePattern` takes %, _ literally), then
  Nowe with "Trafi do" and the project pill (`project-picker.tsx`: search, recent projects, clients as headings over
  their projects, `ProjectChoices`). The pill is preset only when exactly one timer runs (its project). Arrows walk
  the rows, Enter takes the highlighted one (the first match, or Nowe); while the matches are not in, or the search
  failed, nothing is selected and Enter waits for a key. Tab from the field reaches Trafi do and the full list. Esc
  and every close give the focus back to the row or the button that opened the field. A new task goes
  through `POST /day/add` (`DayPlanner::addNewTask`: `TaskWriter::create` and the pick in one plan transaction;
  `day_add_requests` keeps each `request_id` per account with its payload and task, so the same id and payload returns
  the first task, and another payload or a deleted task answers 409), then a toast names it
  with Zmień, which opens the task's edit form (`?change`). A full day says so in the menu and still adds. From 768 px
  the field takes the row and the menu opens under it; a phone keeps the row and opens a sheet with the field on top.
- **Wszystkie zadania** (`tasks.tsx`, drawn as a dialog in Figma 1087:5502, kept as the page `/zadania`): the
  token "Wszystkie zadania", and the days a pick can go to as the pills (`dayPillsFor`: today, tomorrow, the
  next working days, plus the day Tydzień sent). One list tile grouped by client: the head with its count, three
  rows (picked ones always show) and "Pokaż N więcej"; a row is the title, the project from 768 px and the pick
  button; a picked row takes the selection fill and says Wybrane. Ukryj grupy and the hidden count sit under the
  poster, and Gotowe leads back to the day view the list was opened for.
- **Timer** (`/?view=timer`, not in the redesign): the earlier content on a vermilion tile inside the shell: the
  task title, client · project, the clock and "od 09:14"; beside it (below on phones) DZIŚ with the day's picks
  as Queue rows, "dziś razem" and Stop. Whether it stays is an open decision.

## Frontend

- Tokens: `resources/css/solo.css` (`--solo-*`), surface modes via `data-day="paper|timer|done"`,
  mirroring the Figma `Solo / Surface` modes. Night is the fourth mode: paper under `.dark`
  (`.dark [data-day='paper']`); timer and done keep their colours in both appearances. The token
  linter reads the file as a second source.
- Components: `resources/js/components/solo/` (the shell's parts in `shell/`, primitives, tile, row, task row, month card,
  stage pill, actor mark, live line, toaster, logos exported from Figma). Pages:
  `resources/js/pages/today/`.
- The day pages render their root as `<SoloSurface day=...>`, and every Solo page after them
  should too. Under it, Dialog, AlertDialog, Popover, Select and DropdownMenu content portal into
  one shared paper surface on body (`useSoloPortalSurface`, Night under dark) and take the Solo
  look; elsewhere they portal to body as before. `<SoloToaster>` gives toasts their own paper
  surface, so they read on vermilion and cobalt. `InertiaPagination look="solo"` is the Solo
  pagination.
- The layout resolver gives every signed-in page the shell ([shell.md](shell.md)); only `auth/login` and
  `auth/waitlist` are bare. The shell mounts the one toaster.

## Phones

Mobile first, one tree per screen (Figma Components, section Mobile 323:149; Screens, Klienci, column
Mobile (selected)).

- **Breakpoints.** Base CSS is the phone; `@media (min-width: 768px)` adds desktop and
  `(min-width: 1100px)` the wide poster sizes (`solo.css` header). `bun run lint:tokens` fails any
  other width condition in `solo.css` and `components/solo/`, range syntax included
  (`scripts/solo-breakpoints.mjs`). Pages outside that scope
  (`today.module.css`, the old page's invoices tab) still carry older `max-width` queries until
  their phone layouts land; the five record tabs and Lista are mobile first.
- **No JS breakpoints for layout.** Both variants render and CSS shows one, so nothing waits for
  hydration and nothing shifts. Script only where the interaction differs (a bottom sheet instead of an
  inline block), and that content mounts when it opens. `ResponsiveSection` reads the width only to mount its children in one
  place (inline from 768 px, the sheet while open below it); CSS still decides what shows, and the server
  renders the inline copy.
- **Navigation.** The shell renders the rail and `<TabBar>`; both list Dziś, Klienci, Kontakty
  and, for an owner, Ustawienia, the views being worked on (owner decision, 2026-10-08); a member's fourth tab
  on a phone is Mój profil ([shell.md](shell.md)). Leady, Finanse and the month close
  are off the menu for now and still open by URL. The active section comes from the URL
  (`tabBarSection`; a page no section owns lights none). Wyloguj is the avatar menu in the rail and the last row
  of the Ustawienia list on phones, and the Sesja tile of Mój profil on both. The shell clears everything fixed over the page end
  (the tab bar, a floating button or bottom action, the timer strip, the helper button: [shell.md](shell.md),
  the phone's fixed stack), safe areas included; the floating button stands on the tab bar clearance at
  `--solo-fab-height`. The viewport meta has `viewport-fit=cover`.
- **Record header.** `<ClientHeader>` is the desktop header from 768 px (no trail: the record's place is the
  command bar's tokens) and below it the nav bar (back
  link, cobalt agent chip, ••• action menu) plus the title (two lines, then an ellipsis) and a tab strip
  that scrolls sideways and keeps the active tab in view. Header actions render once in code: inline on
  desktop, as menu items inside the ••• menu (`useInHeaderMenu`).
- **Primitives** (`components/solo/`): `BottomSheet` (on `ui/sheet` side bottom: close, Gotowe or save
  header (the Anuluj pill, the centred title, the ink submit; no ×), the only scrolling body, footer actions that sit side by side
  while their widths, the gap and 24 px of headroom fit and stack full width with the primary on top
  when they do not, measured after mount), `ActionMenu` / `ActionMenuItem` / `ActionMenuLink` (DropdownMenu
  `look="action"`), `IconButton` (the focus ring on its own edge, so a focused one keeps its 40 or 44 px), `BottomAction` (the screen's main action: below 768 px a 48 px full-width
  button fixed above the tab bar in the surface's main look, an optional small secondary above it, and the page
  clears it; from 768 px both buttons render where the page puts them; Timer, Podsumowanie, Plan jutro, Zadanie,
  Projekt), `ListRow`, `SegmentedControl` (ToggleGroup
  `look="segmented"`; with an `href` on every option it is a nav of links), `ResponsiveSection` (inline from 768 px, a row opening the same children in a sheet
  below), `ResponsiveDialog` (a form in the themed Dialog from 768 px and in a bottom sheet below it, used
  by the close and reopen month dialogs, the journal entry, Nowe zadanie, Zaloguj czas (its subtitle names
  the task and the client, a running timer first shows the overlap step with a close header), the retainer,
  opening balance, client data and contact forms; `fields` spaces form fields, `sheetAction="save"` moves Anuluj and the submit into
  the sheet header, `sheet={false}` keeps the dialog for the old page, and a form opened from outside
  gives the focus back to the control that opened it, or to the ••• trigger when a menu item did),
  `ResponsiveSelect` (the Solo select from 768 px, below it a field that opens a sheet of options), `SoloButton size="touch"` (44 px),
  `IconButtonLink`, `ListCheck` (the 44 px row checkbox), `PhoneNavBar` (the record header's nav bar,
  reused by pushed screens), `ActionMenuItem tone="agent"`, and the mobile icon set (`mobile-icons.tsx`).
  On a page with a `BottomAction` (or a fixed bar marked `data-solo-fab`) a toast rises above it on phones, and while a bottom sheet is open on a phone
  toasts drop in from the top instead (`toaster.module.css` reads the open sheet's `data-side`/`data-state`). Toasts
  keep Sonner's 4 s default; a pointer resting on the toaster pauses it, which is why a headless run that leaves the
  mouse where the toast lands sees it stay. In Night the sheet's top edge is the 2 px `--solo-rule-strong` rule and
  the scrim dims with black, since the sheet shares the page's ink. `touchTargetClass` gives a small text link a
  44 px hit area on phones without changing how it looks (pagination, Dane's links, the ledger's report links). Shared desktop pieces carry their phone look: ledger `KvRow`s are
  the 52 px Mobile / Ledger row, a body `SectionHead` is the Rule head (`phone="plain"` drops the rule and
  reads the meta inline; with or without its action it stays 44 px, the action 15 px), column `KvRow`s
  are the Field row (the label above a 15 px regular value, side by side only from 768 px), `SoloField`s are the Mobile / Text field (label
  above a 48 px field, 17 px so iOS does not zoom), a `ListRow` with `external` is a plain link to a new
  tab.
- **Lista** (Figma 328:818): KLIENCI with the stage count and the open month note, `ListRow`s from the
  page's own `clients` prop (no second query), the search field over its backdrop above the tab bar, and
  the Filtr sheet (Status segments for active, paused and former; Współpraca rows with counts). Each
  choice navigates at once through the same query string; archived stays a desktop tab.
- **Przegląd** (330:1408, 330:5782): the figure block comes first. A retainer shows the balance poster,
  "bilans na" opens the Godziny sheet (ledger, the data-driven bar, the total), and an open month adds
  the note and Zamknij miesiąc; an order client shows Zlecenie without the invoice poster. The ••• menu
  leads with Generuj raport (retainer only), then Kopiuj kontekst and the pin.
- **Zadania** (331:2124, 331:2335, 331:2384): the lane tabs become the "W toku · N ▾" trigger, an action
  menu over the same lane state (`?wszystkie` still expands a lane, and "Wszystkie w toku" is its phone
  row). Rows are touch rows: the checkbox, the due date, Start, or the owner's Stop with the elapsed time
  (from `running_since`, ticking every 15 s, left out of server-rendered markup), and an agent at work shows "claude
  pracuje" and opens the task. + Zadanie floats and opens the same `TaskFormDialog` as a sheet
  (`sheet` prop). Dla agentów moves out of the rail into a sheet the ••• menu opens, beside Kopiuj
  kontekst; the brief command is its first row. An order client gets the same rows for its list,
  Zrobione and the time section. The tab's badge counts what the tab shows
  (`ClientTasksQuery::visibleOpenTasks`: the three lanes, unlisted tasks in To-Do, for a retainer; the whole open
  list for an order), so Backlog never inflates it.
- **Dziennik** (7A, 1454:1463, phone 1454:1977): Praca leads the main column (Starsze pages it) and SMS follows it;
  beside them Projekty (the month in the head, each project with its hours as end meta) and Relacja z klientem (the
  stage line, the sentence since the last visit, the five newest entries cut at two lines, and Cała relacja with the
  count, `journal.notes_total`). A phone reads Projekty, Relacja, Praca, SMS: one DOM, the column first by CSS order.
  Cała relacja opens the sub-view `?view=relationship` (1368:18948): the place bar's third token Relacja, the poster
  Relacja z klientem, every entry in full under its month, 20 a page with Starsze (`ClientJournalQuery::RELATION_PAGE`),
  and beside it Etap relacji with Zmień etap. Durations in the sentence and the rows never break ("6 h 30").
- **Rozliczenia** (334:3308, 334:3502, 334:3611, 334:3721): the desktop tree and the phone tree render
  from the same props and CSS shows one (`.wide` is `display: contents` from 768 px). A retainer shows the
  balance poster: the latest closed month while a later one is open ("bank po wrześniu", the closure and
  the open month in the note), else the newest month ("bank dziś" while it runs), or the month left open
  behind. It opens the Bank godzin sheet (the terms, the months with settlement and report delivery on
  their own lines, Otwórz ponownie on the latest closure, the entry row, Starsze). Below it the report
  waiting to go out (late dot, Szkic · wyślij) or the newest report, then the newest invoice, which opens
  the Faktury sheet (net; rows open Infakt). Otwórz ponownie closes the ledger and opens the reopen sheet;
  when a later report is finalized or sent the phone gets the blocked sheet instead (347:4982: the
  refusal, "Wrzesień zostaje zamknięty", Anuluj and a link to that report, `reopen_blocker` from the same
  query as the refusal). A reopen or a cancel returns to the ledger (the reopen keeps page state). The •••
  menu holds Zmień abonament and, while the rules let it change, Zmień bilans otwarcia; the dialogs live in
  `show.tsx` state so the menu and the desktop section actions open the same one. An order client (335:3956)
  drops the brief on phones and lists FAKTURY · N as rows, gross, each saying what it waits for ("czeka na wpłatę,
  termin 14.10" in accent, "opłacona 10.09", "szkic"; `orderInvoiceLine`); desktop keeps its wording. It has no
  ••• menu.
- **Dane** (335:4147, 347:5037, 347:5096, 347:5178): Field rows, Edytuj moves into the ••• menu (Edytuj dane
  firmy, Nowy kontakt; Edytuj dane for a private person), contacts are rows to their records, and the
  data and contact forms are save-header sheets (Dane firmy, Nowy kontakt); the data form also edits the
  client's notes and region (since 2026-11-12). A private person reads Imię i
  nazwisko and has no NIP on phones, in the rows and in the form as its Typ changes (Nazwa and NIP from
  768 px), and ends at Kontakt when there are no contact records.
- **Poster type and Polish marks.** The poster is the only text set in capitals (`Poster` and the record
  title; `type-roles.test.ts`). Both read `--solo-caps-leading` from `solo.css`, Figma's 88%, with no padding
  above: a first line's accents rise into the space above, and the marks of a two-line title may touch. A
  task name past 60 characters steps down to `--solo-poster-record-long` with its own looser leading
  (`--solo-poster-record-long-leading`, 1), so Ą, Ę and Ó clear the line above. No measuring script. A
  task's name (`Poster size="record"`) is shown whole, never cut at a line count: at the caps leading a
  hidden line's accents share a strip with the last shown line's ogonek. A client's name takes the same
  path (`RecordFrame titleSize="record"`): the task's size and long step, wrapping whole.
- Fonts: two families only ([theming.md](theming.md), Typography). Archivo (variable, `wdth 62` for
  posters) is self-hosted; the mono web faces were removed on 2026-11-12.
  **Switzer** is the official variable woff2 from Fontshare in `public/fonts/switzer/`, preloaded and
  declared inline by `app.blade.php` (`App\Support\SwitzerFont`). Its licence (ITF FFL, copy beside
  the file) allows self-hosting but not publishing the files, nor subsetting or converting them, so
  the folder is export-ignored and the public snapshot loads Switzer from the Fontshare CDN instead.
  Either way the next face is `Switzer Fallback`, Arial with `size-adjust` and ascent, descent and
  line-gap overrides measured from Switzer, so the swap barely moves anything (mobile Lighthouse CLS on
  Lista and Przegląd: 0 self-hosted, 0.02 at most from the CDN).
- Login and the waitlist: one layout in two colours since 2026-11-12, see [shell.md](shell.md), Login and the
  waitlist.

## Zadanie (the task page)

Figma: 1094:40618 (a card, timer and agent running), phone 1094:40855, a CRM task 1094:41102, done 1094:41505,
more menu 1094:47089, delete refused 1094:47408. `/tasks/{task}`, props from `App\Services\Tasks\TaskPage`,
polled every 30 s and on focus (`useLiveReload`), so an agent's timer, brief writes and a fetched card show without
a reload; drafts live in component state and survive it. The place is the client, Zadania, the task. The page
stands on `TileGrid`: the main tiles first, the time tile and the activity beside them from 1100 px.

- **Head tile** (`task-header.tsx`): a chip when the task is done in the CRM, completed on its board or archived,
  the title, the meta line (client · project · do <due>, "po terminie" in vermilion once overdue · Nadrzędne:
  <parent> · Trello ↗ for a card) and the actions: Oznacz jako zrobione (`day.tasks.complete`, so
  `TaskCompletion`; "w CRM" on a card), Na dziś, Kopiuj dla agenta (in the menu on a phone) and "more".
- **More menu** (`task-menu.tsx`): Edytuj opens `TaskFormDialog` in edit mode (the parent choices come as the
  lazy prop `parent_options`), Archiwizuj and Usuń ask first and call `PATCH /tasks/{task}/archived` and
  `DELETE /tasks/{task}`. A delete the server refuses for settled time (`archive_instead`) shows its sentence
  and offers Archiwizuj. An archived task keeps its page and offers Przywróć z archiwum. After a delete the page
  goes to the client's Zadania. A card gets none of the three (`taskMenuItems`), only its Trello link.
- **Czas tile** (`time-section.tsx`): today and the total as two figures (running timers counted in, today in
  vermilion while one runs), the owner's own Start or Stop through `useTimerActions`, Zaloguj czas, and the
  switch "Liczy się w raporcie" (`PATCH /tasks/{task}/reportable`, a card's too). The switch moves at once and
  goes back when the server refuses, showing its reason (a finalized or sent report). A phone also keeps Start
  fixed at the bottom while no timer of the owner's runs.
- **Opis**: Markdown preview (`react-markdown`, raw HTML skipped, links open outside). A CRM task edits in
  place (Edytuj, Podgląd of the draft, `PATCH /tasks/{task}/description` through `TaskWriter`); a card reads
  "Edytuj w Trello ↗" and the endpoint answers 422. On phones a long description is clamped to four lines
  behind Więcej ↓ / Mniej ↑.
- **Checklista** (a card's only), **Brief dla agenta** (the four fields as `KvRow layout="field"`, edited in a
  dialog, `PUT /tasks/{task}/brief`), **Zasoby** (files, images and links as rows saying where each came from;
  Dodaj is a menu from 768 px and a sheet below; CRM uploads and links can be removed) and **Podzadania** are
  open tiles. Opening a card whose cached details are stale queues `RefreshTaskCardDetailsJob`.
- **Aktywność** (`App\Services\Tasks\TaskActivity`): closed time entries, task notes, agent audit rows, agent
  finishes and reopens, and the owner's current finish; owner edits of the brief or description leave no trail.
  The tile shows the three newest rows (a short title, who and when at the end) and the agent chip while an
  agent's timer runs. Cała historia (`?history`) loads every row into a dialog (a sheet on a phone), where a
  logged time row carries its entry and opens `TimeEntryEditDialog`.

## Ustawienia and Kontakty

Figma: Screens, sections Ustawienia (461:7444) and Kontakty (470:7727). Both are top-level pages with their
own rail item. Ustawienia uses `RecordHeader` (the tab is the place token, the compact poster;
`phoneTitle` puts the tab name under the phone back link, and the section list has no back link). Kontakty
opens like Klienci: the + in the phone nav bar, + Kontakt on the row above the poster from 768 px.

- **Ustawienia** (`settings/index`, `SettingsController`, Figma `1543:90041`): the account's settings, the owner's
  only; a member finds no page there (404, `SettingsController::ownerOnly()`) and has no rail item or tab for it.
  `/settings` is the phone's section list (Mój profil on its own first, then one row per tab with its summary, then
  Wyloguj) and, from 768 px, Ogólne; `/settings/{general|leads|forms|messages|integrations}` are the five tabs on one
  row, each sending only its own props. Ogólne: Moduły (a switch or a link per module, what it does in its (i)), the
  client fields tiles (built in, the account's, archived) and, in the side column, Użytkownicy (the old users pages;
  `users.store/update/destroy/restore` unchanged) and how fields work. Leady: the shipped sources read only, custom
  sources added, relabelled (the slug is fixed once created) or removed, each save PATCHing the whole list.
  Formularze: the website forms with the mail sender beside them. Wiadomości: the SMS tiles (Wysyłka SMS, Szablony
  SMS, Reguły SMS), then the e-mail tiles (Wysyłka e-mail, Szablony e-mail; absent with billing off), the SMS queue in
  the side column. Integracje: each provider's state, Konfiguruj (the old edit form in a `ResponsiveDialog`) and
  Synchronizuj, then Tokeny agentów ([agent-crm-interface.md](agent-crm-interface.md#agent-tokens-and-abilities)),
  and in the side column whether OpenAI and the kiwwwi forms are set on the server (never the values). The retired
  tab addresses (`account`, `client-fields`, `sms`, `email`, `agents`, `appearance`, `tasks`, the Polish ones before
  them, `/users*` and `/integrations*`) are rows of `App\Http\LegacyUrls`, each one hop to its new home.
- **Mój profil** (`profile/index`, `ProfileController`, `/profile`): every user's own settings, opened from the
  account menu (and on a phone from the Ustawienia list, or a member's fourth tab). Profil (name, e-mail, password in
  the profile form), Wygląd (language and mode, stored per browser; caps choices from 768 px, a row and a sheet per
  setting below), Pomocnik (two switches saved to the account, `PATCH /profile/appearance`; [shell.md](shell.md), The
  helper), Zadania (the full day warning and, for an owner with billing, billed time on tasks; `PATCH /profile/tasks`),
  then Konto (the account's name, my role; for an owner a link to Użytkownicy, for a member the owner's name) and
  Sesja with Wyloguj.
- **Kontakty** (`contacts/index`): search (every word must match the person, an email, the phone, the city
  or the client's name), the table (Osoba, Klient, E-mail) or phone rows, Solo pagination. Create and edit
  are `ContactDialog`, the form Klient Dane uses, with the Klient select, a folded Adres i notatki and
  Usuń kontakt (or Przywróć for a deleted one) in edit mode. `?contact=new` and `?contact={id}` open it,
  which is where `/contacts/create` and `/contacts/{id}/edit` (and Dane's contact rows) now land.

## Not built yet

- Month card range switch (3 months, year).
- Picks carried across days, tile history before 2026-09-28 (closes only exist from here on).
