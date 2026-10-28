# Day screens

The home screen (`/`) since 2026-09-28, built on the Solo design system (Figma file
`5penQt55PcSGuuZ4LRasgd`, pages `Solo · Foundations` and `Solo · Components`). The first
surface of the planned rewrite; everything else still runs on the old sidebar layout.

## The day as data

- `day_picks` (`DayPick`): tasks picked for a date, **at most 5** (`DayPlanner::MAX_PICKS`),
  from today to the end of next week (`DayPlanner::isPlannable()`, web and agents alike), one row
  per task per date, account-scoped (route binding too). Each
  pick holds one of the five `slot`s, unique per account and date, so the cap holds in the
  database when two requests add a pick at once; `position` stays the display order.
- A pick left unfinished on an earlier day waits on today (`DayPlanner::carried()`, "z czwartku")
  until the task is done, picked again or dismissed; dismissing stamps `dismissed_at` on the task's
  past picks and never deletes them, so a past day's plan stays as it was.
- `day_closes` (`DayClose`): the day's stamp, unique per account and date.
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
- **Month card tiles** (`DayPlanner::tileState`): today = `today` / `stamped` once closed; any
  other closed day = `closed` (weekends too); weekends = `rest`; past weekdays = `open`,
  upcoming ones `future`. The count is closed weekdays over all weekdays. The card fills its column (seven
  square tiles 8 px apart, the head flush with them, no numbers) and keeps two weeks, the one before today's
  and today's (the month's first two in its first week); Dziś adds "Cały miesiąc ↓" for the whole month and
  names the day in the head, Podsumowanie names the month. A closed day is a filled tile with a tick,
  `stamped` keeps the today ring with a filled checkmark-circle. Phones draw no calendar. Podsumowanie's side
  column reads calendar, the Day status line (checkmark-circle or circle) with Zamknij dzień under it before a
  close, then JUTRO (tomorrow's weekday) with one line and the plan action, unboxed. The line is tomorrow's
  plan once there is one, else the open overdue tasks and the oldest due day (`DayPlanner::overdue`, the
  `overdue` prop), else that nothing is planned.

## Views (navigation never writes)

| URL | View |
|---|---|
| `/` | Dziś (paper). The day list stays the default while timers run; Podsumowanie only when closed and nothing runs |
| `/?widok=dzis` | Dziś, even after a close |
| `/?widok=gotowe` | Podsumowanie: "Gotowe?" with the close button before a close, the stamp after |
| `/?widok=timer[&timer=id]` | The vermilion focus view of a running timer (falls back to Dziś) |
| `/jutro` | Plan tomorrow, with Propozycje |
| `/zadania[?na=jutro]` | Full list of open tasks grouped by client; where picks are chosen (Propozycje on Jutro add too) |

The day trail (Dziś → Podsumowanie → Jutro) sits above the poster with no date line on any day screen; the
poster shows the weekday only and the month card names the date. Below 768 px the trail is the
Width=Full `SegmentedControl` (the same three links, `aria-current="step"`, a checkmark-circle on
Podsumowanie once the day is closed); both render and CSS shows one. Wszystkie zadania uses `RecordHeader`
(DZIŚ → WSZYSTKIE ZADANIA, "‹ Dziś" on phones; "‹ Jutro" when picking for tomorrow).

- **Dziś**: the picks in the main column, the month card beside it; phones put the meta (the client) above the
  row's actions and keep "Zakończ dzień →", which desktop leaves to the trail.
- **Plan jutro**: the head spans the page so Propozycje line up with the list. While a slot is free and there is
  something to suggest, the free slots read "3 wolne miejsca · dodaj z propozycji obok" ("niżej" on phones), a
  line says what is left over from today, Propozycje (`DayPlanner::suggestions`: today's unfinished picks first,
  then the soonest due day, then work in hand, three at most) add with "+ Na jutro" through the same pick route
  as the full list, and the action is "Wróć do dnia". With no slot free or nothing to suggest, the free slots
  link to the full list and the action is "Zapisz plan". Rows are the title, the client and Usuń.
- **Wszystkie zadania**: per client the head with its count, three rows (picked ones always show) and
  "+ N więcej"; a row is the title and the project, + Na dziś from 768 px. On phones the whole row picks the
  task and a picked row takes the List row Active fill.
- **Podsumowanie**: one Stat row (zadania or zaplanowane, zalogowane, klientów, "3 h 40"), the picks as Done
  rows (title and time; an unfinished pick keeps an open box and no time) in the tinted box.
- **Timer** (A2): the trail, the task title (sans, two lines at most), client · project, the clock and "od 09:14"
  in the main column; beside it (below on phones) DZIŚ with the day's picks as Queue rows (done with its time,
  "w toku" on the tint, "następne", the rest bare), "dziś razem" (logged today plus running timers since
  midnight) and Stop.

