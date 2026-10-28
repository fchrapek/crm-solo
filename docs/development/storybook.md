# Storybook

A component catalogue outside the app: every state of a component on one page, in every theme, without a backend or a database.

```bash
bun run storybook         # http://localhost:6006
bun run build:storybook   # static copy in storybook-static/ (gitignored)
```

## Where things live

- `.storybook/main.ts`: finds `resources/js/**/*.stories.tsx`.
- `.storybook/vite.config.ts`: React and the `@` alias only. The app's `vite.config.ts` is not used, because the Laravel and Wayfinder plugins need PHP.
- `.storybook/preview.tsx`: loads `app.css`, the lang files and a decorator that wraps every story in a `SoloSurface`.
- Stories sit next to their component: `components/solo/stage-pill.tsx` has `stage-pill.stories.tsx`. A file that holds several components gets one stories file per component, named after both: `primitives.stat.stories.tsx`, `task-row.queue.stories.tsx`.

## Title groups

The tree mirrors the sections of the Figma library (page Components · A2f), so a component is found under the same name in both.

| Group | Holds | Figma |
|---|---|---|
| `Solo/…` | Shared primitives: Button, Stage pill, Field, Section head, Section toggle, Poster, Stat, Segmented control, Bottom action, Bottom sheet, Month card, Day status, Agent chip, Live line, Actor mark, Icon | The A2f / primitives of the same name (Stat covers Stat and Stat row, Bottom sheet its three headers, Month card its Day tiles) |
| `Zadania/…` | The task views: Task row, Done row, Queue row, Task meta, the Zadanie sections (`Zadania/Zadanie/Czas`), the Zaloguj czas and Nowe zadanie dialogs | A2f / Task row, Done row, Queue row, Task meta; the Zadanie, Zaloguj czas and Nowe zadanie frames |
| `Dziś/…` | Day-screen parts: Day trail, Propozycje, Free slots, Timer queue, Podsumowanie list | A2f / Day trail, Free slots; the Plan jutro, Timer A2 and Podsumowanie frames |

A row component has its own entry per Figma variant set (Task row, Done row, Queue row) even when the code is one `TaskRow` with a `kind`. A composed part of a day screen (Timer queue, Podsumowanie list) sits under `Dziś/` beside the rows it is made of.

## Toolbar

Theme, accent, appearance, language and day (Paper, timer running, day closed) are toolbar globals. They go through the app's own `applyTheme`, `applyAccent` and `applyAppearance`, so a story looks the way the screen does.

The viewport button picks Phone (390), Tablet (768) or Desktop (1280), one size per side of the app's breakpoints. Stories open on Phone, mobile first. The frame's width drives both the CSS media queries and `useSoloWide()`, so a `ResponsiveDialog` turns into a bottom sheet on Phone as it does on a real phone.

All of these can also be set in a URL: `iframe.html?id=solo-stage-pill--every-stage&globals=appearance:dark;locale:en;viewport.value:desktop`.

## Writing a story

- One story per state that matters (empty, long Polish text, over the limit), and one story that shows them all side by side.
- Use fictional data only, the same rule as DemoSeeder.
- Inertia's `Link`, `router` and the Wayfinder routes render without a backend (a click goes nowhere), so a story needs the generated routes (`php artisan wayfinder:generate`) but no server. A component that calls `usePage()` needs a fake Inertia context first, so stories cover a page's sections, not the page itself.
- A page's sections share one fixtures file next to them (`pages/tasks/components/task-page/story-fixtures.ts`) with a fixed clock, so "12 min temu" reads the same every time, and render at the record split's widths (`story-columns.tsx`: main 760, side 380).
- A story that needs a surface or a width sets it as a story global, not with a wrapper: `globals: { day: 'done' }` for the accent surfaces, `globals: { viewport: { value: 'desktop', isRotated: false } }` for what only exists from 768 px (Month card, the text Day trail, a dialog instead of a sheet).
- A component that fetches on open (Zaloguj czas asks `/time-entries/running`) gets a `beforeEach` that stubs `fetch` for that URL and restores it; a never-settling promise shows the loading step.
- Day-screen fixtures live in `pages/today/story-fixtures.ts`.
