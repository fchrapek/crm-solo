import { describe, expect, test } from 'bun:test';
import { readFileSync } from 'node:fs';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../lang/pl.json';
import { CommandBar } from './command-bar';
import { HelperWindow } from './helper/windows';
import { HELPER_CLOSED, helperHint, helperPose, helperScene, helperShow } from './helper/hints';
import { SearchHints } from '../command-palette';
import { Pills } from './pills';
import { TimerStrip } from './timer-strip';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const t = (key: string, options?: Record<string, unknown>) => i18next.t(key, options);

describe('CommandBar', () => {
    test("a section's home has no tokens and asks what you are looking for", () => {
        const html = renderToStaticMarkup(<CommandBar />);

        expect(html).toContain('placeholder="Czego szukasz?"');
        expect(html).not.toContain('<nav');
        expect(html).toContain('⌘K');
    });

    test('every level above the page is a link to it and the last token is the page itself', () => {
        const html = renderToStaticMarkup(
            <CommandBar tokens={[{ label: 'Pracownia Mebli', href: '/clients/7' }, { label: 'Rozliczenia', href: '/clients/7/rozliczenia' }, { label: 'Wrzesień' }]} />,
        );
        const nav = html.slice(html.indexOf('<nav'), html.indexOf('</nav>'));

        expect(nav).toContain('aria-label="Jesteś tutaj"');
        expect([...nav.matchAll(/<a href="([^"]+)"[^>]*>([^<]+)<\/a>/g)].map(([, href, label]) => [href, label])).toEqual([
            ['/clients/7', 'Pracownia Mebli'],
            ['/clients/7/rozliczenia', 'Rozliczenia'],
        ]);
        expect(nav).toMatch(/<span[^>]*aria-current="page"[^>]*>Wrzesień<\/span>/);
        expect(html).toContain('placeholder="szukaj dalej..."');
    });

    test('a level with siblings to switch between is a button handed to its switcher, not a link', () => {
        const html = renderToStaticMarkup(
            <CommandBar
                tokens={[
                    { label: 'Pracownia Mebli', href: '/clients/7', switcher: (trigger) => <span data-switcher="">{trigger}</span> },
                    { label: 'Zadania' },
                ]}
            />,
        );

        expect(html).toMatch(/<span data-switcher=""><button type="button"[^>]*>Pracownia Mebli<\/button><\/span>/);
        expect(html).not.toContain('href="/clients/7"');
    });

    test('the field is the one search box of the page and names its results list', () => {
        const html = renderToStaticMarkup(<CommandBar />);

        expect(html.match(/type="search"/g)).toHaveLength(1);
        expect(html).toContain('role="search"');
        expect(html).toContain('aria-controls="palette-results"');
    });
});

describe('Pills', () => {
    const items = [
        { label: 'Zadania', href: '/clients/7/zadania', count: 25, active: true },
        { label: 'Dziennik', href: '/clients/7/dziennik' },
    ];

    test('exactly the active sibling is the current page, and a count rides in its pill', () => {
        const html = renderToStaticMarkup(<Pills items={items} />);

        expect(html.match(/aria-current="page"/g)).toHaveLength(1);
        expect(html).toMatch(/<a aria-current="page" href="\/clients\/7\/zadania"[^>]*>Zadania<span[^>]*>25<\/span><\/a>/);
        expect(html).toMatch(/<a[^>]*href="\/clients\/7\/dziennik"[^>]*>Dziennik<\/a>/);
    });

    test('a pill with choices is a button that opens them, selected once a choice narrows the page', () => {
        const menu = [
            { label: 'Wszyscy', href: '/clients', count: 7 },
            { label: 'Abonament', href: '/clients?cooperation_type=retainer', count: 5, active: true },
        ];
        const html = renderToStaticMarkup(<Pills items={[...items, { label: 'Współpraca: abonament', active: true, menu }]} />);

        expect(html).toMatch(/<button[^>]*data-active=""[^>]*aria-haspopup="menu"[^>]*>Współpraca: abonament<\/button>/);
        expect(html.match(/aria-current="page"/g)).toHaveLength(1);
        expect(renderToStaticMarkup(<Pills items={[{ label: 'Współpraca: wszyscy', menu }]} />)).not.toContain('data-active');
    });

    test('a page without siblings draws no pill row', () => {
        expect(renderToStaticMarkup(<Pills items={[]} />)).toBe('');
    });
});

describe('TimerStrip', () => {
    test('names the running task and offers Stop; a timer without a task is a session without a name', () => {
        const timer = { id: 3, started_at: '2026-10-28T08:00:00Z', task: 'Korekta faktury', client: null };

        expect(renderToStaticMarkup(<TimerStrip timer={timer} />)).toMatch(/Korekta faktury<\/span><button[^>]*>Stop<\/button>/);
        expect(renderToStaticMarkup(<TimerStrip timer={{ ...timer, task: null }} />)).toContain('Sesja bez nazwy');
        expect(renderToStaticMarkup(<TimerStrip timer={timer} />)).toContain('data-shell-strip=""');
    });
});

