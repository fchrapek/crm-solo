# Theming

Three independent axes, all persisted per browser:

| Axis | Values | Storage | Applied as |
|---|---|---|---|
| **appearance** | `light` / `dark` / `system` | `localStorage.appearance` + cookie | `.dark` class on `<html>` |
| **theme** | `paper` (default) / `ink` / `brutal` / `bauhaus` | `localStorage.theme` + cookie | `data-theme` on `<html>` |
| **accent** | `default` (the theme's own) + 10 named | `localStorage.accent` + cookie | `data-accent` on `<html>` |

They compose: every theme works in both appearances. `initializeTheme()` in
`hooks/use-theme.tsx` applies `data-theme` before first paint, so there is no
unthemed state — the default is `DEFAULT_THEME` (`paper`), changed by editing
that one constant. Users switch themes from the sidebar footer, next to the
language and light/dark controls.

## The themes

| Theme | Identity |
|---|---|
| **Paper** (default) | Warm editorial. Ink-brown text rather than black, terracotta accent, off-white cards. |
| **Ink** | Monochrome. Every colour is a grey; meaning is carried by lightness and position, never hue. |
| **Brutal** | Neo-brutalist slab: 2px borders, hard offset shadows, zero radius, uppercase chips, one high-voltage yellow. |
| **Bauhaus** | Primaries and geometry: red accent, hairline rules, tight corners, blue category chips. |

## Typography and icons

`--font-family-sans` (body) and `--font-family-display` (headings) are separate
tokens, plus `--display-weight` / `--display-transform` / `--display-tracking`.
Self-hosted faces, latin-ext for Polish:

| Theme | Body | Display |
|---|---|---|
| paper, ink | Spline Sans | Spline Sans |
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

Four contracts, all failing the build:

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
