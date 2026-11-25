# The shell: rail and search

The redesign "Szyna i wyszukiwarka" (Figma file `5penQt55PcSGuuZ4LRasgd`, page `Redesign · Szyna i wyszukiwarka`,
foundation section `1065:52`). Since 2026-11-11 every signed-in page renders inside one shell, and since 2026-11-12
every screen is rebuilt on its parts: the day views, the task page, the client area, the money area, Kontakty,
Leady, the SMS queue, Ustawienia, the error pages and the first run. Login and the waitlist are bare pages on the
same tokens (Login and the waitlist, below).

## What the shell draws

`layouts/shell-layout` (given to every page by `lib/layout-resolver.tsx`; `auth/login` and `auth/waitlist` stay bare,
and a guest who lands on the error page gets the page alone):

- **Rail** (`components/solo/shell/rail.tsx`), from 768 px: the sections (`useSections()` in
  `tab-bar.tsx`: Dziś, Klienci, Kontakty and, for an owner, Ustawienia; the active one on the cobalt field with the
  filled icon), the running-timer chip (one click stops the timer) and the avatar with the **account menu**
  (Figma `1557:346650`): the name, the e-mail and the role, then Mój profil (`/profile`) and Wyloguj. While `/profile`
  is open the avatar is filled with the selection colour (`--role-selection`, initials in `--role-selection-text`, the
  tokens of a current rail item) and no rail item is lit. Below 768 px the same sections are the **tab bar**
  (`tab-bar.tsx`): a member has no Ustawienia, so a member's fourth tab is Mój profil, drawn as the avatar with the
  initials; an owner reaches Mój profil from the first row of the Ustawienia list, so `/profile` lights Ustawienia.
- **Command bar** (`shell/command-bar.tsx`): the search field and the place in one. It scrolls with the page.
  ⌘K, a click or `openSearch()` puts the cursor in it; the tokens give way to the query, the page dims behind and
  the results open under the bar. Before two letters are typed the panel holds only the keys (from 768 px; the app
  keeps no recent places or shortcuts to list). The request, the result rows and the keys are `useSearch()` in
  `command-palette.tsx`, the one search. The screens the rail has no item for (Leady, and with billing on
  Zamknięcie miesiąca and Przychody) are `places` passed from `shell-layout.tsx`: the browser matches them by name
  and lists them with the results.
- **Pills** (`shell/pills.tsx`): the siblings of the last place level.
- **Timer strip** (`shell/timer-strip.tsx`), phones only: the running timer above the tab bar.
- **Helper** (`shell/helper/`): the dog with the screen's topics, see below.
- The one toaster and the flash messages. A page mounts neither.

The running timer comes from the shared Inertia prop `timer` (`HandleInertiaRequests`, the newest running entry of
the account, `ShellTimer` in `types/index.d.ts`).

There is no breadcrumb row anywhere.

## The place API

A page says where it is in one way: a static `place` on the page component, a pure function of the page's props.
The shell calls it on every render, so it follows partial reloads and works on the server.

```tsx
import { type PlaceResolver } from '@/components/solo/shell/place';

export default function ClientShow() { /* ... */ }

ClientShow.place = (({ client, tab }, { t }) => ({
    tokens: [{ label: client.short_name, href: clients.show({ client: client.id, tab: 'overview' }).url }, { label: t('Billing') }],
    pills: months.map((month) => ({ label: month.label, href: month.url, active: month.current, count: month.open })),
})) satisfies PlaceResolver<ShowProps>;
```

- `tokens: { label, href?, switcher? }[]`: one per level **below the section**. A section's home (Dziś, Klienci,
  Kontakty, Ustawienia) has none. The last token is the page itself and carries no `href`; every token before it
  links to its level. A level with siblings to switch between gives `switcher: (trigger) => node` instead: the token
  becomes a button and the page wraps it in its own switcher (the client token opens `ClientSwitcher`, which keeps
  the tab).