## Frontend

- Tokens: `resources/css/solo.css` (`--solo-*`), surface modes via `data-day="paper|timer|done"`,
  mirroring the Figma `Solo / Surface` modes. Night is the fourth mode: paper under `.dark`
  (`.dark [data-day='paper']`); timer and done keep their colours in both appearances. The token
  linter reads the file as a second source.
- Components: `resources/js/components/solo/` (masthead, primitives, task row, month card,
  day trail/row, stage pill, actor mark, live line, toaster, logos exported from Figma). Pages:
  `resources/js/pages/today/`.
- The day pages render their root as `<SoloSurface day=...>`, and every Solo page after them
  should too. Under it, Dialog, AlertDialog, Popover, Select and DropdownMenu content portal into
  one shared paper surface on body (`useSoloPortalSurface`, Night under dark) and take the Solo
  look; elsewhere they portal to body as before. `<SoloToaster>` gives toasts their own paper
  surface, so they read on vermilion and cobalt. `InertiaPagination look="solo"` is the Solo
  pagination.
- The layout resolver gives `today/*` and `auth/login` no shell; they draw their own masthead.
  So do the Klient record and the task page (`tasks/show`).

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
- **Navigation.** `<Masthead>` renders the desktop header and `<TabBar>`; both list Dziś, Klienci, Kontakty
  and Ustawienia, the views being worked on (owner decision, 2026-10-08). Leady, Finanse and the month close
  are off the menu for now and still open by URL. The app shell layout renders `<TabBar>` too, inside a
  contents-only Solo surface, and hides the sidebar trigger below 768 px. The active tab comes from the URL
  (`tabBarSection`; a page no tab owns lights none). Wyloguj is the avatar menu on desktop and the last row
  of the Ustawienia list on phones. A page that holds the tab bar gets bottom clearance
  (`--solo-tab-bar-clearance`, plus `--solo-fab-clearance` with a floating button), safe areas included;
  the floating button stands on the same tab bar clearance at `--solo-fab-height`, so scrolled to the end
  the last line sits above it. The viewport meta has `viewport-fit=cover`.
- **Record header.** `<ClientHeader>` is the desktop header from 768 px and below it the nav bar (back
  link, cobalt agent chip, ••• action menu) plus the title (two lines, then an ellipsis) and a tab strip
  that scrolls sideways and keeps the active tab in view. Header actions render once in code: inline on
  desktop, as menu items inside the ••• menu (`useInHeaderMenu`).
