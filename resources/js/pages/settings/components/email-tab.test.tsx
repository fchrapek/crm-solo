import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../lang/pl.json';
import { EmailTab } from './email-tab';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const text = (html: string) => html.replace(/<[^>]+>/g, '');
const template = { key: 'monthly', name: 'Podsumowanie miesiąca', subject: 'Za {period}', body: 'Notka.', is_default: true };

describe('EmailTab', () => {
    test('while no sender is set it says every send is a test and names no sender', () => {
        const html = text(renderToStaticMarkup(<EmailTab sending={{ live: false, from: null, bcc: 'owner@agency.test' }} templates={[template]} />));

        expect(html).toContain('Wysyłka testowa: nic nie wyjdzie do klienta, dopóki nie ustawisz nadawcy.');
        expect(html).toContain('nie ustawiony');
        expect(html).toContain('owner@agency.test');
        expect(html).toContain('Szablony e-mail1Nowy szablon');
        expect(html).toContain('Domyślny');
    });

    test('a server that refuses to send says nothing goes out, not that it is a test', () => {
        const html = text(
            renderToStaticMarkup(<EmailTab sending={{ live: false, refused: true, from: null, bcc: 'owner@agency.test' }} templates={[]} />),
        );

        expect(html).toContain('Wysyłka jest wyłączona na serwerze: nic nie wyjdzie.');
        expect(html).not.toContain('Wysyłka testowa');
    });

    test('a live sender shows its address and no test notice', () => {
        const html = text(
            renderToStaticMarkup(<EmailTab sending={{ live: true, from: 'office@agency.test', bcc: 'owner@agency.test' }} templates={[]} />),
        );

        expect(html).not.toContain('Wysyłka testowa');
        expect(html).toContain('office@agency.test');
        expect(html).toContain('Bez szablonu szkic podsumowania nie powstanie.');
    });
});
