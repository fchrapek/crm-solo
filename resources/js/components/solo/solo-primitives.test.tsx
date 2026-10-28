import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup, renderToString } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import InertiaPagination from '@/components/inertia-pagination';
import { Select, SelectTrigger, SelectValue } from '@/components/ui/select';
import pl from '../../../../lang/pl.json';
import { ActorMark, actorOf, initials } from './actor-mark';
import { LiveLine } from './live-line';
import { currentWeek, MonthCard, shownWeeks, type MonthData } from './month-card';
import { EmptyBlock, SectionHead, SoloButton } from './primitives';
import { StagePill } from './stage-pill';
import { soloPortalRoot, SoloSurface, useInSoloSurface, useSoloPortalSurface } from './surface';
import { TaskRow } from './task-row';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

describe('StagePill', () => {
    test('the pill takes the status colour of its stage: active green, paused amber, churned red', () => {
        expect(renderToStaticMarkup(<StagePill stage="active" />)).toContain('data-tone="green"');
        expect(renderToStaticMarkup(<StagePill stage="paused" />)).toContain('data-tone="amber"');
        expect(renderToStaticMarkup(<StagePill stage="churned" />)).toContain('data-tone="red"');
    });

    test('the retired stages that history still carries keep their own tone', () => {
        expect(renderToStaticMarkup(<StagePill stage="prospect" />)).toContain('data-tone="neutral"');
        expect(renderToStaticMarkup(<StagePill stage="offer_sent" />)).toContain('data-tone="blue"');
    });

    test('the pill shows the stage label in the reader language', () => {
        expect(renderToStaticMarkup(<StagePill stage="paused" />)).toContain('Wstrzymany');
    });
});

describe('ActorMark', () => {
    test("any write with actor_via set is an agent, the owner's own bridge runs included", () => {
        expect(actorOf('cli')).toBe('agent');
        expect(actorOf('cli-remote')).toBe('agent');
        expect(actorOf('mcp')).toBe('agent');
    });

    test('a write without actor_via is a person', () => {
        expect(actorOf(null)).toBe('person');
        expect(actorOf(undefined)).toBe('person');
        expect(actorOf('')).toBe('person');
    });

    test('an agent write shows the square mark with the agent initial', () => {
        const html = renderToStaticMarkup(<ActorMark via="cli-remote" name="Filip Chrapek" />);
        expect(html).toContain('data-actor="agent"');
        expect(html).toContain('>C</span>');
        expect(html).toContain('aria-label="Claude (agent)"');
    });

    test('a web write shows the person initials', () => {
        const html = renderToStaticMarkup(<ActorMark via={null} name="Filip Chrapek" />);
        expect(html).toContain('data-actor="person"');
        expect(html).toContain('>FC</span>');
        expect(html).toContain('aria-label="Filip Chrapek"');
    });

    test('initials take the first letters of at most two words', () => {
        expect(initials('anna maria nowak')).toBe('AM');
        expect(initials('  Filip  ')).toBe('F');
        expect(initials('')).toBe('');
    });
});

describe('SectionHead', () => {
    test('an action takes the place of the counter', () => {
        const html = renderToStaticMarkup(<SectionHead kind="body" label="Projekty" meta="2" action={<a href="/x">Nowy projekt</a>} />);
        expect(html).toContain('Nowy projekt');
        expect(html).not.toContain('>2<');
    });

    test('without an action the counter shows', () => {
        const html = renderToStaticMarkup(<SectionHead label="Dziś" meta="2 z 5" />);
        expect(html).toContain('2 z 5');
    });

    test('inside a listbox label it renders without a heading', () => {
        const html = renderToStaticMarkup(<SectionHead kind="body" as="span" label="Współpraca" />);
        expect(html).not.toContain('<h2');
        expect(html).toContain('<span');
    });
});

