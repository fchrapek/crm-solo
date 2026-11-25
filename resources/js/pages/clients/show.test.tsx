import * as inertia from '@inertiajs/react';
import { afterAll, describe, expect, mock, spyOn, test } from 'bun:test';
import i18next from 'i18next';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';
import * as clientView from './client-view';
import ClientShow from './show';

// Bun runs no DOM, so no effect runs here: this proves the page hands its props to useClientView on every render,
// and client-view.test.ts proves what the hook reports for each showing. That the effect fires is React's part.
const calls: Array<[number, string, number]> = [];
let page: Record<string, unknown> = {};

spyOn(inertia, 'usePage').mockImplementation((() => ({ url: '/clients/7/tasks', props: page })) as typeof inertia.usePage);
spyOn(inertia, 'Head').mockImplementation((() => null) as unknown as typeof inertia.Head);
spyOn(clientView, 'useClientView').mockImplementation((...args) => void calls.push(args));
afterAll(() => mock.restore());

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

function show(props: Record<string, unknown>): string {
    page = { auth: { user: { id: 1, name: 'Anna' } }, errors: {}, client: { id: 7, short_name: 'Pracownia', name: 'Pracownia' }, ...props };

    return renderToStaticMarkup(createElement(ClientShow));
}

describe('the client page', () => {
    test('reports its opening with the client, the tab and when the server read its data', () => {
        calls.length = 0;

        show({ tab: 'tasks', read_at: 1_000 });
        show({ tab: 'tasks', read_at: 1_030 });

        expect(calls).toEqual([
            [7, 'tasks', 1_000],
            [7, 'tasks', 1_030],
        ]);
    });

    test('draws the real client header with the client name', () => {
        expect(show({ tab: 'tasks', read_at: 1_000 })).toMatch(/<h1[^>]*>Pracownia<\/h1>/);
    });
});
