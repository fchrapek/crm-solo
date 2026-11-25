import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';
import { ClientHeader, type ClientHeaderData, HeaderAction } from './client-header';

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
    test('the short name is the poster and the full name its title; the head draws no tabs, trail or back link', () => {
        const html = renderToStaticMarkup(<ClientHeader client={client} />);

        expect(html).toMatch(/<h1[^>]*title="Pracownia Testowa Sp. z o.o."[^>]*>Pracownia Testowa<\/h1>/);
        expect(html).not.toContain('<nav');
        expect(html).not.toContain('<a ');
    });

    test('the name is set like a task title: a name past 60 characters steps down, and every line shows', () => {
        const long = 'Przedsiębiorstwo Wielobranżowe Żółć Gęślą Jaźń Spółka z ograniczoną odpowiedzialnością';
        const stepped = renderToStaticMarkup(<ClientHeader client={{ ...client, name: long, short_name: long }} />);

        expect(renderToStaticMarkup(<ClientHeader client={client} />)).not.toContain('data-long');
        expect(stepped).toMatch(new RegExp(`<h1 class="[^"]*" title="${long}" data-long="">${long}</h1>`));
    });

    test('the header carries no stage pill; an archived client keeps its marker', () => {
        const withStage = { ...client, lifecycle_stage: 'active' };
        const html = renderToStaticMarkup(<ClientHeader client={withStage} />);

        expect(html).not.toContain('Aktywny');
        expect(html).not.toContain('zarchiwizowany');
        expect(renderToStaticMarkup(<ClientHeader client={{ ...client, is_archived: true }} />)).toContain('Klient zarchiwizowany');
    });

    test('a running agent shows as the chip above the name, and nothing shows without one', () => {
        expect(renderToStaticMarkup(<ClientHeader client={client} />)).not.toContain('pracuje');
        expect(renderToStaticMarkup(<ClientHeader client={{ ...client, live_agent_minutes: 14 }} />)).toMatch(
            /claude pracuje · 14 min<\/span><\/div><div[^>]*><div[^>]*><h1/,
        );
    });

    test('the actions are buttons beside the name, and on a phone one main button with the rest behind "more"', () => {
        const html = renderToStaticMarkup(
            <ClientHeader
                client={client}
                actions={<HeaderAction>Generuj raport</HeaderAction>}
                main={<HeaderAction primary>Nowe zadanie</HeaderAction>}
            />,
        );

        expect(html.match(/<button type="button"[^>]*>Generuj raport<\/button>/g)).toHaveLength(1);
        expect(html).toMatch(/<button type="button"[^>]*>Nowe zadanie<\/button><button[^>]*aria-label="Więcej akcji"/);
        expect(renderToStaticMarkup(<ClientHeader client={client} />)).not.toContain('Więcej akcji');
    });
});
