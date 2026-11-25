import { afterAll, afterEach, describe, expect, mock, spyOn, test } from 'bun:test';
import i18next from 'i18next';
import { createElement, type ComponentProps } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import { dashboard, logout } from '@/routes';
import clients from '@/routes/clients';
import leads from '@/routes/leads';
import tasks from '@/routes/tasks';
import pl from '../../../../lang/pl.json';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

// Link is a forwardRef object, which spyOn cannot wrap; the file mocks the module and hands the real one back after.
const inertia = { ...(await import('@inertiajs/react')) };
const { Link: InertiaLink, router } = inertia;
const asked: Array<{ url: string; prefetch: unknown }> = [];
function RecordingLink(props: ComponentProps<typeof InertiaLink>) {
    asked.push({ url: typeof props.href === 'string' ? props.href : (props.href?.url ?? ''), prefetch: props.prefetch ?? false });

    return createElement(InertiaLink, props);
}
mock.module('@inertiajs/react', () => ({
    ...inertia,
    Link: RecordingLink,
    usePage: () => ({ url: '/tomorrow', props: { auth: { user: { name: 'Anna Zając', owner: 1 } } } }),
}));
afterAll(() => {
    mock.module('@inertiajs/react', () => inertia);
    mock.restore();
});

const { forgetPrefetchedPages, prefetchOn } = await import('./link-prefetch');
const { Rail } = await import('./shell/rail');
const { TabBar } = await import('./tab-bar');
const { Pill } = await import('./shell/pills');
const { Token } = await import('./shell/command-bar');
const { Row } = await import('./row');
const { TaskRow } = await import('./task-row');

function linksOf(element: Parameters<typeof renderToStaticMarkup>[0]) {
    asked.length = 0;
    renderToStaticMarkup(element);

    return asked.map(({ url, prefetch }) => [url, prefetch]);
}

describe('which links fetch their page ahead', () => {
    test('plain GET pages of the app do, on the trigger the device has', () => {
        expect(prefetchOn(dashboard(), 'hover')).toBe('hover');
        expect(prefetchOn(clients.index(), 'click')).toBe('click');
        expect(prefetchOn(tasks.show(4), 'hover')).toBe('hover');
        expect(prefetchOn('/all-tasks?for=tomorrow', 'hover')).toBe('hover');
        expect(prefetchOn(leads.index(), 'hover')).toBe('hover');
        expect(prefetchOn(clients.show({ client: 7, tab: 'overview' }), 'hover')).toBe('hover');
        expect(prefetchOn(clients.show({ client: 7, tab: 'billing' }).url + '?month=2026-10', 'hover')).toBe('hover');
        expect(prefetchOn(clients.record(7), 'click')).toBe('click');
    });

    test('a write and an outside link never do', () => {
        expect(prefetchOn(logout(), 'hover')).toBe(false);
        expect(prefetchOn('https://docs.example.com/t/dzis', 'hover')).toBe(false);
        expect(prefetchOn('//example.com/', 'hover')).toBe(false);
    });

    test('the rail and the tab bar ask for every section, the logout never', () => {
        expect(linksOf(<Rail timer={null} />)).toEqual([
            ['/', 'hover'],
            ['/clients', 'hover'],
            ['/contacts', 'hover'],
            ['/settings', 'hover'],
        ]);
        expect(linksOf(<TabBar />).every(([, prefetch]) => prefetch === 'hover')).toBe(true);
    });

    test('a pill asks unless it is a partial reload of one block, the current one too so a visit never flips it', () => {
        expect(linksOf(<Pill label="Jutro" href="/tomorrow" active />)).toEqual([['/tomorrow', 'hover']]);
        expect(linksOf(<Pill label="Tydzień" href="/week" />)).toEqual([['/week', 'hover']]);
        expect(linksOf(<Pill label="2025" href="/clients/7/billing?rok=2025" visit={{ only: ['invoices'] }} />)).toEqual([
            ['/clients/7/billing?rok=2025', false],
        ]);
        expect(linksOf(<Pill label="Zadania" href={clients.show({ client: 7, tab: 'tasks' })} />)).toEqual([['/clients/7/tasks', 'hover']]);
    });

    test('a command bar token, a row and a task row ask for the page they open', () => {
        expect(linksOf(<Token label="Klienci" href={clients.index()} />)).toEqual([['/clients', 'hover']]);
        expect(linksOf(<Token label="Acme" href={clients.show({ client: 7, tab: 'overview' })} />)).toEqual([['/clients/7/overview', 'hover']]);
        expect(linksOf(<Row title="Zadanie" href={tasks.show(4)} />)).toEqual([['/tasks/4', 'hover']]);
        expect(linksOf(<Row title="Wszystkie" href="/clients/7/tasks?expand=1" visit={{ preserveScroll: true }} />)).toEqual([
            ['/clients/7/tasks?expand=1', false],
        ]);
        expect(linksOf(<TaskRow task={{ task_id: 4, name: 'Korekta', client: null, project: null, done: false }} />)).toEqual([
            ['/tasks/4', 'hover'],
        ]);
    });
});

