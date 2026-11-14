# The shell: rail and search

The redesign "Szyna i wyszukiwarka" (Figma file `5penQt55PcSGuuZ4LRasgd`, page `Redesign · Szyna i wyszukiwarka`,
foundation section `1065:52`). Since 2026-11-11 every signed-in page renders inside one shell, and since 2026-11-12
every screen is rebuilt on its parts: the day views, the task page, the client area, the money area, Kontakty,
Leady, the SMS queue, Ustawienia, the error pages and the first run. Login and the waitlist are bare pages on the
same tokens (Login and the waitlist, below).

## What the shell draws

`layouts/shell-layout` (given to every page by `lib/layout-resolver.tsx`; `auth/login` and `auth/czesc` stay bare,
and a guest who lands on the error page gets the page alone):

- **Rail** (`components/solo/shell/rail.tsx`), from 768 px: the dog, the four sections (`useSections()` in
  `tab-bar.tsx`, the active one on the cobalt field with the filled icon), the running-timer chip (one click stops
  the timer) and the owner's menu. Below 768 px the same sections are the **tab bar** (`tab-bar.tsx`).
- **Command bar** (`shell/command-bar.tsx`): the search field and the place in one. It scrolls with the page.
  ⌘K, a click or `openSearch()` puts the cursor in it; the tokens give way to the query, the page dims behind and
  the results open under the bar. Before two letters are typed the panel holds only the keys (from 768 px; the app
  keeps no recent places or shortcuts to list). The request, the result rows and the keys are `useSearch()` in
  `command-palette.tsx`, the one search. The screens the rail has no item for (Leady, and with billing on
  Zamknięcie miesiąca and Przychody) are `places` passed from `shell-layout.tsx`: the browser matches them by name
  and lists them with the results.
- **Pills** (`shell/pills.tsx`): the siblings of the last place level.
- **Timer strip** (`shell/timer-strip.tsx`), phones only: the running timer above the tab bar.
- **Helper** (`shell/helper/`): a mock, see below.
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
    tokens: [{ label: client.short_name, href: clients.show({ client: client.id, tab: 'przeglad' }).url }, { label: t('Billing') }],
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
with one column (a list, a board) fills it.

## Sections without a rail item

Leady are a pill beside Kontakty (`pages/contacts/place.ts`, `peoplePills`), and the Kontakty rail item is lit on
both. The SMS queue (`/sms`) lights Klienci and has the token `SMS`; its statuses are its pills. Ustawienia's tabs
are pills; on a phone `/settings` lists the sections itself, so its place sets `pillsFrom: 'wide'`. The month close
and Przychody have no rail item and no pill: the search finds them by name (above). Where they belong in the rail
is an open decision.

## Tokens

`resources/css/solo.css` holds the Figma variables of collection `A2f`: `role/x` is `--role-x` (light on `:root`,
dark on `.dark`), `space/n`, `radius/n` and `stroke/n` are `--role-space-n`, `--role-radius-n` and
`--role-stroke-n`. `--role-control` is the control height (44 px, 40 from 768 px). `--role-hover` and
`--role-shadow` are derived and have no Figma variable. The Paper `--solo-*` surface tokens now read the roles, so
an unported Solo screen stands on the same ground, tiles and fields in both appearances.

Rules: cobalt is the command bar and the current selection only. Vermilion is running time and over the limit
only (surface `--role-tile-timer`, type `--role-text-over`, which goes inside tiles only). There is no error red:
a field error is a 2 px ink edge and a message. Tiles are never outlined: a tile that holds a figure is a plain tile
and the poster face sets the figure apart. The 2 px ink outline belongs to small controls only (buttons, pills, the
hours bar, fields, the helper's bubble and window, the command bar's key badge).

## Parts

In `components/solo/`, each with a story under `Redesign/…`:

