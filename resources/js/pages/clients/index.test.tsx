import { router } from '@inertiajs/react';
import { afterAll, afterEach, describe, expect, spyOn, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';
import { type PlaceLinkPill, type PlaceMenuPill } from '@/components/solo/shell/place';
import { CLEAR_FILTERS, listQuery, type ListQuery } from './components/client-list/filters';
import { setClientPinned } from './components/client-pin';
import { ClientRow, ClientTable, FirstRun, OpenMonthsNote, openMonthsText, type ClientListRow } from './index';
import { clientListPlace, noClients } from './place';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const t = (key: string, options?: Record<string, unknown>) => i18next.t(key, options);
const context = { t, url: '/clients', locale: 'pl' };

const row: ClientListRow = {
    id: 7,
    name: 'Pracownia Testowa Sp. z o.o.',
    short_name: 'Pracownia Testowa',
    lifecycle_stage: 'active',
    cooperation_type: 'retainer',
    is_archived: false,
    is_pinned: true,
    agent_live: false,
    agent_minutes: null,
    hours: { period: '2026-10', behind: false, has_retainer: true, used: 4, available: 10, remaining: 6, logged: 4 },
};

const rowHtml = (client: ClientListRow) =>
    renderToStaticMarkup(
        <table>
            <tbody>
                <ClientRow client={client} />
            </tbody>
        </table>,
    );

describe('ClientRow', () => {
    test('a pinned client shows a small pin mark beside the name link, with no pin button, and the name still opens the record', () => {
        const html = rowHtml(row);

        expect(html).toContain('href="/clients/7/overview"');
        expect(html).toMatch(/<\/a><span role="img" aria-label="Przypięty"[^>]*data-icon="pin">/);
        expect(html).not.toContain('<button');
    });

    test('a retainer reads its hours against the pool and its balance; a negative balance is over the limit', () => {
        const over = rowHtml({ ...row, hours: { ...row.hours, used: 36.5, available: 20.5, remaining: -16 } });

        expect(over).toContain('36,5 z 20,5 h');
        expect(over).toMatch(/data-tone="over"[^>]*>[−-]16,0 h</);
        expect(rowHtml(row)).not.toContain('data-tone="over"');
    });

    test('a client without a pool shows the hours logged and no balance figure', () => {
        const html = rowHtml({ ...row, is_pinned: false, hours: { ...row.hours, has_retainer: false, logged: 6.5 } });

        expect(html).toContain('6,5 h');
        expect(html).not.toContain('Przypięty');
        expect(html).toMatch(/data-tone="muted"[^>]*><span[^>]*>·</);
    });

    test('a working agent is a dot before the name that names the agent and its minutes, with no column of its own', () => {
        const html = rowHtml({ ...row, agent_live: true, agent_minutes: 14 });

        expect(html).toMatch(/<button type="button"[^>]*aria-label="Claude pracuje · 14 min"[^>]*><span[^>]*data-status="agent"/);
        expect(html.match(/<td/g)).toHaveLength(4);
        expect(html).not.toMatch(/<span class="[^"]*chip[^"]*">/);
        expect(rowHtml(row)).not.toContain('pracuje');
        expect(rowHtml(row).match(/<td/g)).toHaveLength(4);
    });

    test('an agent without a known start is still named, without minutes', () => {
        expect(rowHtml({ ...row, agent_live: true, agent_minutes: null })).toContain('aria-label="Claude pracuje"');
    });
});

describe('ClientTable', () => {
    test('the head has the name, the month, the open month and the balance, and no attention column', () => {
        const html = renderToStaticMarkup(<ClientTable rows={[row]} period="2026-10" />);

        expect(html.match(/<th[ >]/g)).toHaveLength(4);
        expect(html).not.toContain('Uwaga');
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
    test('a row behind names its open month, since the column head names the running month; a row on it none', () => {
        const behind = rowHtml({ ...row, hours: { ...row.hours, period: '2026-09', behind: true, used: 36.5, available: 20.5, remaining: -16 } });

        expect(behind).toMatch(/<span title="wrzesień jeszcze otwarty">wrzesień<\/span>/);
        expect(rowHtml(row)).not.toContain('jeszcze otwarty');
    });

    test('the note names the shared month in the instrumental, with a Polish plural', () => {
        const note = (count: number, period: string | null) => openMonthsText('2026-10', { count, period, oldest: period ?? '2026-08' }, false, i18next.t, 'pl');

        expect(note(3, '2026-09')).toBe('Jest już październik · 3 klientów z otwartym wrześniem');
        expect(note(1, '2026-09')).toBe('Jest już październik · 1 klient z otwartym wrześniem');
        expect(note(5, null)).toBe('Jest już październik · 5 klientów z otwartym miesiącem');
    });

    test('the phone note is the short form', () => {
        expect(openMonthsText('2026-10', { count: 3, period: '2026-09', oldest: '2026-09' }, true, i18next.t, 'pl')).toBe(
            'Październik · 3 klientów z otwartym wrześniem',
        );
    });

    test('the note opens the month close of the oldest month still owed', () => {
        const href = (oldest: string | null) =>
            renderToStaticMarkup(<OpenMonthsNote current="2026-10" openMonths={{ count: 2, period: null, oldest }} />).match(/href="([^"]+)"/)?.[1];

        expect(href('2026-08')).toBe('/month-close?period=2026-08');
        expect(href(null)).toBe('/month-close');
    });
});

describe('the place of Klienci', () => {
    const place = (filters: ListQuery, archived = 2) =>
        clientListPlace(
            {
                filters,
                stage_counts: { active: 23, paused: 7, churned: 39, archived },
                cooperation_counts: { all: 7, retainer: 5, hourly: 0, project: 1, one_off: 0, none: 1 },
                card: null,
            },
            context,
        );
    const links = (filters: ListQuery, archived?: number) => (place(filters, archived).pills ?? []).filter((pill): pill is PlaceLinkPill => !('menu' in pill));
    const menus = (filters: ListQuery) => (place(filters).pills ?? []).filter((pill): pill is PlaceMenuPill => 'menu' in pill);

    test('a section home has no tokens; every status is a pill with its count, Archived included even at 0', () => {
        const filters: ListQuery = { stage: 'paused', cooperation_type: 'retainer', search: 'kawa' };

        expect(place(filters).tokens).toBeUndefined();
        expect(links(filters, 0).map((pill) => [pill.label, pill.count, pill.active])).toEqual([
            ['Aktywni', 23, false],
            ['Wstrzymani', 7, true],
            ['Byli', 39, false],
            ['Zarchiwizowani', 0, false],
        ]);
    });

    test('a status pill keeps the other filters and the search', () => {
        const [active, , churned] = links({ stage: 'paused', cooperation_type: 'retainer', search: 'kawa' });

        expect(active.href).toBe('/clients?cooperation_type=retainer&search=kawa');
        expect(churned.href).toBe('/clients?stage=churned&cooperation_type=retainer&search=kawa');
    });

    test('Cooperation is a menu pill: All clients as the sum of the rows, types without clients left out, the current one marked', () => {
        const [cooperation] = menus({ stage: 'paused', cooperation_type: 'retainer', search: '' });

        expect(cooperation.label).toBe('Współpraca: abonament');
        expect(cooperation.active).toBe(true);
        expect(cooperation.menu.map((choice) => [choice.label, choice.count, choice.active])).toEqual([
            ['Wszyscy', 7, false],
            ['Abonament', 5, true],
            ['Projekt', 1, false],
            ['Bez typu', 1, false],
        ]);
        expect(cooperation.menu.slice(1).reduce((sum, choice) => sum + (choice.count ?? 0), 0)).toBe(7);
        expect(cooperation.menu[0].href).toBe('/clients?stage=paused');
        expect(menus({ stage: 'active', cooperation_type: null, search: '' })[0]).toMatchObject({ label: 'Współpraca: wszyscy', active: false });
    });

    test('the command bar narrows the list to the typed words and keeps the filters', () => {
        expect(place({ stage: 'churned', cooperation_type: null, search: '' }).filter?.href('kawa')).toBe('/clients?stage=churned&search=kawa');
    });

    test('an account with its own fields filters by assignee and by each choice field', () => {
        const pills = clientListPlace(
            {
                filters: { stage: 'active', cooperation_type: null, search: '' },
                stage_counts: { active: 3, paused: 0, churned: 0, archived: 0 },
                cooperation_counts: { all: 3, retainer: 0, hourly: 0, project: 0, one_off: 0, none: 3 },
                card: {
                    billing: false,
                    filters: { assignee: 'me', f: { wizja: 'umowiona' } },
                    columns: [],
                    assignees: [
                        { value: null, label: null, count: 3 },
                        { value: 'me', label: null, count: 2 },
                    ],
                    facets: [
                        {
                            key: 'wizja',
                            label: 'Wizja',
                            options: [
                                { value: null, label: null, count: 3 },
                                { value: 'umowiona', label: 'Umówiona', count: 1 },
                            ],
                        },
                    ],
                    sms: { today: 0, failed: 0 },
                },
            },
            context,
        ).pills?.filter((pill): pill is PlaceMenuPill => 'menu' in pill);

        expect(pills?.map((pill) => pill.label)).toEqual(['Opiekun: moi klienci', 'Wizja: umówiona']);
        expect(decodeURIComponent(pills?.[0].menu[0].href as string)).toBe('/clients?f[wizja]=umowiona');
        expect(pills?.[1].menu[0].href).toBe('/clients?assignee=me');
    });

    test('clearing the filters puts the status and the type back to the defaults and keeps the search', () => {
        const filtered: ListQuery = { stage: 'churned', cooperation_type: 'hourly', search: 'kawa' };
        expect(listQuery({ ...filtered, ...CLEAR_FILTERS })).toEqual({ search: 'kawa' });
    });
});

describe('the first run', () => {
    test('an account with no client in any stage shows the dog looking up, one line and New client, and no pills', () => {
        const empty = { active: 0, paused: 0, former: 0 };
        const html = renderToStaticMarkup(<FirstRun onCreate={() => {}} />);

        expect(noClients(empty)).toBe(true);
        expect(noClients({ ...empty, former: 1 })).toBe(false);
        expect(html).toContain('data-pose="look-up"');
        expect(html).toContain('Nie masz jeszcze klientów.');
        expect(html).toMatch(/<button[^>]*>Nowy klient<\/button>/);
        expect(clientListPlace({ filters: {}, stage_counts: empty, cooperation_counts: {}, card: null } as never, context)).toEqual({});
    });
});