- **Primitives** (`components/solo/`): `BottomSheet` (on `ui/sheet` side bottom: close, Gotowe or save
  header (the Anuluj pill, the centred title, the ink submit; no ×), the only scrolling body, footer actions that sit side by side
  while their widths, the gap and 24 px of headroom fit and stack full width with the primary on top
  when they do not, measured after mount), `ActionMenu` / `ActionMenuItem` / `ActionMenuLink` (DropdownMenu
  `look="action"`), `IconButton`, `FloatingButton`, `BottomAction` (the screen's main action: below 768 px a 48 px full-width
  button fixed above the tab bar in the surface's main look, an optional small secondary above it, and the page
  clears it; from 768 px both buttons render where the page puts them; Timer, Podsumowanie, Plan jutro, Zadanie,
  Projekt), `ListRow`, `SegmentedControl` (ToggleGroup
  `look="segmented"`; with an `href` on every option it is a nav of links, `width="full"` the phone day trail), `ResponsiveSection` (inline from 768 px, a row opening the same children in a sheet
  below), `ResponsiveDialog` (a form in the themed Dialog from 768 px and in a bottom sheet below it, used
  by the close and reopen month dialogs, the journal entry, Nowe zadanie, Zaloguj czas (its subtitle names
  the task and the client, a running timer first shows the overlap step with a close header), the retainer,
  opening balance, client data and contact forms; `fields` spaces form fields, `sheetAction="save"` moves Anuluj and the submit into
  the sheet header, `sheet={false}` keeps the dialog for the old page, and a form opened from outside
  gives the focus back to the control that opened it, or to the ••• trigger when a menu item did),
  `ResponsiveSelect` (the Solo select from 768 px, below it a field that opens a sheet of options), `SoloButton size="touch"` (44 px),
  `IconButtonLink`, `ListCheck` (the 44 px row checkbox), `PhoneNavBar` (the record header's nav bar,
  reused by pushed screens), `ActionMenuItem tone="agent"`, and the mobile icon set (`mobile-icons.tsx`).
  On a page with a `FloatingButton` or a `BottomAction` a toast rises above it on phones, and while a bottom sheet is open on a phone
  toasts drop in from the top instead (`toaster.module.css` reads the open sheet's `data-side`/`data-state`). Toasts
  keep Sonner's 4 s default; a pointer resting on the toaster pauses it, which is why a headless run that leaves the
  mouse where the toast lands sees it stay. In Night the sheet's top edge is the 2 px `--solo-rule-strong` rule and
  the scrim dims with black, since the sheet shares the page's ink. `touchTargetClass` gives a small text link a
  44 px hit area on phones without changing how it looks (pagination, Dane's links, the ledger's report links). Shared desktop pieces carry their phone look: ledger `KvRow`s are
  the 52 px Mobile / Ledger row, a body `SectionHead` is the Rule head (`phone="plain"` drops the rule and
  reads the meta inline; with or without its action it stays 44 px, the action 15 px), column `KvRow`s
  are the Field row (the label above a 15 px regular value, side by side only from 768 px), `SoloField`s are the Mobile / Text field (label
  above a 48 px field, 17 px so iOS does not zoom), a `ListRow` with `external` is a plain link to a new
  tab, and a `WaitingRow` with a `link` is one row target named by its title.
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
- **Dziennik** (333:2817, 333:3134, 333:3265): the summary sentence, a Relacja z klientem row (the new
  entries in accent) and a Projekty row, then Praca as `ListRow`s with NOWE on new work rows only.
  Relacja pushes its own screen (Dziennik back link, title, stage line, notes and the ROZLICZENIE
  settlement rows, read-only), held in `?relacja`: a client-side Inertia visit (`router.push`), so
  there is no request and no record visit, the browser's back closes it with the scroll restored, and
  the back link pops that entry (or replaces the URL when the screen was opened directly). A note
  written there (or older notes loaded) comes back to Dziennik with that history return, client side;
  any server visit drops the held copy. Closing the screen gives the focus back to the Relacja row. From 768 px
  `?relacja` changes nothing. Projekty opens a Gotowe sheet (hours this month, Trello, + Projekt to the
  old page). + Wpis floats and opens the entry form as a sheet; Starsze pages both lists as before.
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
  data and contact forms are save-header sheets (Dane firmy, Nowy kontakt). A private person reads Imię i
  nazwisko and has no NIP on phones, in the rows and in the form as its Typ changes (Nazwa and NIP from
  768 px), and ends at Kontakt when there are no contact records.
- **Poster type and Polish marks.** Every display caps heading (the record title, `Poster`, the Dziś and
  login titles) uses `--solo-caps-leading` and `--solo-caps-mark-room` from `solo.css`: Archivo's accents
  rise 0.2 em above its capitals and its ogoneks hang 0.21 em under the baseline, so the leading
  (1.1 em plus the gap) clears even an ogonek right over an accent on the next line, and the mark room
  keeps the first line's accents off the trail above. It is one CSS value for every heading, with no
  measuring script. The record title
  sizes itself from its length and width (`--record-title-chars`, `cqi`), from the poster down to the
  compact size, so a long task or client name keeps to about two lines.
- Fonts: Archivo (variable, `wdth 62` for posters) and Geist Mono (agent tags) self-hosted.
  **Switzer** is the official variable woff2 from Fontshare in `public/fonts/switzer/`, preloaded and
  declared inline by `app.blade.php` (`App\Support\SwitzerFont`). Its licence (ITF FFL, copy beside
  the file) allows self-hosting but not publishing the files, nor subsetting or converting them, so
  the folder is export-ignored and the public snapshot loads Switzer from the Fontshare CDN instead.
  Either way the next face is `Switzer Fallback`, Arial with `size-adjust` and ascent, descent and
  line-gap overrides measured from Switzer, so the swap barely moves anything (mobile Lighthouse CLS on
  Lista and Przegląd: 0 self-hosted, 0.02 at most from the CDN).
- Login is the Figma v1 (full wordmark on vermilion).

## Zadanie (the task page)

Figma: desktop 770:56741 (with Podzadania 740:18599), phones 849:20996 (timer running 849:21057). `/tasks/{task}`, props from
`App\Services\Tasks\TaskPage`, polled every 30 s and on focus (`useLiveReload`), so an agent's timer, brief
writes and a fetched card show without a reload; drafts live in component state and survive it.

- **Header**: `RecordHeader` from `client-header.tsx`, the same component and heading the Klient view uses
  (Archivo poster from 768 px, the 28 px two-line title on phones). The trail runs KLIENCI → client → project
  → the task title (the current item, clipped at 180 px); phones keep the back bar. Meta (`TaskMeta`, one
  line at every width): project · do <due> (the accent colour when overdue) · zrobione w CRM or karta
  ukończona when done · Nadrzędne: <parent> as a quiet link · Trello ↗ for a card. Kopiuj dla agenta copies "Pracuj nad zadaniem <id> z CRM
  Solo (crm task <id>)" and toasts Skopiowano; Oznacz jako zrobione w CRM posts to `day.tasks.complete`
  (`TaskCompletion`). On phones both, plus Karta w Trello, are the ••• menu.
- **Czas**: from 768 px the side block: today's minutes as the Stat (`statDuration`: "38 min", then "2 h 52"),
  the total in the head, Start/Stop (with the elapsed time) of the owner's own timer (no `actor_via`, no
  token) through `useTimerActions`, + Zaloguj czas under it. On phones the figures are the Stat row under the
  meta (dziś, łącznie) and Start/Stop with + Zaloguj czas is the bottom action. Today is from
  `LocalCalendar::dayRange`; running timers count in both figures.
