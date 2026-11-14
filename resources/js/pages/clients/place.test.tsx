import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import { type ClientHeaderData } from '@/components/solo/client-header';
import { type PlaceLinkPill } from '@/components/solo/shell/place';
import pl from '../../../../lang/pl.json';
import { SwitcherRows, switcherHref } from './components/client-switcher';
import { clientPlace, projectPlace } from './place';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const context = { t: (key: string, options?: Record<string, unknown>) => i18next.t(key, options), url: '/clients/7/zadania', locale: 'pl' };

const client: ClientHeaderData = {
    id: 7,
    name: 'Pracownia Testowa Sp. z o.o.',
    short_name: 'Pracownia Testowa',
    billing_mode: 'retainer',
    billing: true,
    is_archived: false,
    is_pinned: false,
    open_tasks_count: 25,
    live_agent_minutes: null,
};

const pills = (place: ReturnType<typeof clientPlace>) => (place.pills ?? []) as PlaceLinkPill[];

describe('the place of a client tab', () => {
    test('the tokens are the client, then the tab; the client level opens the switcher and the tab is the page itself', () => {
        const { tokens = [] } = clientPlace({ client, tab: 'dziennik' }, context);

        expect(tokens.map((token) => token.label)).toEqual(['Pracownia Testowa', 'Dziennik']);
        expect(tokens[0].href).toBe('/clients/7/przeglad');
        expect(tokens[0].switcher).toBeFunction();
        expect(tokens[1].href).toBeUndefined();
        expect(clientPlace({ client, tab: 'przeglad' }, context).tokens?.map((token) => token.label)).toEqual(['Pracownia Testowa', 'Przegląd']);
    });

    test('the tabs are the pills: each links to its own url, exactly the open one is active and Zadania carries the open tasks', () => {
        const tabs = pills(clientPlace({ client, tab: 'zadania' }, context));

        expect(tabs.map((pill) => [pill.label, pill.href, pill.active, pill.count])).toEqual([
            ['Przegląd', '/clients/7/przeglad', false, undefined],
            ['Zadania', '/clients/7/zadania', true, 25],
            ['Dziennik', '/clients/7/dziennik', false, undefined],
            ['Rozliczenia', '/clients/7/rozliczenia', false, undefined],
            ['Dane', '/clients/7/dane', false, undefined],
        ]);
        expect(pills(clientPlace({ client: { ...client, open_tasks_count: 0 }, tab: 'dane' }, context))[1].count).toBeUndefined();
    });

    test('an account with billing hidden from the viewer has no Rozliczenia pill', () => {
        const labels = pills(clientPlace({ client: { ...client, billing: false }, tab: 'przeglad' }, context)).map((pill) => pill.label);

        expect(labels).toEqual(['Przegląd', 'Zadania', 'Dziennik', 'Dane']);
    });
});

describe('the place of a project page', () => {
    const place = projectPlace(
        {
            client,
            project: { id: 3, name: 'Strona WWW' },
            projects: [
                { id: 3, name: 'Strona WWW', open: 16 },
                { id: 4, name: 'Galeria realizacji', open: 9 },
            ],
            open_tasks_count: 25,
        },
        context,
    );

    test('the tokens are the client, Zadania and the project, each level above the page a way up', () => {
        expect(place.tokens?.map((token) => [token.label, token.href])).toEqual([
            ['Pracownia Testowa', '/clients/7/przeglad'],
            ['Zadania', '/clients/7/zadania'],
            ['Strona WWW', undefined],
        ]);
        expect(place.tokens?.[0].switcher).toBeFunction();
    });

    test("the pills are the client's projects with their open tasks, and Wszystkie leads back to the Zadania tab", () => {
        expect(pills(place).map((pill) => [pill.label, pill.href, pill.active ?? false, pill.count])).toEqual([
            ['Wszystkie', '/clients/7/zadania', false, 25],
            ['Strona WWW', '/clients/7/projects/3', true, 16],
            ['Galeria realizacji', '/clients/7/projects/4', false, 9],
        ]);
    });
});

describe('the place of Rozliczenia', () => {
    const client = { id: 7, name: 'Pracownia Mebli sp. z o.o.', short_name: 'Pracownia Mebli', billing_mode: 'retainer' as const, is_archived: false, is_pinned: false, open_tasks_count: 25, live_agent_minutes: null };
    const context = { t: (key: string) => ({ Overview: 'Przegląd', Tasks: 'Zadania', Journal: 'Dziennik', Billing: 'Rozliczenia', Data: 'Dane' })[key] ?? key, url: '/clients/7/rozliczenia?miesiac=2026-09', locale: 'pl' };

    test('the month shown is the third token, and Rozliczenia before it leads back to the tab', () => {
        const place = clientPlace({ client, tab: 'rozliczenia', billing_month: { period: '2026-09' } }, context);

        expect(place.tokens?.map((token) => [token.label, token.href])).toEqual([
            ['Pracownia Mebli', '/clients/7/przeglad'],
            ['Rozliczenia', '/clients/7/rozliczenia'],
            ['Wrzesień', undefined],
        ]);
    });

    test('the pills stay the client tabs, with Rozliczenia the current one', () => {
        const place = clientPlace({ client, tab: 'rozliczenia', billing_month: { period: '2026-09' } }, context);

        expect(place.pills?.map((pill) => pill.label)).toEqual(['Przegląd', 'Zadania', 'Dziennik', 'Rozliczenia', 'Dane']);
        expect(place.pills?.filter((pill) => pill.active).map((pill) => pill.label)).toEqual(['Rozliczenia']);
    });

    test('an order client has no month, so Rozliczenia is the last token; another tab never shows one', () => {
        expect(clientPlace({ client, tab: 'rozliczenia' }, context).tokens?.map((token) => token.label)).toEqual(['Pracownia Mebli', 'Rozliczenia']);
        expect(clientPlace({ client, tab: 'zadania', billing_month: { period: '2026-09' } }, context).tokens?.map((token) => token.label)).toEqual(['Pracownia Mebli', 'Zadania']);
    });
});

describe('the client switcher', () => {
    test('a chosen client opens on the tab the switcher was opened on', () => {
        expect(switcherHref(9, 'dziennik')).toBe('/clients/9/dziennik');

        const html = renderToStaticMarkup(
            <SwitcherRows
                clientId={7}
                tab="rozliczenia"
                groups={[
                    { head: 'Przypięci', clients: [{ id: 7, name: 'Pracownia Testowa' }] },
                    { head: 'Ostatnie', clients: [{ id: 9, name: 'Fundacja Testowa' }] },
                ]}
            />,
        );

        expect([...html.matchAll(/href="([^"]+)"/g)].map(([, href]) => href)).toEqual(['/clients/7/rozliczenia', '/clients/9/rozliczenia']);
    });

    test('the open client is marked, and a group without clients is left out', () => {
        const html = renderToStaticMarkup(
            <SwitcherRows
                clientId={7}
                tab="przeglad"
                groups={[
                    { head: 'Przypięci', clients: [] },
                    {
                        head: 'Ostatnie',
                        clients: [
                            { id: 7, name: 'Pracownia Testowa' },
                            { id: 9, name: 'Fundacja Testowa' },
                        ],
                    },
                ]}
            />,
        );

        expect(html).not.toContain('Przypięci');
        expect(html.match(/aria-current="true"/g)).toHaveLength(1);
        expect(html).toMatch(/aria-current="true"[^>]*href="\/clients\/7\/przeglad"|href="\/clients\/7\/przeglad"[^>]*aria-current="true"/);
    });
});
