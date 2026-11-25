import { router } from '@inertiajs/react';
import { afterEach, describe, expect, spyOn, test } from 'bun:test';
import * as react from 'react';

import { prefetchOn } from '@/components/solo/link-prefetch';
import clients from '@/routes/clients';
import { openingToReport, reportClientView, useClientView, type ClientShowing } from './client-view';

const realFetch = globalThis.fetch;
const realDocument = globalThis.document;

afterEach(() => {
    globalThis.fetch = realFetch;
    globalThis.document = realDocument;
});

// Bun runs no DOM, so the hook's effect cannot run here; it hands every showing to openingToReport, tested as the hook calls it.
function showings(...pages: ClientShowing[]): Array<number | null> {
    let last: ClientShowing | null = null;

    return pages.map((shown) => {
        const report = openingToReport(last, shown);
        last = shown;

        return report;
    });
}

describe('opening a client', () => {
    test('the first showing reports once, with when its data was read, and a reload of its props reports nothing', () => {
        expect(showings({ clientId: 7, tab: 'overview', readAt: 1_000 }, { clientId: 7, tab: 'overview', readAt: 1_030 })).toEqual([1_000, null]);
    });

    test('a page shown from the prefetch cache reports the moment the prefetch read it, not the moment it was shown', () => {
        // Inertia mounts a visited page afresh, so the hook starts with no showing behind it.
        expect(openingToReport(null, { clientId: 7, tab: 'overview', readAt: 990 })).toBe(990);
    });

    test('another tab and another client are openings of their own', () => {
        expect(
            showings(
                { clientId: 7, tab: 'overview', readAt: 1_000 },
                { clientId: 7, tab: 'journal', readAt: 1_010 },
                { clientId: 8, tab: 'journal', readAt: 1_020 },
            ),
        ).toEqual([1_000, 1_010, 1_020]);
    });

    test('its links fetch the page ahead like any other, the fetch itself recording nothing', () => {
        expect(prefetchOn(clients.show({ client: 7, tab: 'overview' }), 'hover')).toBe('hover');
        expect(prefetchOn(clients.show({ client: 7, tab: 'tasks' }), 'click')).toBe('click');
    });

    test('the report posts once with the session token and the read moment, and keeps the prefetched pages', async () => {
        const flushAll = spyOn(router, 'flushAll').mockImplementation(() => {});
        const sent: Array<{ url: string; init: RequestInit }> = [];
        globalThis.document = { cookie: 'XSRF-TOKEN=abc%3D' } as Document;
        globalThis.fetch = (async (url: string, init: RequestInit) => {
            sent.push({ url, init });

            return new Response(null, { status: 204 });
        }) as unknown as typeof fetch;

        await reportClientView(7, 1_000);

        expect(sent).toHaveLength(1);
        expect(sent[0].url).toBe('/clients/7/view');
        expect(sent[0].init.method).toBe('POST');
        expect(JSON.parse(String(sent[0].init.body))).toEqual({ read_at: 1_000 });
        expect((sent[0].init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc=');
        expect(flushAll).not.toHaveBeenCalled();
        flushAll.mockRestore();
    });

    test('the hook reports an opening once, with its read moment, and not a reload of the same tab', async () => {
        // React runs no effect without a DOM: the hook's effect runs at once here, and its ref lives across the calls like one mounted page's.
        const ref = { current: null };
        const spies = [
            spyOn(react, 'useRef').mockImplementation((() => ref) as typeof react.useRef),
            spyOn(react, 'useEffect').mockImplementation((effect) => void effect()),
        ];
        const posted: unknown[] = [];
        globalThis.document = { cookie: '' } as Document;
        globalThis.fetch = (async (_url: string, init: RequestInit) => {
            posted.push(JSON.parse(String(init.body)));

            return new Response(null, { status: 204 });
        }) as unknown as typeof fetch;

        try {
            useClientView(7, 'overview', 1_000);
            useClientView(7, 'overview', 1_030);
            useClientView(7, 'tasks', 1_040);
            await new Promise((resolve) => setTimeout(resolve, 20));
        } finally {
            spies.forEach((spy) => spy.mockRestore());
        }

        expect(posted).toEqual([{ read_at: 1_000 }, { read_at: 1_040 }]);
    });

    test('a report the server refuses leaves the page alone and is not sent again', async () => {
        let posts = 0;
        globalThis.document = { cookie: '' } as Document;
        globalThis.fetch = (async () => {
            posts += 1;

            return new Response('{}', { status: 422 });
        }) as unknown as typeof fetch;

        expect(await reportClientView(7, 1_000)).toBeUndefined();
        await new Promise((resolve) => setTimeout(resolve, 20));
        expect(posts).toBe(1);
    });
});
