# Theming

Three independent axes, all persisted per browser:

| Axis | Values | Storage | Applied as |
|---|---|---|---|
| **appearance** | `light` / `dark` / `system` | `localStorage.appearance` + cookie | `.dark` class on `<html>` |
| **theme** | `paper`, `boring-saas` (`ink` / `brutal` / `bauhaus` are retired) | `localStorage.theme` + cookie | `data-theme` on `<html>` |
| **accent** | `default` (the theme's own) + 10 named | `localStorage.accent` + cookie | `data-accent` on `<html>` |

They compose: every theme works in both appearances. The server stamps
`data-theme` on `<html>` from the `theme` cookie (`HandleAppearanceMiddleware`,
validated, unencrypted like `appearance`) and `initializeTheme()` in
`hooks/use-theme.tsx` applies it again on load, so there is no unthemed state — the default is `DEFAULT_THEME` (`paper`), changed by editing
that one constant. Ustawienia · Wygląd (`/settings/wyglad`) sets the language, the mode, the
theme and the accent. It offers Paper and Boring SaaS; a browser that saved a retired theme reads
it as Paper, because `THEMES` does not list it. The CSS of the retired themes is still in
`themes.css`.

## The themes

| Theme | Identity |
|---|---|
| **Paper** (default) | Warm editorial. Ink-brown text rather than black, terracotta accent, off-white cards. |
| **Boring SaaS** | A calm panel: slate greys, white cards on a light grey page, Inter, one quiet cobalt. |
| **Ink** | Monochrome. Every colour is a grey; meaning is carried by lightness and position, never hue. |
| **Brutal** | Neo-brutalist slab: 2px borders, hard offset shadows, zero radius, uppercase chips, one high-voltage yellow. |
| **Bauhaus** | Primaries and geometry: red accent, hairline rules, tight corners, blue category chips. |

## Boring SaaS

One component, two token sets. `themes.css` gives the theme its primitives, and pins exact surfaces
through optional primitives the derived blocks read with today's expression as the fallback
(`--theme-bg-light`, `--theme-card-dark`, `--theme-muted-light`, `--theme-muted-fg-dark`,
`--theme-border-light`, `--theme-input-light`, `--theme-destructive-dark`, `--theme-destructive-fg-dark`
and kin). Paper sets none,
so it resolves exactly as before.

The Solo screens carry their own `--solo-*` tokens. In `solo.css` the `:root[data-theme='boring-saas']`
blocks alias them to the themed `--color-*` tokens, so dark mode and the accent axis follow, and
`:root[data-theme='boring-saas'] [data-day]` outranks `[data-day='timer']`, so a running timer keeps
the calm surface. What Paper hard-codes in a component is a token only this theme sets, read with
Paper's literal as the fallback: `text-transform: var(--solo-label-case, uppercase)`,
`border-bottom: var(--solo-head-rule, 2px) solid ...`, the card set on existing section containers
(`--solo-card`, `--solo-card-pad`, `--solo-card-radius`, `--solo-card-shadow`, `--solo-rule-end` for
the last row), the nav pill (`--solo-nav-pad`, `--solo-nav-active`, `--solo-nav-line`), the header
bar (`--solo-header-bar`), boxed fields (`--solo-boxed`, `--solo-field-width`), buttons
(`--solo-primary`, `--solo-link`, `--solo-control-border`) and popups (`--solo-pop-*`). A new rule
that Paper styles with a literal and SaaS needs to change takes the same form, so Paper never moves.
`--solo-accent` stays the attention colour (red in SaaS); the accent axis drives `--solo-primary`
and `--solo-link`, whose lightness is clamped in oklch so any accent reads as link text.

## Typography and icons

`--font-family-sans` (body) and `--font-family-display` (headings) are separate
tokens, plus `--display-weight` / `--display-transform` / `--display-tracking`.
Self-hosted faces, latin-ext for Polish:

| Theme | Body | Display |
|---|---|---|
| paper, ink | Spline Sans | Spline Sans |
| boring-saas | Inter | Inter, sentence case; clocks tabular |
| brutal | Space Grotesk | Archivo Black, uppercase |
| bauhaus | Outfit | Outfit 800, uppercase, wide tracking |

**Icons stay one package.** 64 files import `lucide-react` directly, so
swapping icon sets per theme would mean an indirection layer over ~230 call
sites — real churn for a cosmetic axis. Themes restyle the strokes instead:
`--icon-stroke` plus `--icon-linecap` / `--icon-linejoin`. Round caps read
hand-drawn; square caps with mitred joins read technical and blocky, which is
most of what a second icon set would buy. If a theme ever genuinely needs
different glyph shapes, the honest move is a single `<Icon name="…">` wrapper —
not per-theme imports.

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

Ink overrides any chosen accent back to grey: one saturated button on an
otherwise monochrome screen is not monochrome.

## How a theme is built

A theme in `resources/css/themes.css` sets **primitives only**:

- `--theme-hue`, `--theme-sat-strong`, `--theme-sat-soft` — the surface family
- `--theme-success` / `-warning` / `-info` / `-destructive` — `"hue sat"` pairs
- `--color-brand*` — the accent
- `--tint-strength`, `--chip-*`, `--card-*`, `--icon-stroke`, `--radius-*` — form

Two shared blocks (`:root[data-theme]:not(.dark)` and `:root[data-theme].dark`)
derive every actual `--color-*` surface from those primitives, adding the
lightness per appearance.

**A theme must never set `--color-*` surfaces directly.** `:root[data-theme=x]`
outranks `.dark`, so a directly-set light canvas survives into dark mode with
light text on it — invisible. Deriving from primitives makes that class of bug
impossible; it broke five of six themes in the first draft.

## Why four status colours re-colour the whole app

`variables.css` defines a **tone palette** — `--tone-{blue,green,amber,red,grey,violet}-{solid,bg,fg}`
plus `--tone-tier-*` and `--tone-diff-*` — where each tone **aliases** a themed
status. Set four colours in a theme and every stage chip, timeline dot, badge,
diff row and pill follows. Ink sets all four to greys, so everything goes grey
at once.

Before this, 75 hardcoded `hsl()` values across 13 component files were
invisible to theming — they stayed green while the theme went monochrome.

## Guards (`bun run lint:tokens`)

Seven contracts, all failing the build:

1. Every `var(--x)` in the CSS layer resolves to a property defined in
   `variables.css` (catches invented token names).
2. **No hardcoded colours in `*.module.css`.** Alpha-only overlays on pure
   black/white are exempt — they carry no hue, so they cannot fight a theme.
3. **The ink theme is achromatic**: every colour it declares must have zero
   saturation. "Only shades of black" is a promise a single stray hue breaks
   invisibly.
4. **Accent text is readable on its accent** (4.5:1). This caught a real bug on
   its first run: a duplicated accent section left stale white-text values
   later in the cascade, so the solved values were being overridden.
5. **The Solo layer is mobile first.** In `solo.css` and `components/solo/`,
   width queries are `min-width` at 768px or 1100px only (see `day-screens.md`,
   Phones).
6. **Pinned surfaces stay readable.** A theme that sets `--theme-bg-light` has its text, muted
   text and attention red checked at 4.5:1 on its page, card and muted band, and the labels on its
   red and brand fills, in both appearances.
7. **Boring SaaS tokens fall back to Paper.** A token only the SaaS blocks of `solo.css` set is
   never read bare outside them, so Paper keeps its own value.

## Verifying contrast (read before writing a probe)

Contrast must be measured in-browser, and the naive probe is wrong in four
distinct ways — each of these produced confident, false results here:

1. **Transparent parents.** Walk up until a non-transparent background is found.
2. **`color-mix()` returns `color(srgb 0.25 0.94 …)`** — 0-1 floats that read as
   near-black if parsed as 0-255. Chrome does *not* normalise this to `rgb()` in
   computed style.
3. **Transitions.** Lanes carry `transition: background 0.12s`, so reading
   immediately after switching returns the *previous* colour. Wait ~250ms.
4. **Alpha compositing.** Tinted pills have semi-transparent backgrounds;
   comparing text against the un-composited colour understates contrast
   dramatically (1.2:1 where the real value was 4.7:1).

Current state: 4 themes x 2 appearances, worst text contrast **4.66:1** across
105 text elements per combination.

## Colour must not be the only signal

Tier badges tint their fill and border but keep the label at
`--color-foreground`. Deriving label colour from the tier hue was fragile — a
theme accent can be near-white (brutal's yellow) or near-black (ink's grey), so
one rule produced anywhere from 1.4:1 to 5.7:1. Marking the badge rather than
the text is readable in every combination and does not rely on colour alone.

Priority dots follow the same rule: only `high` and `medium` carry hue.

## Icons

One component, `components/ui/icon` (`<Icon name size filled>`), sizes 16, 20 and 24 only: 16 beside text and in rows, 20 for icon-only buttons and list items, 24 for navigation and the tab bar. Every name has two glyphs, Fluent UI System Icons on Paper and Lucide on Boring SaaS; both render and the theme's CSS shows one. Paper draws Fluent's outline (regular) glyph at the asked size, and the filled one only for state: the active tab bar item, a timer's play and stop, a pinned pin. Lucide's stroke is set in pixels per size (`--icon-stroke-16/20/24`); Fluent draws its own weight per size. Paper's glyphs come from the `@fluentui/svg-icons` dev dependency through `bun run icons:fluent`, which writes only the used paths into `fluent.ts`; add a name to `GLYPHS` in `scripts/fluent-icons.mjs` (and `FILLED` if it needs a state) and its Lucide glyph to `icon.tsx` to bring in another.