describe('TaskRow', () => {
    const task = { task_id: 1, name: 'Przepisanie strony', client: null, project: null, done: false };

    test('a due date gets its own cell before the action', () => {
        const html = renderToStaticMarkup(<TaskRow task={task} kind="client" due="30.10" trailing={<SoloButton variant="text">Start</SoloButton>} />);
        expect(html.indexOf('30.10')).toBeGreaterThan(-1);
        expect(html.indexOf('30.10')).toBeLessThan(html.indexOf('Start'));
    });

    test('a row without a due date has no due cell', () => {
        const html = renderToStaticMarkup(<TaskRow task={task} />);
        expect(html.match(/<div/g)).toHaveLength(1);
        expect(renderToStaticMarkup(<TaskRow task={task} due="30.10" />).match(/<div/g)).toHaveLength(2);
    });

    test('the title opens the task page, the checkbox and the row actions stay buttons of their own', () => {
        const html = renderToStaticMarkup(<TaskRow task={task} onComplete={() => undefined} trailing={<SoloButton>Do planu</SoloButton>} />);

        expect(html).toMatch(/<a[^>]*href="\/tasks\/1"[^>]*><span>Przepisanie strony<\/span><\/a>/);
        expect(html.match(/<a /g)).toHaveLength(1);
        expect(html).toMatch(/<button[^>]*aria-label="Oznacz jako zrobione"/);
        expect(html).toMatch(/<button[^>]*>Do planu<\/button>/);
    });

    test('a row that leads elsewhere keeps its link, and a timer with no task links nowhere by default', () => {
        expect(renderToStaticMarkup(<TaskRow task={task} href="/?widok=timer&timer=9" />)).toContain('href="/?widok=timer&amp;timer=9"');
        expect(renderToStaticMarkup(<TaskRow task={{ ...task, task_id: 0 }} />)).not.toContain('<a ');
    });

    test('a title stays on one line with an ellipsis and carries the full name as its tooltip', async () => {
        const css = await Bun.file(new URL('./task-row.module.css', import.meta.url)).text();
        const title = css.match(/\n\.title \{([^}]*)\}/)?.[1] ?? '';
        const text = css.match(/\n\.titleText \{([^}]*)\}/)?.[1] ?? '';

        expect(title).toContain('white-space: nowrap;');
        expect(text).toContain('text-overflow: ellipsis;');
        expect(text).toContain('overflow: hidden;');
        expect(renderToStaticMarkup(<TaskRow task={task} />)).toMatch(/<p[^>]*title="Przepisanie strony"/);
        expect(renderToStaticMarkup(<TaskRow task={{ ...task, task_id: 0 }} />)).toMatch(/<p[^>]*><span>Przepisanie strony<\/span><\/p>/);
    });

    test('the title cuts its text inside the link, never the link, so the phone row keeps the whole hit area', async () => {
        const css = await Bun.file(new URL('./task-row.module.css', import.meta.url)).text();
        const clipping = [...css.matchAll(/\n *([^\n{}]*\.title(?![A-Za-z])[^\n{}]*) \{([^}]*)\}/g)].filter(([, , body]) => /overflow:/.test(body));

        expect(clipping).toEqual([]);
        expect(css).toMatch(/\.day \.title \{\s*grid-area: title;\s*position: relative;/);
    });

    test('the link covers the whole text column on its row padding and draws its focus ring there', async () => {
        const css = await Bun.file(new URL('./task-row.module.css', import.meta.url)).text();

        expect(css).toMatch(/\.text \{\s*position: relative;/);
        expect(css).toMatch(
            /\.titleLink::after \{\s*content: '';\s*position: absolute;\s*inset: calc\(-1 \* var\(--row-pad\)\) calc\(-1 \* var\(--solo-space-2\)\);/,
        );
        expect(css).toMatch(/\.titleLink:focus-visible::after \{\s*outline: 2px solid var\(--solo-focus, var\(--solo-fg\)\);/);
        expect(css).toMatch(/\.row \{[^}]*padding-block: var\(--row-pad\);/);
    });
});

describe('LiveLine', () => {
    test('renders the label next to the agent mark in the requested size', () => {
        const html = renderToStaticMarkup(<LiveLine size="sm">claude</LiveLine>);
        expect(html).toContain('data-size="sm"');
        expect(html).toContain('claude');
    });
});

describe('SoloSurface', () => {
    function Probe() {
        return <span>{useInSoloSurface() ? 'solo' : 'plain'}</span>;
    }

    test('tells overlays inside a Solo page to wear the Solo surface', () => {
        expect(
            renderToStaticMarkup(
                <SoloSurface day="paper">
                    <Probe />
                </SoloSurface>,
            ),
        ).toContain('solo');
        expect(
            renderToStaticMarkup(
                <SoloSurface day="timer">
                    <Probe />
                </SoloSurface>,
            ),
        ).toContain('data-day="timer"');
    });

    test('outside a Solo page overlays keep the app look', () => {
        expect(renderToStaticMarkup(<Probe />)).toBe('<span>plain</span>');
    });
});

describe('soloPortalRoot', () => {
    function fakeDocument() {
        const appended: HTMLElement[] = [];
        const doc = {
            createElement: () => ({ dataset: {}, style: {}, isConnected: true }) as unknown as HTMLElement,
            body: { appendChild: (el: HTMLElement) => appended.push(el) },
        } as unknown as Pick<Document, 'createElement' | 'body'>;

        return { doc, appended };
    }

    test('overlays under a Solo page portal into one paper surface on body that draws no box', () => {
        const { doc, appended } = fakeDocument();
        const root = soloPortalRoot(doc)!;
        expect(root.dataset.day).toBe('paper');
        expect(root.style.display).toBe('contents');
        expect(soloPortalRoot(doc)).toBe(root);
        expect(appended).toHaveLength(1);
    });

    test('without a document (server render) there is no container, so Radix keeps its default', () => {
        expect(soloPortalRoot(undefined)).toBeUndefined();
    });

    test('a server render hands out no portal node even where a document exists, so hydration matches it', () => {
        const { doc } = fakeDocument();
        const real = globalThis.document;
        Object.assign(globalThis, { document: doc });
        try {
            let seen: HTMLElement | undefined | null = null;
            function Probe() {
                seen = useSoloPortalSurface();
                return null;
            }
            renderToString(
                <SoloSurface day="paper">
                    <Probe />
                </SoloSurface>,
            );
            expect(seen).toBeUndefined();
        } finally {
            Object.assign(globalThis, { document: real });
        }
    });
});

describe('InertiaPagination solo look', () => {
    const links = [
        { url: null, label: '&laquo; Previous', active: false },
        { url: '/klienci?page=1', label: '1', active: true },
        { url: '/klienci?page=2', label: '2', active: false },
        { url: '/klienci?page=3', label: '3', active: false },
        { url: '/klienci?page=2', label: 'Next &raquo;', active: false },
    ];
    const html = renderToStaticMarkup(<InertiaPagination look="solo" links={links} summary="1-10 z 22" />);

    test('is a labelled navigation with the range line', () => {
        expect(html).toStartWith('<nav');
        expect(html).toContain('aria-label="Stronicowanie"');
        expect(html).toContain('1-10 z 22');
    });

    test('marks the current page and does not link it', () => {
        expect(html).toMatch(/<span[^>]*aria-current="page"[^>]*>1<\/span>/);
        expect(html).not.toContain('href="/klienci?page=1"');
        expect(html).toContain('href="/klienci?page=3"');
    });

    test('on the first page Poprzednia is inert text and Następna links on', () => {
        expect(html).toMatch(/<span[^>]*aria-disabled="true"[^>]*>← Poprzednia<\/span>/);
        expect(html).toMatch(/<a[^>]*href="\/klienci\?page=2"[^>]*>Następna →<\/a>/);
    });

    test('a single page renders nothing', () => {
        expect(renderToStaticMarkup(<InertiaPagination look="solo" links={[links[0], links[1], links[4]]} />)).toBe('');
    });
});

describe('Select solo-pill look', () => {
    test('the filter pill ends in a text arrow instead of the chevron icon', () => {
        const html = renderToStaticMarkup(
            <Select value="all">
                <SelectTrigger look="solo-pill">
                    <SelectValue>Filtr: wszyscy</SelectValue>
                </SelectTrigger>
            </Select>,
        );

        expect(html).toContain('Filtr: wszyscy');
        expect(html).toContain('↓');
        expect(html).not.toContain('<svg');
    });
});

describe('SoloToaster on phones', () => {
    test('toasts sit above the tab bar below 768 px and keep the desktop 24 px from it', async () => {
        const { SOLO_TOAST_OFFSET } = await import('./toaster');
        const css = await Bun.file(new URL('../../../css/solo.css', import.meta.url)).text();
        const [phone, desktop] = [css.indexOf('--solo-toast-offset: var(--solo-tab-bar-clearance);'), css.indexOf('--solo-toast-offset: 24px;')];

        expect(SOLO_TOAST_OFFSET).toEqual({ bottom: 'var(--solo-toast-offset)' });
        expect(phone).toBeGreaterThan(-1);
        expect(desktop).toBeGreaterThan(css.indexOf('@media (min-width: 768px)'));
    });

    test('on a page with a floating button or a bottom action toasts rise above it on phones and stay 24 px from the edge on desktop', async () => {
        const css = await Bun.file(new URL('../../../css/solo.css', import.meta.url)).text();
        const fab = css.indexOf(
            ':root:has([data-solo-fab]) {\n    --solo-toast-offset: calc(var(--solo-tab-bar-clearance) + var(--solo-fab-clearance));',
        );
        const bar = css.indexOf(
            ':root:has([data-solo-bottom-action]) {\n    --solo-toast-offset: calc(var(--solo-tab-bar-clearance) + var(--solo-bottom-action-clearance));',
        );
        const desktop = css.indexOf('        --solo-toast-offset: 24px;', bar);
        const reset = css.slice(css.lastIndexOf('@media (min-width: 768px)', desktop), desktop);

        expect(fab).toBeGreaterThan(-1);
        expect(bar).toBeGreaterThan(fab);
        expect(reset).toContain(':root:has([data-solo-fab]),');
        expect(reset).toContain(':root:has([data-solo-bottom-action]),');
    });
});

describe('FloatingButton on phones', () => {
    test('both the button and the link carry the marker the page layout reserves room for', async () => {
        const { FloatingButton, FloatingButtonLink } = await import('./floating-button');
        expect(renderToStaticMarkup(<FloatingButton>+ Zasób</FloatingButton>)).toContain('data-solo-fab=""');
        expect(renderToStaticMarkup(<FloatingButtonLink href="/x">+ Zasób</FloatingButtonLink>)).toContain('data-solo-fab=""');
    });

    test('a page with one ends below the button, which itself sits on the tab bar clearance at its own height', async () => {
        const css = await Bun.file(new URL('../../../css/solo.css', import.meta.url)).text();
        const fab = (await Bun.file(new URL('./mobile.module.css', import.meta.url)).text()).match(/\.fab \{([^}]*)\}/)?.[1] ?? '';

        expect(fab).toContain('bottom: var(--solo-tab-bar-clearance);');
        expect(fab).toContain('height: var(--solo-fab-height);');
        expect(css).toContain('--solo-fab-clearance: calc(var(--solo-fab-height) + var(--solo-space-4));');
        expect(css).toContain(
            '[data-day]:has([data-solo-tab-bar]):has([data-solo-fab]) {\n    padding-bottom: calc(var(--solo-tab-bar-clearance) + var(--solo-fab-clearance));',
        );
    });
});