describe('the search before anything is typed', () => {
    test('the keys are all it shows: select, open, close', () => {
        expect(renderToStaticMarkup(<SearchHints />).replace(/<[^>]+>/g, '|')).toContain('↑↓ wybierz||↵ otwórz|||esc| zamknij');
    });
});

const context = { t, locale: 'pl-PL' };
const text = (html: string) => html.replace(/<[^>]+>/g, '|');

describe('the helper window', () => {
    test('its field is disabled and says that writing does not work yet', () => {
        for (const kind of ['plan', 'report', 'client'] as const) {
            expect(renderToStaticMarkup(<HelperWindow kind={kind} />)).toMatch(/<input[^>]*placeholder="Podgląd: pisanie jeszcze nie działa"[^>]*disabled=""/);
        }
    });

    test('Zaplanuj dzień shows three made-up tasks with a reason, and its Dodaj buttons are marked as a preview', () => {
        const html = renderToStaticMarkup(<HelperWindow kind="plan" />);

        expect(text(html)).toContain('Przykład');
        expect(html.match(/Powód: /g)).toHaveLength(3);
        expect(html.match(/aria-disabled="true" title="Podgląd: ten przycisk jeszcze nic nie robi"[^>]*>Dodaj</g)).toHaveLength(3);
        expect(html).toContain('To makieta: pomocnik nie czyta jeszcze danych i niczego nie zmienia.');
    });

    test('Przygotuj raport lists its steps with the progress counted from them', () => {
        const html = renderToStaticMarkup(<HelperWindow kind="report" />);

        expect(html.match(/data-state="done"/g)).toHaveLength(2);
        expect(html).toMatch(/data-state="current" aria-current="step"/);
        expect(text(html)).toContain('Przykładowy postęp: 2 z 5 kroków');
    });

    test('Co się dzieje u klienta links to the places of the client the page shows, and says so where there is none', () => {
        const html = renderToStaticMarkup(<HelperWindow kind="client" links={[{ label: 'Zadania', href: '/clients/7/zadania' }]} />);

        expect(html).toMatch(/<a[^>]*href="\/clients\/7\/zadania"[^>]*>Zadania/);
        expect(text(renderToStaticMarkup(<HelperWindow kind="client" />))).toContain('Otwórz klienta, a pojawią się tu linki');
    });

    test('a window opens, gives way to another and closes, and the line of that page is used up', () => {
        const open = helperShow(HELPER_CLOSED, { page: 'today/index', show: 'plan' });
        const other = helperShow(open, { page: 'today/index', show: 'report' });
        const closed = helperShow(other, { page: 'today/index', show: null });

        expect(HELPER_CLOSED.shown).toBeNull();
        expect(open.shown).toBe('plan');
        expect(other.shown).toBe('report');
        expect(closed).toEqual({ shown: null, seen: 'today/index' });
    });

    test('the page\'s hint is said first in the window, so a touch screen that never hovers still reads it', () => {
        const line = helperScene('clients/show', { tab: 'rozliczenia', client: { id: 7, short_name: 'Pracownia Mebli' } }, context).hint!.text;
        const html = renderToStaticMarkup(<HelperWindow kind="report" line={line} />).replace(/<[^>]+>/g, '|');

        expect(html.indexOf(line)).toBeGreaterThan(-1);
        expect(html.indexOf(line)).toBeLessThan(html.indexOf('Przygotuj raport'));
    });

    test('the helper never covers the page on its own: the phone button scrolls with the page, the hint waits for the dog', () => {
        const css = readFileSync(`${import.meta.dir}/helper/helper.module.css`, 'utf8');
        const base = css.slice(0, css.indexOf('@media'));

        expect(base.match(/\.helper \{([^}]*)\}/)?.[1]).not.toMatch(/position:/);
        expect(css).toMatch(/\.helper:hover \.hint,\s*\.helper:focus-within \.hint \{\s*display: flex;/);
        expect(css.match(/ {4}\.hint \{([^}]*)\}/)?.[1]).not.toMatch(/display:/);
    });

    test('nothing in the helper performs a request', () => {
        const dir = `${import.meta.dir}/helper`;
        const source = ['helper.tsx', 'windows.tsx', 'hints.ts', 'dog.tsx'].map((file) => readFileSync(`${dir}/${file}`, 'utf8')).join('\n');

        expect(source).not.toMatch(/fetch\(|axios|jsonRequest|useForm|router\.|XMLHttpRequest|usePoll|useHttp/);
    });
});

