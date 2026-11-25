# Theming

One theme, Paper (the A2f look), and two independent axes persisted per browser:

| Axis | Values | Storage | Applied as |
|---|---|---|---|
| **appearance** | `light` / `dark` / `system` | `localStorage.appearance` + cookie | `.dark` class on `<html>` |
| **accent** | `default` (the theme's own) + 10 named | `localStorage.accent` + cookie | `data-accent` on `<html>` |

`app.blade.php` stamps `data-theme="paper"` on `<html>` and `initializeTheme()` in
`hooks/use-theme.tsx` sets it again on load, because `themes.css` keys its tokens on that
attribute. Mój profil · Wygląd (`/profile`) sets the language and the mode.

Boring SaaS and the older Ink, Brutal and Bauhaus themes were removed on 2026-11-11, with their
CSS, their fonts and the theme picker. A browser that still holds a `theme` value in localStorage
or a cookie gets Paper: nothing reads either any more.

## Paper

Warm editorial. Ink-brown text rather than black, terracotta accent, off-white cards. The Solo
screens carry their own `--solo-*` tokens in `solo.css`, switched by the day's state
(`[data-day="paper|timer|done"]`) and by `.dark`; on Paper they read the redesign's roles (below).

## The redesign's role tokens

Since 2026-11-11 the screens are being rebuilt on the rail and search redesign ([shell.md](shell.md)). Its colours
are the Figma `role/*` variables as `--role-*` custom properties in `solo.css`: light values on `:root`, dark on
`.dark`, so they follow the appearance switch with no theme block. Spacing, radius and stroke are `--role-space-n`,
`--role-radius-n` and `--role-stroke-n`. New work uses these only. Cobalt (`--role-command-bar`,
`--role-selection`) is the command bar and the current selection; vermilion (`--role-tile-timer`,
`--role-text-over`) is running time and over the limit; the accent axis does not recolour them. The Paper
`--solo-*` surface tokens (ground, blocks, text, fields, overlays, scrim) read the roles, so a screen not yet
rebuilt matches the shell in light and dark.

Tiles are never outlined: every tile has the tile fill (`--role-tile`, one step lighter than the page in dark), and
a measured figure stands out through the poster face. The 2 px ink outline (`--role-outline`,
`--role-stroke-outline`) belongs to small controls: buttons, pills, the hours bar, the command bar's key
badge. The vermilion timer tile is the one coloured tile.

Fields are filled on tone, never outlined (Figma, Shared · Dialogs and fields, B): an input, select, textarea or
date field is the page tone (`--solo-field-bg`, which reads `--role-page`) on its tile or dialog, with a transparent
1 px border that keeps its size. Hover mixes 6 % of the text colour into the fill; focus is a 2 px
`--role-focus-ring` ring inside the box and error a 2 px ink ring with the message under the field, never red;
disabled is 50 % opacity. The empty fill is about 1.16:1 against the tile in both appearances, so the label and
the value or placeholder (`--solo-fg-muted`, 5:1 on the fill) carry the field's shape. Dialogs and confirms have
no edge in either appearance and no rule over the footer: the actions sit on a band of the same tone to the
dialog's edges, Cancel the quiet button and the submit the one strong button. A bottom sheet has no rule under
its header, and its foot actions take the same band.

## Motion

One set of tokens in `variables.css` (Figma, Shared · Transitions, set B), CSS `transition` and keyframes only, no
animation library and no JS timers:

| Token | Value | For |
|---|---|---|
| `--motion-fast` | 120 ms | colour, background, border and opacity of every control: buttons and icon buttons, pills and menu pills, row hover fills, rail icons, a switch's track, a field's hover fill, the page cross-fade |
| `--motion-base` | 160 ms | what moves: a switch's knob, the folded group's caret, menus and popovers opening (opacity and 4 px from the trigger side) |
| `--motion-slow` | 200 ms | dialogs and bottom sheets (scrim fade, the panel 8 px up, the sheet from below) and a folded group's body |

Whatever closes takes the `-exit` value (`--motion-fast-exit`, `--motion-base-exit`, `--motion-slow-exit`, 0.75 of
the entry) and `--ease-exit`; everything else eases with `--ease-standard`. Colour states come from one
zero-specificity rule in `solo.css` (`:where([data-day] *)`), so a component writes its own `transition` only for a
transform or an overlay. Focus rings, a press, a keyboard highlight (`data-highlighted`, `data-selected`) and disabled
change at once. Only colour, background, border colour, box shadow, opacity and transform move, with one exception:
`Fold` (`components/solo/fold.tsx`) opens a body with `grid-template-rows: 0fr` to `1fr`, which needs no measuring, and
everything that opens in place goes through it (`fold.test.tsx` names the exceptions). Its content stays
mounted, so a refused field in a closed group can take the focus once the group opens; a closed body is `inert` and
turns `visibility: hidden` when its fold has ended, so Tab and screen readers skip it.

`prefers-reduced-motion: reduce` sets `--motion-base` and `--motion-slow` to 0 ms in `variables.css`, so nothing
slides, grows or rotates; `--motion-fast` stays, so a colour still fades. `bun run lint:tokens` fails on any
transition or animation in any stylesheet that writes its own time or easing, or reads a variable that is not a
motion token (`scripts/motion-tokens.mjs`); a looping animation (`infinite`, the spinner) keeps its own period.

## Typography and icons

`--font-family-sans` (body) and `--font-family-display` (headings) are separate tokens, both
Spline Sans. The Solo screens use two families and no more (`solo.css`): Archivo for display and
Switzer for everything else, figures included (Switzer's digits share one width, and the shell
root sets `tabular-nums`). Inter stays declared in `fonts.css` as the last named face in the Solo
text stack. No monospace face anywhere: code, the report diff and a token field use the text face
(`base.css` resets the browser's mono default for `code`, `kbd`, `samp` and `pre`).

Names in narrow places are one line with an ellipsis and the native tooltip, never a wrap that grows the
row: `<Row>` cuts its title from 768 px, `ListTableCell` has `truncate`, a command bar token stops at about 30
characters. Every element a stylesheet cuts (an ellipsis or a line clamp) carries its whole text in `title`; a
part given a node reads the text with `fullText()` (`lib/plain-text.ts`, HTML elements only, never a
component's children). `full-text.test.tsx` lists every cutting rule and where its tooltip comes from, so a new
one fails until it is listed. A poster name is never cut: a task title and a client's name share
`<Poster size="record">` and wrap whole. A row's `wrap` is for text that is content (a note, a message).

Eight type roles, each with one intention (`type-roles.test.ts` guards the rules):

| Role | Style | For |
|---|---|---|
| Poster title | display 900, capitals | where you are, one per screen |
| Big number | display 900 | the one figure that sums up a tile |
| Row title | text 16 / 600 | the name of a thing you can open |
| Body | text 14 / 400 | reading text, values, table cells |
| Group label | text 14 / 400, ink, rule under | names a tile, a lane, a weekday, a stat |
| Meta | text 13 / 400, secondary | counts, dates, end meta, column heads, field labels, hints |
| Control label | text 14 / 600 | what you click: buttons, pills, text actions |
| Caption | text 12 / 600 | rail labels, initials |

Capitals belong to the poster alone. The text face has two weights, 400 and 600: 600 means it
opens or acts. Ink is content and secondary is what describes it, so a figure is ink wherever it
stands and a status word beside it is secondary. A count is Meta everywhere; on a selection only
its colour changes.

`<Icon>` draws Fluent glyphs (see Icons below). About 25 older files still import `lucide-react`
directly; `svg.lucide` in `themes.css` sets their stroke from `--icon-stroke`, `--icon-linecap`
and `--icon-linejoin`.

## Accents

Ten accents plus "theme default". Each declares `--accent-hs` / `--accent-l` /
`--accent-fg` primitives; `--color-brand*` is composed from them. They are
**solved, not eyeballed** — `--accent-fg` is whichever of white / near-black
clears 4.5:1 on the fill, at the closest lightness to the intended one where
that holds. Four need dark text, including ember, where white measures 2.81:1
(the pairing this app shipped before 2026-07-30).

The fill is identical in light and dark mode by design. An earlier version
lifted lightness in dark mode for vibrancy, which made white-on-accent worse
exactly where it was already weakest.

## How the theme is built

The Paper block in `resources/css/themes.css` sets **primitives only**:

- `--theme-hue`, `--theme-sat-strong`, `--theme-sat-soft` — the surface family
- `--theme-fg-light`, `--theme-fg-dark`, `--theme-card-light` — the pinned text and card colours
- `--theme-success` / `-warning` / `-info` / `-destructive` — `"hue sat"` pairs
- `--color-brand*` — the accent
- `--tint-strength`, `--chip-*`, `--icon-stroke` — form

Two shared blocks (`:root[data-theme]:not(.dark)` and `:root[data-theme].dark`)
derive every actual `--color-*` surface from those primitives, adding the
lightness per appearance.

**The theme block must never set `--color-*` surfaces directly.** `:root[data-theme=x]`
outranks `.dark`, so a directly-set light canvas survives into dark mode with
light text on it — invisible. Deriving from primitives makes that class of bug
impossible.

## Why four status colours re-colour the whole app

`variables.css` defines a **tone palette** — `--tone-{blue,green,amber,red,grey,violet}-{solid,bg,fg}`
plus `--tone-tier-*` and `--tone-diff-*` — where each tone **aliases** a themed
status. Set four colours in the theme and every stage chip, timeline dot, badge,
diff row and pill follows.

Before this, 75 hardcoded `hsl()` values across 13 component files were
invisible to theming.

## Guards (`bun run lint:tokens`)

Five contracts, all failing the build:

1. Every `var(--x)` in the CSS layer resolves to a property defined in
   `variables.css` or `solo.css` (catches invented token names).
2. **No hardcoded colours in `*.module.css`.** Alpha-only overlays on pure
   black/white are exempt — they carry no hue, so they cannot fight a theme.
3. **Accent text is readable on its accent** (4.5:1). This caught a real bug on
   its first run: a duplicated accent section left stale white-text values
   later in the cascade, so the solved values were being overridden.
4. **The Solo layer is mobile first.** In `solo.css` and `components/solo/`,
   width queries are `min-width` at 768px or 1100px only (see `day-screens.md`,
   Phones).
5. **Motion comes from the tokens.** No transition or animation in any
   stylesheet writes its own duration or easing (see Motion above).

## Verifying contrast (read before writing a probe)

Contrast must be measured in-browser, and the naive probe is wrong in four
distinct ways — each of these produced confident, false results here:

1. **Transparent parents.** Walk up until a non-transparent background is found.
2. **`color-mix()` returns `color(srgb 0.25 0.94 …)`** — 0-1 floats that read as
   near-black if parsed as 0-255. Chrome does *not* normalise this to `rgb()` in
   computed style.
3. **Transitions.** Every colour on a Solo surface fades over `--motion-fast`, so
   reading immediately after switching returns the *previous* colour. Wait ~250ms.
4. **Alpha compositing.** Tinted pills have semi-transparent backgrounds;
   comparing text against the un-composited colour understates contrast
   dramatically (1.2:1 where the real value was 4.7:1).

Last measured with four themes x 2 appearances (before the 2026-11-11 removal):
worst text contrast **4.66:1** across 105 text elements per combination.

## Colour must not be the only signal

Tier badges tint their fill and border but keep the label at
`--color-foreground`. Deriving label colour from the tier hue was fragile: one
rule produced anywhere from 1.4:1 to 5.7:1 depending on accent and appearance. Marking the badge rather than
the text is readable in every combination and does not rely on colour alone.

Priority dots follow the same rule: only `high` and `medium` carry hue.

## Icons

One component, `components/ui/icon` (`<Icon name size filled>`), sizes 16, 20 and 24 only: 16 beside text and in rows, 20 for icon-only buttons and list items, 24 for navigation and the tab bar. It renders one SVG, the Fluent UI System Icons glyph: the outline (regular) one at the asked size, and the filled one only for state: the active rail and tab bar item, a timer's play and stop, a pinned pin. Fluent draws its own weight per size. The glyphs come from the `@fluentui/svg-icons` dev dependency through `bun run icons:fluent`, which writes only the used paths into `fluent.ts`; add a name to `GLYPHS` in `scripts/fluent-icons.mjs` (and `FILLED` if it needs a state) to bring in another.