describe('the bottom sheet in Night', () => {
    test('the sheet draws the overlay edge along its top and the scrim dims with black, while paper keeps its borderless sheet', async () => {
        const css = await Bun.file(new URL('../ui/sheet/sheet.module.css', import.meta.url)).text();
        const paper = css.match(/:global\(\[data-day\]\) \.bottom \{([^}]*)\}/)?.[1] ?? '';
        const night = css.match(/:global\(\.dark \[data-day='paper'\]\) \.bottom \{([^}]*)\}/)?.[1] ?? '';
        const scrim = css.match(/:global\(\.dark \[data-day='paper'\]\) \.overlay \{([^}]*)\}/)?.[1] ?? '';

        expect(paper).toContain('border: 0;');
        expect(night.trim()).toBe('border-top: var(--solo-pop-width, 2px) solid var(--solo-pop-border, var(--solo-rule-strong));');
        expect(scrim).toContain('var(--color-black) 60%');
        expect(css.indexOf(':global(.dark')).toBeGreaterThan(css.indexOf(':global([data-day]) .bottom {'));
    });
});

describe('SoloToaster over an open bottom sheet', () => {
    test('on phones the toasts move to the top while a bottom sheet is open, and from 768 px they stay at the bottom', async () => {
        const css = await Bun.file(new URL('./toaster.module.css', import.meta.url)).text();
        const sheet = await Bun.file(new URL('../ui/sheet/sheet.tsx', import.meta.url)).text();
        const open = ":global(:root:has([data-slot='sheet-content'][data-side='bottom'][data-state='open']))";
        const phone = css.indexOf(
            `${open} .toasts[data-y-position='bottom'] {\n    top: calc(env(safe-area-inset-top, 0px) + var(--solo-space-4));\n    bottom: auto;`,
        );
        const desktop = css.indexOf(`    ${open} .toasts[data-y-position='bottom'] {\n        top: auto;\n        bottom: var(--solo-toast-offset);`);

        expect(sheet).toContain('data-side={side}');
        expect(phone).toBeGreaterThan(-1);
        expect(css).toContain(`${open} .toasts [data-sonner-toast][data-y-position='bottom'] {\n    top: 0;\n    bottom: auto;\n    --lift: 1;\n}`);
        // Only the entering toast starts above the edge; a mounted one keeps Sonner's own stacking transform.
        expect(css).toContain(
            `${open} .toasts [data-sonner-toast][data-y-position='bottom']:not([data-mounted='true']) {\n    --y: translateY(-100%);\n}`,
        );
        expect(desktop).toBeGreaterThan(css.indexOf('@media (min-width: 768px)'));
        expect(css.indexOf('@media (min-width: 768px)')).toBeGreaterThan(phone);
    });
});