describe('the helper hints', () => {
    test('Dziś with free slots offers to suggest tasks, counted in Polish', () => {
        expect(helperHint('today/index', { state: 'paper', maxPicks: 5, picks: [1, 2] }, context)).toEqual({
            text: 'Zostały 3 wolne miejsca na dziś. Podpowiedzieć zadania?',
            action: 'Podpowiedz',
        });
        expect(helperHint('today/index', { state: 'paper', maxPicks: 5, picks: [] }, context)?.text).toBe('Zostało 5 wolnych miejsc na dziś. Podpowiedzieć zadania?');
    });

    test('a full day, the timer view and the summary have no hint', () => {
        expect(helperHint('today/index', { state: 'paper', maxPicks: 5, picks: [1, 2, 3, 4, 5] }, context)).toBeNull();
        expect(helperHint('today/index', { state: 'timer', maxPicks: 5, picks: [] }, context)).toBeNull();
        expect(helperHint('today/index', { state: 'done', maxPicks: 5, picks: [] }, context)).toBeNull();
    });

    test('every main screen has its line, and the action opens the window that fits it', () => {
        const client = { id: 7, short_name: 'Pracownia Mebli' };

        expect(helperScene('today/index', { state: 'paper', maxPicks: 5, picks: [] }, context).window).toBe('plan');
        expect(helperScene('clients/index', {}, context)).toMatchObject({ window: 'report', hint: { action: 'Pokaż podgląd' } });
        expect(helperScene('clients/show', { tab: 'przeglad', client }, context)).toMatchObject({
            window: 'client',
            hint: { text: 'Tu streszczę, co się dzieje u klienta Pracownia Mebli. Zobaczyć, jak to będzie wyglądać?' },
        });
        expect(helperScene('clients/show', { tab: 'rozliczenia', client }, context)).toMatchObject({ window: 'report', hint: { text: expect.stringContaining('raport za miesiąc') } });
        expect(helperScene('tasks/show', {}, context)).toMatchObject({ window: 'plan', hint: { text: expect.stringContaining('Po tym zadaniu') } });
    });

    test('a client over the package is a warning with the figure the page already shows, and the dog is surprised', () => {
        const props = { tab: 'przeglad', client: { id: 7, short_name: 'Pracownia Mebli' }, overview: { hours: { remaining: -12.8 } } };
        const hint = helperHint('clients/show', props, context);

        expect(hint).toMatchObject({ text: 'Pracownia Mebli: 12,8 h ponad pakiet. Tu pomogę przygotować raport.', warning: true });
        expect(helperPose(hint)).toBe('wow');
        expect(helperHint('clients/show', { ...props, overview: { hours: { remaining: 3 } } }, context)?.warning).toBeUndefined();
    });

    test('the warning and the surprised pose start only below zero hours left: a used-up or untracked package keeps the plain line', () => {
        const scene = (remaining: unknown) => helperHint('clients/show', { tab: 'przeglad', client: { id: 7, short_name: 'Pracownia Mebli' }, overview: { hours: { remaining } } }, context);

        for (const remaining of [0, 0.25, 30, null, undefined]) {
            expect(scene(remaining)?.warning).toBeUndefined();
            expect(scene(remaining)?.text).toContain('Tu streszczę');
            expect(helperPose(scene(remaining))).toBe('look-left');
        }
        expect(scene(-0.25)).toMatchObject({ text: 'Pracownia Mebli: 0,25 h ponad pakiet. Tu pomogę przygotować raport.', warning: true });
        expect(helperPose(scene(-0.25))).toBe('wow');
        expect(helperPose(helperHint('clients/show', { tab: 'rozliczenia', client: { id: 7 }, overview: { hours: { remaining: -5 } } }, context))).toBe('look-left');
    });

    test('the dog looks at its line, and wears the sunglasses when there is none', () => {
        expect(helperPose({ text: 'x', action: 'y' })).toBe('look-left');
        expect(helperPose(null)).toBe('cool');
    });

    test('the client window links to the tabs of the client on the page', () => {
        expect(helperScene('clients/show', { tab: 'przeglad', client: { id: 7, short_name: 'A' } }, context).links.map((link) => link.label)).toEqual(['Zadania', 'Rozliczenia', 'Dziennik']);
    });

    test('the first run hides the corner helper: the dog is large on the screen itself', () => {
        expect(helperScene('clients/index', { stage_counts: { active: 0, former: 0 } }, context).hidden).toBe(true);
        expect(helperScene('clients/index', { stage_counts: { active: 2, former: 0 } }, context).hidden).toBe(false);
    });

    test('a page that registered no line shows the dog alone, and the dog opens the day plan', () => {
        expect(helperScene('settings/index', {}, context)).toEqual({ window: 'plan', hint: null, links: [] });
    });
});

describe('places in the search', () => {
    const places = [
        { label: 'Leady', url: '/leads' },
        { label: 'Zamknięcie miesiąca', url: '/month-close' },
        { label: 'Przychody', url: '/revenue' },
    ];

    test('a screen the rail has no item for is found by its name, with or without Polish letters', async () => {
        const { placeGroup } = await import('../command-palette');

        expect(placeGroup('przy', places)?.items.map((item) => item.url)).toEqual(['/revenue']);
        expect(placeGroup('zamkniecie mies', places)?.items.map((item) => item.title)).toEqual(['Zamknięcie miesiąca']);
        expect(placeGroup('LEADY', places)?.group).toBe('places');
    });

    test('words that name no screen add no group', async () => {
        const { placeGroup } = await import('../command-palette');

        expect(placeGroup('formularz', places)).toBeNull();
        expect(placeGroup('  ', places)).toBeNull();
    });
});