| Part | Where | Note |
|---|---|---|
| Tile, TileHeader, TileRows, TileInset, TileActions | `tile.tsx` | `kind`: `list`, `plain` (any content, a measured figure too), `timer`. No tile is outlined. `TileHeader` names a whole tile in the same label a `GroupHeading` uses |
| GroupHeading | `tile.tsx` | the heading of a group of rows; `note` (date or count), `current` (today: the word beside the note, label and rule in cobalt), `quiet` (a past day), `end`, `size="compact"` and `flush` for a menu or a board column, `as="h3"` under a named tile |
| TileGrid, TileStack, TileNote | `tile.tsx` | a rebuilt page's columns (main plus 380 px from 1100 px), one column of tiles, a secondary line in a tile |
| Row | `row.tsx` | title plus end meta; `check`, `time` (`timeWide`), `meta`, `wideMeta`, `chip`, `action`, `more`, `selected`, `visit`; `stack` for two buttons (under the title on a phone), `wrap` for a row in the side column. From 768 px the meta is cut before the title; a wrapping title keeps its meta whole |
| Notice | `notice.tsx` | a message in a tile or dialog: `lock`, `warn`, `info`; ink on the page ground |
| RowMenu | `row-menu.tsx` | the row's "more" button with its menu |
| KvRow | `kv-row.tsx` | `layout="tile"`: a label and its value at the row's ends; `tone` (`muted`, `over`), `total` for the row the others add up to |
| KvRow field | `kv-row.tsx` | `layout="field"`: a secondary label with a longer text, above it on a phone and beside it from 768 px (the brief) |
| ListTable, ListTableHead, ListTableCell, ListTableRow | `list-table.tsx` | a table inside a list tile; a phone keeps the title and the cells not marked `wide`; `full` is from 1100 px, `narrow` below 768 px only; a row is `current` (the one the page is on) or `minor` |
| JournalEntry | `journal-entry.tsx` | a dated entry: meta, kind, the Nowe chip, the text, a "more" menu |
| RecordFrame | `record-frame.tsx` | a record's head: chip, poster title, actions (buttons from 768 px, one main button plus "more" on a phone); `noteKind="line"` for a sentence under the title, `aside` for a row of pills beside it |
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
timer strip while a timer runs (`--shell-strip`). `solo.css` sets the shell variables from what is in the DOM
(`:has()`), and `[data-shell]` pads the page end by their sum, so the last row always scrolls clear. The helper
button is neither fixed nor sticky: it ends the page in the flow and scrolls with it, so it never covers a row (a
sticky button sat on the rows' end buttons while they scrolled under it). Toasts rise above the dock and the strip.

## The helper (a mock)

A floating dog bottom right from 768 px and a button at the page end on a phone. Closed it may show one hint
line with one action, from 768 px and only while the dog is pointed at or has focus (shown on open it covered the
side column's controls); the hint follows the dog in the DOM, so Tab reaches its action next; opened it is a window (a panel on desktop, a bottom
sheet on a phone) of example content with a "makieta" chip and a disabled field. It sends nothing and keeps no
state beyond which window is open.

- Scenes: `shell/helper/hints.ts`, one resolver per Inertia page name: the hint line, the window its action and
  the dog open, the links of the client window, and `hidden` for a screen that shows the dog large itself. Add a
  page's line there. A line states nothing about real data unless it is computed from the page's own props (the
  free slots of Dziś, the hours over the package on Przegląd).
- Windows: `shell/helper/windows.tsx`: `plan` (made-up tasks with a reason), `report` (a step list), `client`
  (links to the client's tabs). A chip either opens another window or is drawn only (`aria-disabled`).
- The dog: `shell/dog-still.tsx` is the PNG of a pose (`public/images/helper/`: sunglasses, looking left, right
  or up, surprised). `helper/dog.tsx` shows it first and keeps it where WebGL is missing or the user prefers
  reduced motion; otherwise it loads `helper/dog-model.tsx` (three.js, the landing page's scene and
  `public/models/dog.glb`) as its own chunk, on desktop only, and the model takes the same pose: it follows the
  pointer, looks at its line only while the line shows (`helperPose(hint, lineShown)`), and is surprised at a warning. `canAnimate()` asks for WebGL once and caches the answer: a probe
  on every render opened a new context each time, and past the browser's limit the dog's own was dropped.
  `fluid` makes the dog fill its parent (`size` is then the drawing's resolution) and read the pointer against its
  own middle instead of the corner; `shade` colours the ground shadow on a coloured page.

## Login and the waitlist

`auth/login` and `auth/czesc` are one layout in two colours, `AuthFrame` in `pages/auth/frame.tsx`: cobalt for
the login, vermilion for the waitlist, the page itself the colour. On a phone the wordmark, the dog and the form
stack, centred; from 768 px the words and the form sit at the bottom left of the 1040 px column and the large live
dog (`fluid`) fills the right, cut by the edges: its middle stays just below the window's, and on a narrow window
it grows past the width and moves right so the head never covers the form. While a field has the cursor the dog looks at the form with its
glasses up; an error gives the surprised pose (`wow`); after joining the list the glasses stay up. The waitlist's
words are `waitlist_title`, `waitlist_submit` and `waitlist_joined_title`. To see `/czesc` locally, start the PHP
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