describe('touch targets on phones', () => {
    test('a small text link gets a 44 px hit area below 768 px and none from 768 px, with no change to its box', async () => {
        const css = await Bun.file(new URL('./primitives.module.css', import.meta.url)).text();
        const hit = css.match(/\.touchTarget::before \{([^}]*)\}/)?.[1] ?? '';
        const wide = css.slice(css.lastIndexOf('@media (min-width: 768px)'));

        expect(css).toMatch(/\.touchTarget \{\s*position: relative;\s*\}/);
        expect(hit).toContain('width: max(100%, var(--solo-touch));');
        expect(hit).toContain('height: max(100%, var(--solo-touch));');
        expect(hit).not.toMatch(/padding|margin/);
        expect(wide).toMatch(/\.touchTarget::before \{\s*content: none;/);
    });

    test("Dane's phone and e-mail links are one box even when an address wraps, so the hit area centres on the whole link", async () => {
        const css = await Bun.file(new URL('../../pages/clients/components/details-tab/details-tab.module.css', import.meta.url)).text();

        expect(css).toMatch(/\.contactLink \{\s*display: inline-block;\s*max-width: 100%;\s*\}/);
    });

    test("the pagination links' hit areas meet halfway across the gap instead of overlapping", async () => {
        const css = await Bun.file(new URL('../inertia-pagination/inertia-pagination.module.css', import.meta.url)).text();
        const pages = css.match(/\.soloPages \{([^}]*)\}/)?.[1] ?? '';
        const hit = css.match(/\.soloPages > a::before \{([^}]*)\}/)?.[1] ?? '';

        expect(pages).toContain('--solo-pages-gap: 22px;');
        expect(pages).toContain('gap: var(--solo-pages-gap);');
        expect(hit.trim()).toBe('width: calc(100% + var(--solo-pages-gap));');
    });
});

