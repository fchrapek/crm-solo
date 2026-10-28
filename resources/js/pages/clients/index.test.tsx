import { router } from '@inertiajs/react';
import { afterAll, afterEach, describe, expect, spyOn, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';
import { CLEAR_FILTERS, DESKTOP_STAGES, listQuery, type ListQuery } from './components/client-list/filters';
import { setClientPinned } from './components/client-pin';
import { ClientList, ClientRow, ListFilterBody, OpenMonthsNote, type ClientListRow } from './index';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const row: ClientListRow = {
    id: 7,
    name: 'Pracownia Testowa Sp. z o.o.',
    short_name: 'Pracownia Testowa',
    lifecycle_stage: 'active',
    cooperation_type: 'retainer',
    is_archived: false,
    is_pinned: true,
    agent_live: false,
    hours: { period: '2026-10', behind: false, has_retainer: true, used: 4, available: 10, remaining: 6, logged: 4 },
};

function ancestorsOfButtons(html: string): string[][] {
    const stack: string[] = [];
    const found: string[][] = [];
    for (const [, closing, name, selfClosing] of html.matchAll(/<(\/?)([a-z][\w-]*)[^>]*?(\/?)>/g)) {
        if (closing) {
            stack.splice(stack.lastIndexOf(name));
        } else {
            if (name === 'button') found.push([...stack]);
            if (!selfClosing) stack.push(name);
        }
    }

    return found;
}

describe('ClientRow', () => {
    test('a pinned client shows a small pin mark beside the name link, with no pin button, and the name still opens the record', () => {
        const html = renderToStaticMarkup(
            <table>
                <tbody>
                    <ClientRow client={row} />
                </tbody>
            </table>,
        );
        expect(html).toContain('href="/clients/7/przeglad"');
        expect(html).toMatch(/<\/a><span role="img" aria-label="Przypięty"[^>]*data-icon="pin">/);
        expect(ancestorsOfButtons(html)).toHaveLength(0);
    });
});

describe('setClientPinned', () => {
    const put = spyOn(router, 'put').mockImplementation(() => {});

    afterEach(() => put.mockClear());
    afterAll(() => put.mockRestore());

    test('sends the explicit state to the pin endpoint and keeps the scroll', () => {
        setClientPinned(7, true);
        setClientPinned(7, false);
        expect(put.mock.calls).toEqual([
            ['/clients/7/pin', { pinned: true }, { preserveScroll: true }],
            ['/clients/7/pin', { pinned: false }, { preserveScroll: true }],
        ]);
    });
});

describe('Lista active month', () => {
    const rowHtml = (client: ClientListRow) =>
        renderToStaticMarkup(
            <table>
                <tbody>
                    <ClientRow client={client} />
                </tbody>
            </table>,
        );

    test('a row behind carries the short month mark after its hours, since the column head names the running month; a row on it none', () => {
        const behind = rowHtml({ ...row, hours: { ...row.hours, period: '2026-09', behind: true, used: 36.5, available: 20.5, remaining: -16 } });
        const current = rowHtml(row);

        expect(behind).toMatch(/36,5 z 20,5 h<abbr[^>]*title="wrzesień jeszcze otwarty"[^>]*>WRZ<\/abbr>/);
        expect(current).not.toContain('<abbr');
    });

    test('the note names the shared month in the instrumental, with a Polish plural', () => {
        const note = (count: number, period: string | null) =>
            renderToStaticMarkup(<OpenMonthsNote current="2026-10" openMonths={{ count, period, oldest: period ?? '2026-08' }} />).replace(
                /<[^>]+>/g,
                '',
            );

        expect(note(3, '2026-09')).toBe('Jest już październik · 3 klientów z otwartym wrześniem\u00a0→');
        expect(note(1, '2026-09')).toBe('Jest już październik · 1 klient z otwartym wrześniem\u00a0→');
        expect(note(5, null)).toBe('Jest już październik · 5 klientów z otwartym miesiącem\u00a0→');
    });

    test('the phone note is the short form, the arrow kept on the last word', () => {
        const note = renderToStaticMarkup(
            <OpenMonthsNote current="2026-10" openMonths={{ count: 3, period: '2026-09', oldest: '2026-09' }} short />,
        ).replace(/<[^>]+>/g, '');

        expect(note).toBe('Październik · 3 klientów z otwartym wrześniem\u00a0→');
    });

    test('the note opens the month close of the oldest month still owed', () => {
        const href = (oldest: string | null) =>
            renderToStaticMarkup(<OpenMonthsNote current="2026-10" openMonths={{ count: 2, period: null, oldest }} />).match(/href="([^"]+)"/)?.[1];

        expect(href('2026-08')).toBe('/month-close?period=2026-08');
        expect(href(null)).toBe('/month-close');
    });
});

