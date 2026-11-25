import * as inertia from '@inertiajs/react';
import { afterAll, describe, expect, mock, spyOn, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

let helper: { desktop: boolean; phone: boolean } | undefined;
spyOn(inertia, 'usePage').mockImplementation((() => ({
    component: 'today/index',
    url: '/',
    props: {
        auth: { user: { name: 'Anna Zając', account: { features: { billing: true } }, helper } },
        timers: [],
        docsUrl: '',
        errors: {},
        flash: {},
    },
})) as typeof inertia.usePage);
afterAll(() => mock.restore());

const { default: ShellLayout } = await import('./shell-layout');

const corner = () => renderToStaticMarkup(<ShellLayout>{null}</ShellLayout>).match(/<aside[^>]*data-shell-helper[^>]*>/)?.[0] ?? '';

describe('the shell and the helper switches', () => {
    test("the shell renders the helper once with the user's two switches as data attributes", () => {
        helper = { desktop: false, phone: true };
        expect(corner()).toContain('data-phone=""');
        expect(corner()).not.toContain('data-desktop');

        helper = { desktop: true, phone: false };
        expect(corner()).toContain('data-desktop=""');
        expect(corner()).not.toContain('data-phone');
    });

    test('without a saved choice a computer shows the helper and a phone does not', () => {
        helper = undefined;
        expect(corner()).toContain('data-desktop=""');
        expect(corner()).not.toContain('data-phone');
    });
});