describe('SegmentedControl at 320 px', () => {
    test('each segment hugs its label, and a label wider than its room wraps inside the 44 px segment', async () => {
        const css = await Bun.file(new URL('../ui/toggle-group/toggle-group.module.css', import.meta.url)).text();
        const item = css.match(/\.group\.segmented \.item \{([^}]*)\}/)?.[1] ?? '';

        expect(item).toContain('white-space: normal;');
        expect(item).toContain('text-align: center;');
        expect(item).toMatch(/flex: 1 1 auto;[\s\S]*min-width: 0;/);
        const lineHeight = Number(item.match(/font: 500 var\(--solo-text-control\) \/ ([\d.]+)/)?.[1]);
        expect(2 * lineHeight * 14).toBeLessThanOrEqual(44);
    });
});

describe('KvRow field rows', () => {
    test('on Paper phones the label sits left and its value right, Boring SaaS stacks them, and the label column comes back from 768 px', async () => {
        const css = await Bun.file(new URL('./kv-row.module.css', import.meta.url)).text();
        const tokens = await Bun.file(new URL('../../../css/solo.css', import.meta.url)).text();
        const wideAt = css.indexOf('@media (min-width: 768px)', css.indexOf('Field row on phones'));
        const phone = css.slice(0, wideAt).match(/\n\.row\[data-layout='column'\] \{([^}]*)\}/)?.[1] ?? '';
        const wide = css.slice(wideAt).match(/\.row\[data-layout='column'\] \{([^}]*)\}/)?.[1] ?? '';

        expect(phone).toContain('flex-direction: var(--solo-kv-flow, row);');
        expect(css.slice(0, wideAt)).toMatch(/\.row\[data-layout='column'\] \.value \{[^}]*text-align: var\(--solo-kv-value-align, right\);/);
        expect(tokens).toMatch(/:root\[data-theme='boring-saas'\] \{[^}]*--solo-kv-flow: column;/);
        expect(css.slice(0, wideAt)).not.toMatch(/\.row\[data-layout='column'\] \.label \{[^}]*flex-basis: \d+px/);
        expect(wide).toContain('flex-direction: row;');
        expect(css.slice(wideAt)).toMatch(/\.row\[data-layout='column'\] \.label \{\s*flex: 0 0 200px;/);
    });
});

