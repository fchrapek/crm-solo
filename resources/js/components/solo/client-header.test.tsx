import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';
import { ClientHeader, clientHeaderSize, type ClientHeaderData } from './client-header';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const client: ClientHeaderData = {
    id: 7,
    name: 'Pracownia Testowa Sp. z o.o.',
    short_name: 'Pracownia Testowa',
    billing_mode: 'retainer' as const,
    is_archived: false,
    is_pinned: false,
    open_tasks_count: 54,
    live_agent_minutes: null,
};

describe('ClientHeader', () => {
    test('Przegląd and Zadania open on the poster, the record tabs on the compact name', () => {
        expect(clientHeaderSize('przeglad')).toBe('poster');
        expect(clientHeaderSize('zadania')).toBe('poster');
        expect(clientHeaderSize('dziennik')).toBe('compact');
        expect(clientHeaderSize('rozliczenia')).toBe('compact');
        expect(clientHeaderSize('dane')).toBe('compact');
    });

    test('the short name is the poster, the full name its title, and every tab links to its own url', () => {
        const html = renderToStaticMarkup(<ClientHeader client={client} tab="dane" />);
        expect(html).toContain('title="Pracownia Testowa Sp. z o.o."');
        expect(html).toContain('>Pracownia Testowa</h1>');
        for (const tab of ['przeglad', 'zadania', 'dziennik', 'rozliczenia', 'dane']) {
            expect(html).toContain(`href="/clients/7/${tab}"`);
        }
        expect(html).toContain('aria-current="page" href="/clients/7/dane"');
        expect(html).toMatch(/Zadania<span[^>]*><span[^>]*> · <\/span>54<\/span>/);
    });

    test('the title hands its length to the poster, which steps a long name down', () => {
        const html = renderToStaticMarkup(<ClientHeader client={client} tab="przeglad" />);
        expect(html).toContain('style="--record-title-chars:17"');
    });

    test('the header carries no stage pill; an archived client keeps its marker', () => {
        const withStage = { ...client, lifecycle_stage: 'active' };
        const html = renderToStaticMarkup(<ClientHeader client={withStage} tab="przeglad" />);
        expect(html).not.toContain('Aktywny');
        expect(html).not.toContain('zarchiwizowany');
        expect(renderToStaticMarkup(<ClientHeader client={{ ...client, is_archived: true }} tab="przeglad" />)).toContain('Klient zarchiwizowany');
    });

    test('a running agent shows as a live line, and nothing shows without one', () => {
        expect(renderToStaticMarkup(<ClientHeader client={client} tab="dane" />)).not.toContain('pracuje');
        expect(renderToStaticMarkup(<ClientHeader client={{ ...client, live_agent_minutes: 14 }} tab="dane" />)).toContain('claude pracuje · 14 min');
    });
});