describe('Lista on phones', () => {
    const rows: ClientListRow[] = [
        { ...row, hours: { ...row.hours, period: '2026-09', behind: true, used: 36.5, available: 20.5, remaining: -16 }, agent_live: true },
        { ...row, id: 8, short_name: 'Kawiarnia Testowa', is_pinned: false, hours: { ...row.hours, has_retainer: false, logged: 6.5 } },
    ];

    test('the rows come from the page clients: hours, the balance in vermilion when negative, and the marks', () => {
        const html = renderToStaticMarkup(<ClientList rows={rows} />);
        const items = html.split('<li>').slice(1);

        expect(items).toHaveLength(2);
        expect(items[0]).toContain('href="/clients/7/przeglad"');
        expect(items[0]).toContain('Pracownia Testowa');
        expect(items[0]).toContain('aria-label="Przypięty"');
        expect(items[0]).toContain('36,5 z 20,5 h');
        expect(items[0]).toContain('>WRZ<');
        expect(items[0]).toContain('claude pracuje');
        expect(items[0]).toMatch(/data-tone="over"[^>]*>[−-]16,0 h</);

        expect(items[1]).toContain('href="/clients/8/przeglad"');
        expect(items[1]).toContain('6,5 h');
        expect(items[1]).not.toContain('Przypięty');
        expect(items[1]).not.toContain('data-tone=');
    });

    test('an empty page says so in the list', () => {
        expect(renderToStaticMarkup(<ClientList rows={[]} />)).toContain('Nie znaleziono klientów.');
    });

    test('the filter sheet shows the status counts, the current stage and type, and Wszyscy as the sum of the rows', () => {
        const html = renderToStaticMarkup(
            <ListFilterBody
                filters={{ stage: 'paused', cooperation_type: 'retainer', search: '' }}
                stageCounts={{ active: 23, paused: 7, churned: 39, archived: 2 }}
                cooperationCounts={{ all: 7, retainer: 5, hourly: 0, project: 1, one_off: 0, none: 1 }}
                onChange={() => undefined}
            />,
        );
        const segments = [...html.matchAll(/<button[^>]*data-state="(on|off)"[^>]*>([^<]+)<\/button>/g)].map(([, state, label]) => [label, state]);
        expect(segments).toEqual([
            ['Aktywni · 23', 'off'],
            ['Wstrzymani · 7', 'on'],
            ['Byli · 39', 'off'],
        ]);
        expect(html).not.toContain('Zarchiwizowani');

        const options = [
            ...html.matchAll(
                /<button[^>]*aria-pressed="(true|false)"[^>]*>.*?<span[^>]*>([^<]+)<\/span><\/span><\/span><span[^>]*><span[^>]*>(\d+)<\/span>/g,
            ),
        ].map(([, pressed, label, count]) => [label, Number(count), pressed]);
        expect(options).toEqual([
            ['Wszyscy', 7, 'false'],
            ['Abonament', 5, 'true'],
            ['Projekt', 1, 'false'],
            ['Bez typu', 1, 'false'],
        ]);
        expect(options.slice(1).reduce((sum, [, count]) => sum + Number(count), 0)).toBe(7);
    });

    test('the desktop filter lists every status as rows, Archived included even at 0', () => {
        const statuses = (archived: number, stage: ListQuery['stage']) => {
            const html = renderToStaticMarkup(
                <ListFilterBody
                    filters={{ stage, cooperation_type: null, search: '' }}
                    stageCounts={{ active: 22, paused: 7, churned: 39, archived }}
                    cooperationCounts={{ all: 22, retainer: 15, hourly: 2, project: 2, one_off: 3, none: 0 }}
                    onChange={() => undefined}
                    stages={DESKTOP_STAGES}
                    look="menu"
                />,
            );
            return [...html.matchAll(/<button[^>]*aria-pressed="(true|false)"[^>]*><span[^>]*>([^<]+)<\/span><span[^>]*>(\d+)<\/span><\/button>/g)]
                .map(([, pressed, label, count]) => [label, Number(count), pressed])
                .slice(0, 4);
        };

        expect(statuses(11, 'active')).toEqual([
            ['Aktywni', 22, 'true'],
            ['Wstrzymani', 7, 'false'],
            ['Byli', 39, 'false'],
            ['Zarchiwizowani', 11, 'false'],
        ]);
        expect(statuses(0, 'active')[3]).toEqual(['Zarchiwizowani', 0, 'false']);
        expect(statuses(0, 'archived')[3]).toEqual(['Zarchiwizowani', 0, 'true']);
    });

    test('Wyczyść puts the status and the type back to the defaults and keeps the search', () => {
        const filtered: ListQuery = { stage: 'churned', cooperation_type: 'hourly', search: 'kawa' };
        expect(listQuery({ ...filtered, ...CLEAR_FILTERS })).toEqual({ search: 'kawa' });
    });
});