describe('display caps leading', () => {
    const read = (path: string) => Bun.file(new URL(path, import.meta.url)).text();

    test("posters take Figma's 88% leading, one value for every caps heading", async () => {
        const css = await read('../../../css/solo.css');

        expect(css).toMatch(/--solo-caps-leading: 0\.88;/);
        expect(css).not.toContain('--solo-caps-mark-room');
    });

    test('every display caps poster reads the caps leading and pads nothing above its accents', async () => {
        const rules = [
            ['./client-header.module.css', /@media \(min-width: 768px\) \{[\s\S]*?\n {4}\.name \{([^}]*)\}/],
            ['./primitives.module.css', /\.poster \{([^}]*)\}/],
            ['../../pages/auth/login.module.css', /\.title \{([^}]*)\}/],
        ] as const;

        for (const [file, rule] of rules) {
            const body = (await read(file)).match(rule)?.[1] ?? '';
            expect(body).toContain('line-height: var(--solo-caps-leading);');
            expect(body).not.toContain('padding-top');
        }
    });

    test('a record name is the Heading poster and a long one steps down by its length and width, in CSS alone', async () => {
        const css = await read('./client-header.module.css');
        const wide = css.slice(css.indexOf('@media (min-width: 768px)'));
        const name = wide.match(/\n {4}\.name \{([^}]*)\}/)?.[1] ?? '';

        expect(name).toMatch(
            /font-size: clamp\(var\(--solo-poster-heading-min\), 372cqi \/ var\(--record-title-chars\), var\(--solo-poster-heading\)\);/,
        );
        expect(css).toMatch(/\.titleBox \{\s*container-type: inline-size;/);
    });
});