- **Opis**: Markdown preview (`react-markdown`, raw HTML skipped, links open outside). A CRM task edits in
  place (Edytuj, Podgląd of the draft, `PATCH /tasks/{task}/description` through `TaskWriter`); a card reads
  "Edytuj w Trello ↗ · Podgląd" (phones: Edytuj w Trello ↗) and the endpoint answers 422. On phones a long
  description (over 200 characters or four lines, `isLongDescription`) is clamped to four lines with CSS
  line-clamp behind Więcej ↓ / Mniej ↑.
- **Collapsed rows** (`SectionToggle`: the List row with a chevron; desktop adds the summary and Pokaż, phones
  show the title, a short end meta (`phoneSummary`: "1 z 3", "4 pola", none for Zasoby) and the chevron): Checklista only when
  the card has one (the CRM has no checklist of its own), read only; Brief dla agenta (the four fields, edited
  in a dialog, `PUT /tasks/{task}/brief` through `TaskBriefWriter` as `web`, confirmed by the owner); Zasoby
  (files, image thumbnails, links; + Dodaj menu on desktop, + Dodaj on the row and the Dodaj zasób sheet on phones; CRM
  uploads and links can be removed, card files and card links are read only). Opening a card whose cached
  details are stale queues `RefreshTaskCardDetailsJob`; the page shows the cache meanwhile.
