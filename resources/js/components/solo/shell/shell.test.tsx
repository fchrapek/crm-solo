import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../lang/pl.json';
import { CommandBar } from './command-bar';
import { SearchHints } from '../command-palette';
import { Pills } from './pills';
import { TimerStrip } from './timer-strip';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

describe('CommandBar', () => {
    test("a section's home has no tokens and asks what you are looking for", () => {
        const html = renderToStaticMarkup(<CommandBar />);

        expect(html).toContain('placeholder="Czego szukasz?"');
        expect(html).not.toContain('<nav');
        expect(html).toContain('⌘K');
    });

    test('every level above the page is a link to it and the last token is the page itself', () => {
        const html = renderToStaticMarkup(
            <CommandBar tokens={[{ label: 'Pracownia Mebli', href: '/clients/7' }, { label: 'Rozliczenia', href: '/clients/7/billing' }, { label: 'Wrzesień' }]} />,
        );
        const nav = html.slice(html.indexOf('<nav'), html.indexOf('</nav>'));

        expect(nav).toContain('aria-label="Jesteś tutaj"');
        expect([...nav.matchAll(/<a [^>]*href="([^"]+)"[^>]*>([^<]+)<\/a>/g)].map(([, href, label]) => [href, label])).toEqual([
            ['/clients/7', 'Pracownia Mebli'],
            ['/clients/7/billing', 'Rozliczenia'],
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
        { label: 'Zadania', href: '/clients/7/tasks', count: 25, active: true },
        { label: 'Dziennik', href: '/clients/7/journal' },
    ];

    test('exactly the active sibling is the current page, and a count rides in its pill', () => {
        const html = renderToStaticMarkup(<Pills items={items} />);

        expect(html.match(/aria-current="page"/g)).toHaveLength(1);
        expect(html).toMatch(/<a aria-current="page" href="\/clients\/7\/tasks"[^>]*>Zadania<span[^>]*>25<\/span><\/a>/);
        expect(html).toMatch(/<a[^>]*href="\/clients\/7\/journal"[^>]*>Dziennik<\/a>/);
    });

    test('a pill with choices is a button that opens them, selected once a choice narrows the page', () => {
        const menu = [
            { label: 'Wszyscy', href: '/clients', count: 7 },
            { label: 'Abonament', href: '/clients?cooperation_type=retainer', count: 5, active: true },
        ];
        const html = renderToStaticMarkup(<Pills items={[...items, { label: 'Współpraca: abonament', active: true, menu }]} />);

        expect(html).toMatch(/<button[^>]*data-active=""[^>]*aria-haspopup="menu"[^>]*>Współpraca: abonament<span aria-hidden="true"[^>]*data-icon="chevron-down">/);
        expect(html.match(/aria-current="page"/g)).toHaveLength(1);
        expect(renderToStaticMarkup(<Pills items={[{ label: 'Współpraca: wszyscy', menu }]} />)).not.toContain('data-active');
    });

    test('every pill with choices ends in a 16 px chevron, and a link pill has none', () => {
        const html = renderToStaticMarkup(<Pills items={[...items, { label: 'Współpraca: wszyscy', menu: [{ label: 'Wszyscy', href: '/clients' }] }]} />);

        expect(html.match(/data-icon="chevron-down"/g)).toHaveLength(1);
        expect(html).toMatch(/Współpraca: wszyscy<span aria-hidden="true"[^>]*data-icon="chevron-down"><svg width="16" height="16"/);
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