describe('prefetched pages after a write', () => {
    const on = spyOn(router, 'on');
    const flushAll = spyOn(router, 'flushAll').mockImplementation(() => {});
    afterEach(() => {
        on.mockClear();
        flushAll.mockClear();
    });

    type Handler = (event: { detail: { visit: object } }) => unknown;
    const page = (path: string) => new URL(path, 'https://crm-solo.test');

    function listeners() {
        on.mockImplementation(() => () => {});
        forgetPrefetchedPages();
        const handler = (name: string) => on.mock.calls.find(([event]) => event === name)?.[1] as Handler;

        return { before: handler('before'), start: handler('start'), finish: handler('finish') };
    }

    test('a write starting drops every copy, a page visit starting keeps them for the click', () => {
        const { before } = listeners();

        before({ detail: { visit: { method: 'get', prefetch: false, url: page('/contacts') } } });
        expect(flushAll).not.toHaveBeenCalled();
        before({ detail: { visit: { method: 'post', prefetch: false, url: page('/day/close') } } });
        expect(flushAll).toHaveBeenCalledTimes(1);
    });

    test('any real visit finishing drops every copy, a prefetch finishing does not though Inertia has cleared its flag by then', () => {
        const { finish } = listeners();

        finish({ detail: { visit: { method: 'get', prefetch: false, url: page('/contacts'), headers: { Purpose: 'prefetch' } } } });
        expect(flushAll).not.toHaveBeenCalled();
        finish({ detail: { visit: { method: 'get', prefetch: false, url: page('/'), headers: {}, only: ['timer'] } } });
        finish({ detail: { visit: { method: 'patch', prefetch: false, url: page('/profile/appearance'), headers: {} } } });
        expect(flushAll).toHaveBeenCalledTimes(2);
    });

    test('a hover coming due while a click fetches the same page is refused, another page and a later hover are not', () => {
        const { before, start, finish } = listeners();
        const prefetch = (path: string) => before({ detail: { visit: { method: 'get', prefetch: true, url: page(path) } } });

        expect(prefetch('/contacts')).toBe(true);
        start({ detail: { visit: { method: 'get', prefetch: false, url: page('/contacts') } } });
        expect(prefetch('/contacts')).toBe(false);
        expect(prefetch('/settings')).toBe(true);
        finish({ detail: { visit: { method: 'get', prefetch: false, url: page('/contacts'), headers: {} } } });
        expect(prefetch('/contacts')).toBe(true);
    });

    test('a prefetch starting is not a visit underway', () => {
        const { before, start } = listeners();

        start({ detail: { visit: { method: 'get', prefetch: true, url: page('/contacts') } } });
        expect(before({ detail: { visit: { method: 'get', prefetch: true, url: page('/contacts') } } })).toBe(true);
    });
});