describe('Month card and Stop', () => {
    const october = (todayIndex: number | null): MonthData => ({
        month: '2026-10',
        days: Array.from({ length: 31 }, (_, i) => ({
            date: `2026-10-${String(i + 1).padStart(2, '0')}`,
            weekday: ((i + 3) % 7) + 1,
            state: i === todayIndex ? 'today' : todayIndex === null || i < todayIndex ? 'open' : 'future',
        })),
        closed: 0,
        weekdays: 22,
    });

    test("the compact card keeps today's week row, else the last week that has begun", () => {
        expect(currentWeek(october(9))).toBe(1);
        expect(currentWeek(october(0))).toBe(0);
        expect(currentWeek(october(null))).toBe(4);
    });

    test('once today is closed the compact card keeps its week, not the last weekend of the month', () => {
        const closed = october(9);
        closed.days = closed.days.map((day, i) => ({ ...day, state: i === 9 ? 'stamped' : day.weekday > 5 ? 'rest' : day.state }));

        expect(currentWeek(closed)).toBe(currentWeek(october(9)));
    });

    test('the card keeps the week before today and today, and only Dziś offers the whole month', () => {
        const html = renderToStaticMarkup(<MonthCard month={october(9)} monthLink />);
        const marked = [...html.matchAll(/<span role="listitem" class="[^"]*" data-other-week=""[^>]*aria-label="(\d+):/g)].map(([, day]) =>
            Number(day),
        );

        expect(Array.from({ length: 31 }, (_, i) => i + 1).filter((day) => !marked.includes(day))).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]);
        expect(html).toContain('Cały miesiąc ↓');
        expect(renderToStaticMarkup(<MonthCard month={october(9)} />)).not.toContain('Cały miesiąc');
    });

    test("in the month's first week the card keeps the first two weeks", () => {
        expect(shownWeeks(october(0))).toEqual([0, 1]);
        expect(shownWeeks(october(9))).toEqual([0, 1]);
        expect(shownWeeks(october(20))).toEqual([2, 3]);
    });

    test('a closed day and today once closed carry the tick; open, rest, today and future do not', () => {
        const states = ['closed', 'stamped', 'open', 'rest', 'today', 'future'] as const;
        const html = renderToStaticMarkup(
            <MonthCard
                month={{
                    month: '2026-10',
                    days: states.map((state, i) => ({ date: `2026-10-0${i + 1}`, weekday: ((i + 3) % 7) + 1, state })),
                    closed: 2,
                    weekdays: 22,
                }}
            />,
        );
        const ticked = [...html.matchAll(/<span role="listitem"[^>]*aria-label="(\d+): [^"]*"[^>]*>(.*?)<\/span>/g)].map(([, day, inner]) => [
            Number(day),
            inner.includes('<svg'),
        ]);

        expect(ticked).toEqual([
            [1, true],
            [2, true],
            [3, false],
            [4, false],
            [5, false],
            [6, false],
        ]);
    });

    test('a closed day draws the tick, today once closed keeps the today ring with the filled checkmark-circle, and no tile shows its number', async () => {
        const html = renderToStaticMarkup(
            <MonthCard
                month={{
                    month: '2026-10',
                    days: [
                        { date: '2026-10-01', weekday: 4, state: 'closed' },
                        { date: '2026-10-02', weekday: 5, state: 'stamped' },
                    ],
                    closed: 2,
                    weekdays: 22,
                }}
            />,
        );
        const tiles = [...html.matchAll(/<span role="listitem"[^>]*>(.*?)<\/span><\/span>|<span role="listitem"[^>]*><\/span>/g)].map(
            ([tile]) => tile,
        );
        const css = await Bun.file(new URL('./month-card.module.css', import.meta.url)).text();

        expect(tiles[0]).toContain('data-icon="check"');
        expect(tiles[1]).toContain('data-icon="done"');
        expect(tiles[1]).not.toContain('data-icon="check"');
        expect(tiles.map((tile) => tile.replace(/<[^>]+>/g, ''))).toEqual(['', '']);
        expect(css).toMatch(/\.today,\n\.stamped \{\s*box-shadow: inset 0 0 0 3px var\(--solo-tile-now\);/);
    });

    test('the card fills its column with seven square tiles 8 px apart, flush head, and draws nothing on phones', async () => {
        const css = await Bun.file(new URL('./month-card.module.css', import.meta.url)).text();
        const wideAt = css.indexOf('@media (min-width: 768px)');
        const grid = css.match(/\n\.grid \{([^}]*)\}/)?.[1] ?? '';

        expect(css.slice(0, wideAt)).toMatch(/\.card \{\s*display: none;\s*width: 100%;/);
        expect(css.slice(wideAt)).toMatch(/\.card \{\s*display: grid;/);
        expect(grid).toContain('grid-template-columns: repeat(7, minmax(0, 1fr));');
        expect(grid).toContain('gap: var(--solo-space-2);');
        expect(css).toMatch(/\.tile \{\s*aspect-ratio: 1;/);
        expect(css).toMatch(/\.popover \.grid \{\s*grid-template-columns: repeat\(7, var\(--solo-tile-size\)\);/);
    });

    test('closed days are ink with a paper tick on paper, paper with an ink tick at night, cream with the surface colour on timer and done, the red accent in Boring SaaS', async () => {
        const css = await Bun.file(new URL('../../../css/solo.css', import.meta.url)).text();
        const block = (selector: string) => css.match(new RegExp(`${selector.replace(/[[\]()*.'=]/g, '\\$&')} \\{([^}]*)\\}`))?.[1] ?? '';
        const tile = (body: string) => [body.match(/--solo-tile-done: ([^;]+);/)?.[1], body.match(/--solo-tile-tick: ([^;]+);/)?.[1]];

        expect(tile(block('[data-day]'))).toEqual(['var(--solo-ink)', 'var(--solo-paper)']);
        expect(tile(block(".dark [data-day='paper']"))).toEqual(['var(--solo-paper)', 'var(--solo-ink)']);
        expect(tile(block("[data-day='timer'],\n[data-day='done']"))).toEqual(['var(--solo-paper)', 'var(--solo-on-accent)']);
        expect(tile(block(":root[data-theme='boring-saas'] [data-day]"))).toEqual([
            'var(--color-destructive)',
            'var(--color-destructive-foreground)',
        ]);
    });

    test('the Stop look draws its own square, so a caller passes only the label', () => {
        const stop = renderToStaticMarkup(<SoloButton variant="stop">Stop</SoloButton>);
        const ink = renderToStaticMarkup(<SoloButton variant="ink">Stop</SoloButton>);

        expect(stop).toMatch(
            /^<button[^>]*><span aria-hidden="true"[^>]*data-icon="stop"><svg[^>]*fill="currentColor"[^>]*>.*<\/svg><\/span>Stop<\/button>$/,
        );
        expect(ink).not.toContain('<svg');
    });

    test('the running time inside the task page Stop takes the button colour, not the muted grey', async () => {
        const css = await Bun.file(new URL('../../pages/tasks/components/task-page/task-page.module.css', import.meta.url)).text();

        expect(css.match(/\.timerElapsed \{([^}]*)\}/)?.[1]).toContain('color: inherit;');
    });

    test('Stop fills with the surface ink, not the brand colour Boring SaaS gives ink buttons', async () => {
        const css = await Bun.file(new URL('./primitives.module.css', import.meta.url)).text();

        expect(
            css
                .match(/\.stop \{([^}]*)\}/)?.[1]
                .replace(/\s+/g, ' ')
                .trim(),
        ).toBe('background: var(--solo-main-bg, var(--solo-fg)); color: var(--solo-main-fg, var(--solo-bg));');
    });
});

