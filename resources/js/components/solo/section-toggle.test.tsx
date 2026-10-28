import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';
import { SectionToggle } from './section-toggle';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

describe('SectionToggle', () => {
    test('a section starts collapsed: Pokaż, the label and summary, and no body rendered', () => {
        const html = renderToStaticMarkup(
            <SectionToggle label="Checklista" summary="1 z 3 · z Trello">
                <p>Ceny z arkusza</p>
            </SectionToggle>,
        );

        expect(html).toContain('aria-expanded="false"');
        expect(html).toContain('Pokaż');
        expect(html).toContain('>Checklista</h2>');
        expect(html).toContain('1 z 3 · z Trello');
        expect(html).not.toContain('Ceny z arkusza');
        expect(html).toMatch(/<div id="[^"]+-body"[^>]*hidden/);
    });

    test('an open section shows its body and Ukryj, and the toggle controls that body', () => {
        const html = renderToStaticMarkup(
            <SectionToggle label="Zasoby" defaultOpen>
                <p>cennik.pdf</p>
            </SectionToggle>,
        );

        expect(html).toContain('aria-expanded="true"');
        expect(html).toContain('Ukryj');
        expect(html).toContain('cennik.pdf');
        const controls = html.match(/aria-controls="([^"]+)"/)?.[1];
        expect(controls).toBeDefined();
        expect(html).toContain(`id="${controls}"`);
    });

    test('the extra action is its own control beside the toggle, never inside it', () => {
        const html = renderToStaticMarkup(
            <SectionToggle label="Zasoby" extra={<button type="button">Dodaj</button>}>
                <p>x</p>
            </SectionToggle>,
        );
        const toggle = html.slice(html.indexOf('<button type="button" class'), html.lastIndexOf('</button>'));

        expect(html).toContain('Dodaj');
        expect(toggle).not.toContain('Dodaj');
    });

    test('an empty section is the row with its summary and action only: no Pokaż, no chevron, no body', () => {
        const html = renderToStaticMarkup(
            <SectionToggle label="Zasoby" summary="na razie brak" extra={<button type="button">Dodaj</button>} empty>
                <p>Brak plików i linków.</p>
            </SectionToggle>,
        );

        expect(html).toContain('na razie brak');
        expect(html).toContain('>Dodaj</button>');
        expect(html).not.toContain('aria-expanded');
        expect(html).not.toContain('Pokaż');
        expect(html).not.toContain('<svg');
        expect(html).not.toContain('Brak plików i linków.');
    });
});

describe('SectionToggle on phones', () => {
    test('a phone summary replaces the summary below 768 px; null leaves the row with no end meta there', () => {
        const short = renderToStaticMarkup(
            <SectionToggle label="Checklista" summary="1 z 3 · z Trello" phoneSummary="1 z 3">
                <p>x</p>
            </SectionToggle>,
        );
        expect(short).toContain('data-wide-only="true">1 z 3 · z Trello</p>');
        expect(short).toContain('>1 z 3</p>');

        const none = renderToStaticMarkup(
            <SectionToggle label="Zasoby" summary="6 · 2 pliki" phoneSummary={null}>
                <p>x</p>
            </SectionToggle>,
        );
        expect(none).toContain('data-wide-only="true">6 · 2 pliki</p>');
        expect(none.match(/<p /g)).toHaveLength(1);
    });

    test('without a phone summary the summary shows at every width', () => {
        const html = renderToStaticMarkup(
            <SectionToggle label="Adres i notatki" summary="2 pola">
                <p>x</p>
            </SectionToggle>,
        );
        expect(html).not.toContain('data-wide-only');
        expect(html).toContain('>2 pola</p>');
    });
});