- `pills: { label, href, active?, count?, note?, done?, visit? }[]`: the siblings of the last level (tabs, months, filters, the day
  views). Exactly one is `active`. A pill may instead be `{ label, active?, menu: pill[] }`: a button that opens its
  choices as a menu, for one more filter beside the status pills (Współpraca on Klienci).
  `note` is a word after the label ("w toku"); `done` is a check after it, read out as the given text (a closed day
  on Podsumowanie); `visit` makes the pill a partial visit that keeps the page where it is.
  A page may draw a second row with `<Pills items label>` for a choice of its own: the months of Rozliczenia (in the
  frame's `aside`), the filters of the month close, the basis of Przychody.
- `filter: { label, href(query) }`: a page whose list the command bar can narrow. The results open with one first
  row that visits `href` with the typed words (Klienci: `?search=`); the page shows what it is narrowed to and the
  way to clear it.
- The second argument is `{ t, url, locale }`.
- Which rail item is lit comes from the URL (`tabBarSection()`), not from the place.

A page never draws navigation of its own (no trail, no tab strip for sibling places, no back row). A record page
draws `RecordFrame` (the poster title, the chip above it, its actions); its tabs are pills. No page draws the old
phone back bar (`RecordHeader`) any more.

## Column widths

Every page sits in the 1040 px column: main 640 and side 380, 20 apart (`TileGrid` with two `TileStack`s). A page
with one column (a list, a board) fills it. From 1100 px the side (the grid's `aside`) sticks 32 px from the top while
a long main column scrolls, and a side taller than the screen scrolls by itself; `stickySide={false}` keeps it in the
flow (Billing, until its layout is decided). A page whose phone column interleaves the two columns uses
`TileGrid phoneOrder` with `TileStack place="lead" | "main" | "side" | "tail"`, so the DOM and the Tab order follow
the phone (Details).

## Sections without a rail item

Leady are a pill beside Kontakty (`pages/contacts/place.ts`, `peoplePills`), and the Kontakty rail item is lit on
both. The SMS queue (`/sms`) lights Klienci and has the token `SMS`; its statuses are its pills. Ustawienia's five tabs
(Ogólne, Leady, Formularze, Wiadomości, Integracje) are pills; on a phone `/settings` lists the sections itself, so
its place sets `pillsFrom: 'wide'`. Mój profil has its token and no pills. The month close
and Przychody have no rail item and no pill: the search finds them by name (above). Where they belong in the rail
is an open decision.

## Prefetch

The shell's links fetch their page ahead with Inertia's own `prefetch` (`components/solo/link-prefetch.ts`): the rail,
the tab bar, the command bar's tokens, every `Pill`, `Row` and `TaskRow`, and the client links of the
Klienci and Kontakty rows, the task header and the lead page. `useLinkPrefetch()` picks the
trigger: `hover` where the pointer can hover, `click` (mousedown to mouseup) on touch, where a tap's emulated hover
would send a second request beside its own visit. Never prefetched: a link with a method (logout), an outside URL, a
pill or row with `visit` (a partial reload of one block). A client's record prefetches too: its GET records no view,
the page reports its opening once shown (`useClientView`, data-models.md, ClientView). Nothing prefetches on mount. A link keeps one answer for its life (the current pill asks too;
Inertia skips the page it is on), because Inertia's `Link` breaks its hook order when `prefetch` turns from false to
a trigger. A copy lives 10 s (`defaults.prefetch.cacheFor` in `app.tsx`).

The flush rule: `forgetPrefetchedPages()` in `app.tsx` drops every copy when a non-GET visit starts and when any
visit that is not a prefetch finishes, the poll, the reload after a write and a deferred prop load included. Inertia
clears a prefetch's `prefetch` flag before it finishes, so the rule tells a prefetch by its `Purpose: prefetch` header.
The same function refuses a prefetch of the page a visit is already fetching: Inertia 2's `Link` keeps its 75 ms hover
timer past a click (fixed upstream in 3.x only), and the late prefetch would fetch the page a second time and mount it
again.
Writes outside the router flush too: `jsonRequest` on any method but GET (the timers; `keepPrefetched` only for a
write no page shows, the client view report), the manual time entry, the
lead autosave, and a finished Reverb job. A new raw `fetch` write either reloads through the router or calls
`router.flushAll()` itself. Since `@inertiajs/core` 2.3.29 `flushAll()` also forgets the prefetches still in flight,
so a slow answer to a hover from before a write is never cached (`prefetch-flush.test.ts` runs Inertia's router on a
fake transport to prove it).

A prefetch writes nothing on the server: `App\Support\PrefetchRequest::is()` (Inertia's `Purpose: prefetch`, a
browser's `Sec-Purpose`) is the one check. On a prefetch the flash messages are neither read nor aged
(`reflash()` in `HandleInertiaRequests`, so a save's toast still reaches the page the user is on), and a client's
record records no view (the page reports its opening itself). The one dispatch a prefetch keeps is the task page's
Trello card refresh: a click served from the prefetch cache makes no request, so the hover is the only moment to
start it, and the card is fresh by the time the page is open. It is a queued job for a stale card only, unique per
task while one is in flight (`RefreshTaskCardDetailsJob`, `ShouldBeUnique`), so a mouse crossing a list of cards
starts at most one per card. Any new GET with a write or a dispatch checks `is()` too.

## Tokens

`resources/css/solo.css` holds the Figma variables of collection `A2f`: `role/x` is `--role-x` (light on `:root`,
dark on `.dark`), `space/n`, `radius/n` and `stroke/n` are `--role-space-n`, `--role-radius-n` and
`--role-stroke-n`. `--role-control` is the control height (44 px, 40 from 768 px). `--role-hover` and
`--role-shadow` are derived and have no Figma variable. The Paper `--solo-*` surface tokens now read the roles, so
an unported Solo screen stands on the same ground, tiles and fields in both appearances.

Rules: cobalt is the command bar and the current selection only. Vermilion is running time and over the limit
only (surface `--role-tile-timer`, type `--role-text-over`, which goes inside tiles only). There is no error red:
a field error is a 2 px ink ring and a message. Tiles are never outlined: a tile that holds a figure is a plain tile
and the poster face sets the figure apart. The 2 px ink outline belongs to small controls only (buttons, pills, the
hours bar, the command bar's key badge); fields are filled on tone with no edge ([theming.md](theming.md)). The helper's line and window are white with the one shadow
(`--role-shadow`), no outline.

## Parts

In `components/solo/`, each with a story under `Redesign/…`:

| Part | Where | Note |
|---|---|---|
| Tile, TileHeader, TileRows, TileInset, TileActions | `tile.tsx` | `kind`: `list`, `plain` (any content, a measured figure too), `timer`. No tile is outlined. `TileHeader` names a whole tile in the same label a `GroupHeading` uses |
| GroupHeading | `tile.tsx` | the heading of a group of rows; `note` (date or count), `current` (today: the word beside the note, label and rule in cobalt), `quiet` (a past day), `end`, `size="compact"` and `flush` for a menu or a board column, `as="h3"` under a named tile |
| TileGrid, TileStack, TileNote | `tile.tsx` | a rebuilt page's columns (main plus 380 px from 1100 px), one column of tiles, a secondary line in a tile |
| Row | `row.tsx` | title plus end meta; `check`, `time` (`timeWide`), `meta`, `wideMeta`, `chip`, `action`, `more`, `selected`, `visit`; `stack` for two buttons (under the title on a phone), `wrap` for a row in the side column. Gaps are 12 px on a phone and 16 from 768 px; the mark stands one step closer (8, 12) and the more button two (4, 8), their 44 px targets unchanged. From 768 px a title beside end meta takes at most half the row and the meta the rest, cut first; `rowParts([client, project])` gives each name an equal share before either is cut; a wrapping title keeps its meta whole. `details` (label and value pairs; a missing value is left out, none left means no chevron) folds them under the row behind a chevron before the action, a real button with `aria-expanded` and `aria-controls`, 44 px on a phone and 32 from 768 px, 24 px from the time and to the end action between visible edges (a quiet button's label counts, `data-look="quiet"` and the `--role-control-inset`); the fold is the shared `Fold`, with the motion tokens and `inert` while closed. The pairs sit in a CSS grid with one fixed column per detail from 768 px, counted whether shown or not (`--detail-column`; a phone flows the shown ones two to a line), the list deciding its columns with `listDetails` (a column no row has a value for is left out), `short` for a date or a duration (`--role-detail-short`, text columns `--role-detail-text`); a value is cut with an ellipsis and carries its whole text as the `title`, a `wide` one takes the whole line uncut. A linked title has no arrow and no underline and takes the secondary ink on hover; a row with details clamps its title to two lines on a phone and has no hover band; `running` (ISO start) draws the timer dot and the live time (`Elapsed`) in the time slot; `timeTone="quiet"` is a logged time beside it |
| Notice | `notice.tsx` | a message in a tile or dialog: `lock`, `warn`, `info`; ink on the page ground |
| RowMenu | `row-menu.tsx` | the row's "more" button with its menu |
| KvRow | `kv-row.tsx` | `layout="tile"`: a label and its value at the row's ends; `tone` (`muted`, `over`), `total` for the row the others add up to, `figure` for a value that never breaks (a duration, hours: the label wraps instead) |
| KvRow field | `kv-row.tsx` | `layout="field"`: a secondary label with a longer text, above it on a phone and beside it from 768 px (the brief) |
| ListTable, ListTableHead, ListTableCell, ListTableRow | `list-table.tsx` | a table inside a list tile; a phone keeps the title and the cells not marked `wide`; `full` is from 1100 px, `narrow` below 768 px only; a row is `current` (the one the page is on) or `minor` |
| RecordFrame | `record-frame.tsx` | a record's head: chip, poster title, actions (buttons from 768 px, one main button plus "more" on a phone); `noteKind="line"` for a sentence under the title, `aside` for a row of pills beside it, `titleSize="record"` for a client's name (the task title's size) |
| Stat | `primitives.tsx` | `kind="tile"` with `sub` and `tone` (`over`, `on-colour`) |
| Button | `primitives.tsx` | `SoloButton size="control"` with `primary`, `secondary`, `quiet`, `stop`, `on-colour` |
| IconButton | `icon-button.tsx` | `look="quiet"`, the row's "more" trigger for an `ActionMenu`; `look="outline"` beside secondary buttons |
| Pagination | `inertia-pagination` | `look="tile"`: what is shown, previous, the page, next, inside the list tile |
| Chip, StatusDot | `chip.tsx` | `waiting`, `done`, `open`, `agent` |
| Pill, Pills, Token, CommandBar | `shell/` | drawn by the shell from the place |
| HoursBar | `hours-bar.tsx` | pool and overrun in role colours; `size="tile"` is the 8 px track as wide as its box |
| Month tile, MonthCalendar | `month-card.tsx` | `MonthCard look="tile"`; its button opens the whole month with day cells, a popover from 768 px and a sheet below |
| Dialog, sheet, menu, toast, field | existing | restyled through tokens; use `ResponsiveDialog`, `ActionMenu`, `SoloField` as before. In a dialog footer the Anuluj pill is the quiet button; a confirm (`ConfirmDialog`) is always ink; `DialogContent size="wide"` is 640 px; a field error carries the warning mark |

## Group headings

Every screen reads page title (poster face), then groups of rows. A group heading is a plain label with the row rule
under it; the rows carry the weight and space parts the groups.

- A heading that groups rows (a weekday, a lane, a status, a site, a search group, a board column) is `GroupHeading`:
  the text face at 400, 14 px on every width (`--solo-heading-group`), sentence case, primary colour, then the same
  1 px rule the rows use (`--role-rule`) under it. A row title is 16 px at 600, so the rows win by size and weight.
- Beside the label, 13 px secondary: the date or the count as `note`. What the old head had on the right goes in
  `end` ("0 z 2 zrobione", or the one button of an empty group).
- 32 px above a group and 8 px under its label; the heading's rule is the first row's rule, and a note under a
  heading (an empty day's `TileNote`) keeps its own 12 px inset.
- `TileHeader` names a whole tile (Opis, Czas, Projekty) in the same 14 px label, first line of the tile. A tile with
  one list keeps it. Sibling tiles that split one list (the SMS days, the month close's groups and sites) take
  `GroupHeading`; a group under a named tile takes `as="h3"` (the checklists of a card).
- Where the rows are smaller (a menu, a board column) the heading is `size="compact"` (13 px) with `flush`.
- Tydzień: today's heading (`current`) has the word beside the date and its label and rule in cobalt; past days are
  `quiet`; an empty day is the heading plus one `TileNote` line, never a row.
- Picked 2026-11-12 by eye on the live preview, after five variants on the Figma page `Hierarchia · 2026-11-12`.
  The Figma component `GroupHeading` (1106:53405) is in this final form, and the variable `role/rule-group` is gone.

## The phone's fixed stack

Bottom up: the tab bar (`--solo-tab-bar-height`), a page's bottom action or a fixed bar marked `data-solo-fab` (`--shell-dock`), the
timer strip while a timer runs (`--shell-strip`), the helper's 56 px button 8 px above them while it is switched on for a
phone (`--shell-helper`).
`solo.css` sets the shell variables from what is in the DOM (`:has()`), and `[data-shell]` pads the page end by
their sum, so the last row always scrolls clear of the button. Toasts rise above the dock and the strip.

## The helper (Pomocnik)

Figma page `Pomocnik · tematy` (`1173:44`), accepted 2026-11-18. No chat and no AI call: every screen has a fixed list
of topics, a topic shows its short answer and at most one action, and choosing the action closes the helper. From
768 px the 112 px dog (`--shell-dog`) floats 16 px from the bottom right and the topics open as a 360 px white window
8 px above it; on a phone the dog is a 56 px button fixed above the timer strip (above the tab bar without one) and
the same window is a bottom sheet. One Radix dialog for both, so Escape closes it, the focus stays inside and returns
to the dog. A screen without topics shows no dog.

- On and off: each user switches it per device kind in Mój profil, Pomocnik (`helper_desktop`, on by default;
  `helper_phone`, off by default; `User::PREFERENCES`, saved to the account by `PATCH /profile/appearance` and shared as
  `auth.user.helper`). The shell renders the helper once with `data-desktop` and `data-phone`, and CSS shows it from
  768 px only with the first and below it only with the second (`display: none`, so a hidden helper takes no focus).
  The phone's page-end room (`--shell-helper`) is reserved only while `data-phone` is set; from 768 px a page with the
  dog shown ends 16 + 112 + 24 px above the window's bottom, so neither the dog nor its line covers a row action.

- Topics: `shell/helper/topics.ts`, `SCREEN_TOPICS` by Inertia page name, in the order the list shows them. The ids
  are the help site's topic ids (the docs site's `/t/<id>`, the `topics` frontmatter on branch `feature/user-docs`): never rename
  one. The texts live in the lang files as `helper_question_<id>` and `helper_answer_<id>` (the docs' `short`, at
  most 240 characters); `helper.test.tsx` fails when a topic misses a text in either language, an answer is longer, or
  a text has no screen. To add one: write the docs section first, copy its id and `short` here, then the question.
- Who sees a topic: `when(props)` hides it where its place cannot be opened. `billingOn` reads the shared
  `auth.user.account.features.billing`, which is false for a member as well as for an account with billing off (the
  hours, the report, the retainer, billed time, the month close, invoices); `managesAccount` reads `auth.user.owner`
  (the Integracje and Wiadomości tabs of Ustawienia, resending a text on the SMS queue). The month close page is
  behind `feature:billing`, so its topics need no rule. A member has no Ustawienia and sees no dog on the SMS queue.
- Actions: a link to a place that exists (`{ label, href }`), or `{ label, event }` for a dialog the page owns: the
  component that owns the dialog listens with `useHelperAction(name, handler)` (`helper/actions.ts`). The resolver gets
  the page's props and returns null where the place is missing or already open, with the same condition as the
  page's own button. A dialog on another tab gets a link built with `helperActionHref(href, name)`: it adds
  `?helper=<name>`, and `useHelperAction` on that tab runs the handler once on arrival and drops the query with
  `router.replace`, so a reload does not open it again. Today: Zaloguj czas (task page), Pokaż abonament (the
  Abonament window of Przegląd's Godziny tile, on every tab only where the header's `retainer_terms` says the tile
  has its button: `ClientHeader`, the active month draws on a package and a position exists), Generuj raport (the Raport tile of Rozliczenia, only for a retainer
  client and while the shown month has the button), Nowy wpis (Dziennik). `helper.test.tsx` checks that every event
  has one listener, in the file that renders its dialog.
- The docs link (`Więcej w dokumentacji`) is built from `config('services.docs.url')` (`DOCS_URL`), shared as
  `docsUrl`; while it is empty no docs link is rendered.
- The line: `shell/helper/hints.ts`, one resolver per page, only where the page's own props hold a fact worth
  saying (the free slots of Dziś, the hours over the package on Przegląd, the clients waiting for you on the month
  close). From 768 px it shows while the dog is pointed at or has focus, follows the dog in the DOM so Tab reaches it
  next, and its link opens the helper on its topic. Opening the helper uses it up for the visit; `hidden` is for a
  screen that shows the dog large itself (the first run of Klienci).
- The dog: `shell/dog-still.tsx` is the PNG of a pose (`public/images/helper/`: sunglasses, looking left, right
  or up, surprised). `helper/dog.tsx` shows it first and keeps it where WebGL is missing or the user prefers
  reduced motion; otherwise it loads `helper/dog-model.tsx` (three.js, the landing page's scene and
  `public/models/dog.glb`) as its own chunk, on desktop only, and the model takes the same pose: it follows the
  pointer, looks at its line only while the line shows (`helperPose(hint, lineShown)`), and is surprised at a warning. `canAnimate()` asks for WebGL once and caches the answer: a probe
  on every render opened a new context each time, and past the browser's limit the dog's own was dropped.
  `fluid` makes the dog fill its parent (`size` is then the drawing's resolution) and read the pointer against its
  own middle instead of the corner; `shade` colours the ground shadow on a coloured page.

## Login and the waitlist

`auth/login` and `auth/waitlist` are one layout in two colours, `AuthFrame` in `pages/auth/frame.tsx`: cobalt for
the login, vermilion for the waitlist, the page itself the colour. On a phone the dog head mark, the dog and the form
stack, centred; from 768 px the words and the form sit at the bottom left of the 1040 px column and the large live
dog (`fluid`) fills the right, cut by the edges: its middle stays just below the window's, and on a narrow window
it grows past the width and moves right so the head never covers the form. While a field has the cursor the dog looks at the form with its
glasses up; an error gives the surprised pose (`wow`); after joining the list the glasses stay up. The waitlist's
words are `waitlist_title`, `waitlist_submit` and `waitlist_joined_title`. To see `/waitlist` locally, start the PHP
server with `APP_CLOSED=true WAITLIST_ACCOUNT_ID=1`; without the account id it answers 503 ([setup.md](setup.md),
Closed mode). The build follows the owner's frames "logowanie winner" and "list winner", not the options A to C in
the Figma section `Logowanie i lista · opcje 2026-11-12`.

## Porting a screen

1. Read the Figma frames of the screen (four versions) and the foundation note.
2. Give the page its `place` (tokens and pills) and delete the navigation it drew itself.
3. Build the content from `Tile`, `Row`, `Stat` and `SoloButton size="control"`. Phone first, one DOM, `--role-*`
   tokens only. Main blocks first, then side blocks: from 1100 px a grid of `minmax(0, 1fr) 380px`, gap
   `var(--shell-gap)`.
4. Keep the page's own root as `<SoloSurface day="paper">` (its dialogs and menus take the Solo look from it), but
   drop `min-height`, page margins and the page's toaster: the shell has them.
5. Check 1440, 1100, 768 and 390 px in light and dark.