describe('the A2f row hover', () => {
    test('a hovered row clears its own cut only between rows, so the last row keeps the list end under every theme', async () => {
        const files = [
            './task-row.module.css',
            './waiting-row.module.css',
            './mobile.module.css',
            '../../pages/settings/components/tab.module.css',
            '../../pages/clients/index.module.css',
            '../../pages/contacts/index.module.css',
        ];

        for (const file of files) {
            const css = await Bun.file(new URL(file, import.meta.url)).text();
            const rules = [...css.matchAll(/([^{}]+)\{([^}]*)\}/g)].filter(([, , body]) => body.includes('var(--solo-hover-rule'));

            expect(rules.length).toBeGreaterThan(0);
            for (const [, selector] of rules) {
                expect(`${file}: ${selector.trim()}`).toMatch(/:not\(:last-child\)|listRow:hover/);
            }
        }
    });
});

describe('the A2f block and its rows', () => {
    const read = (path: string) => Bun.file(new URL(path, import.meta.url)).text();

    test('a block is padded 8 on every side and its rows 12 at both ends, so the head over it lines up at 20', async () => {
        const tokens = await read('../../../css/solo.css');

        expect(tokens).toContain('--solo-block-pad: var(--solo-space-2);');
        expect(tokens).toContain('--solo-row-inset: var(--solo-space-3);');
        expect(tokens).toContain('--solo-head-inset: calc(var(--solo-space-2) + var(--solo-space-3));');
    });

    test('no screen sets its own block or row inset, so every block keeps the same padding', async () => {
        const glob = new Bun.Glob('**/*.module.css');
        const root = new URL('../../', import.meta.url).pathname;

        for await (const file of glob.scan(root)) {
            const css = await Bun.file(`${root}${file}`).text();

            expect(`${file}: ${css.match(/--solo-(block-pad|row-inset|head-inset)\s*:/)?.[0] ?? ''}`).toBe(`${file}: `);
        }
    });

    test("a ghost action in a row has no side padding on Paper, so the row's last ink ends on its 12 px line", async () => {
        const [tokens, css, row] = await Promise.all([read('../../../css/solo.css'), read('./primitives.module.css'), read('./task-row.module.css')]);

        expect(tokens).toContain('--solo-ghost-x: 0px;');
        expect(css).toMatch(/\.ghost\.sm \{\s*padding-inline: var\(--solo-ghost-x,/);
        expect(row.match(/\n\.trailing \{([^}]*)\}/)?.[1]).not.toMatch(/padding|margin-right/);
    });

    test('an empty section is one muted line inside the block a row would fill', async () => {
        const html = renderToStaticMarkup(<EmptyBlock>Brak aktywności.</EmptyBlock>);
        const css = await read('./primitives.module.css');

        expect(html).toMatch(/^<div[^>]*><p[^>]*>Brak aktywności\.<\/p><\/div>$/);
        expect(css).toMatch(/\.emptyBlock \{\s*padding: var\(--solo-block-pad, 0\);[^}]*background: var\(--solo-block, none\);/);
        expect(css).toMatch(/\.emptyLine \{[^}]*padding: var\(--solo-space-3\) var\(--solo-row-inset, 0\);/);
    });
});