- **Aktywność** (`App\Services\Tasks\TaskActivity`): closed time entries, task notes, agent audit rows on the
  task and its brief (the empty brief row, confirmations and the audit rows of time entries and notes are
  left out, so each event shows once), agent finishes and reopens from the audit trail, and the owner's
  current finish. Owner edits of the brief or description leave no trail and do not show. Last hour reads "12
  min temu", today the local time, earlier days the date. Each row is one line (when · who · what, cut with an
  ellipsis); the server sends 3 rows (`TaskActivity::PREVIEW`), phones show 1, and Cała historia
  (`?historia`) loads every row at once, wrapping. The cobalt "Codex pracuje · 12 min" line is pinned on top
  while a timer started with an agent token runs.

## Ustawienia and Kontakty

Figma: Screens, sections Ustawienia (461:7444) and Kontakty (470:7727). Both are top-level pages with their
own masthead item. Ustawienia uses `RecordHeader` (trail USTAWIENIA → the tab, the compact poster;
`phoneTitle` puts the tab name under the phone back link, and the section list has no back link). Kontakty
opens like Klienci: the + in the phone nav bar, + Kontakt on the row above the poster from 768 px.

- **Ustawienia** (`settings/index`, `SettingsController`): `/settings` is the phone's section list (one
  row per tab with its summary) and, from 768 px, the Konto tab; `/settings/{konto|wyglad|leady|integracje|agenci}`
  are the tabs, each sending only its own props. The left nav is the same on every tab. Konto: the profile
  and Użytkownicy (the old users pages; `users.store/update/destroy/restore` unchanged). Wygląd: language,
  mode, theme (Paper, Boring SaaS coming soon) and accent, stored per browser as before; caps choices from
  768 px, a row and a sheet per setting below. Leady: the shipped sources read only, custom sources added,
  relabelled (the slug is fixed once created) or removed, each save PATCHing the whole list. Integracje:
  each provider's state, Konfiguruj (the old edit form in a `ResponsiveDialog`) and Synchronizuj, plus a
  line saying whether OpenAI and the kiwwwi forms are set on the server (never the values). Agenci: agent
  tokens ([agent-crm-interface.md](agent-crm-interface.md#agent-tokens-and-abilities)). The old `/users*`
  and `/integrations*` pages redirect to their tab.
- **Kontakty** (`contacts/index`): search (every word must match the person, an email, the phone, the city
  or the client's name), the table (Osoba, Klient, E-mail) or phone rows, Solo pagination. Create and edit
  are `ContactDialog`, the form Klient Dane uses, with the Klient select, a folded Adres i notatki and
  Usuń kontakt (or Przywróć for a deleted one) in edit mode. `?kontakt=new` and `?kontakt={id}` open it,
  which is where `/contacts/create` and `/contacts/{id}/edit` (and Dane's contact rows) now land.

## Not built yet

- The ⌘K search pill from the design (no global search exists).
- Month card range switch (3 months, year).
- Picks carried across days, tile history before 2026-09-28 (closes only exist from here on).
