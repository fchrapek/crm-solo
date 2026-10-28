import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';
import { DayTrail } from './day-trail';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const css = () => Bun.file(new URL('../ui/toggle-group/toggle-group.module.css', import.meta.url)).text();
const wideRules = async () => {
    const text = await css();

    return text.slice(text.lastIndexOf('@media (min-width: 768px)'));
};

describe('DayTrail', () => {
    test('one nav and one set of links on every width, so the focus survives a resize; the current step marked by aria-current', () => {
        const html = renderToStaticMarkup(<DayTrail current="done" />);
        const links = [...html.matchAll(/<a ([^>]*)>(.*?)<\/a>/g)].map(([, attrs, inner]) => ({
            current: attrs.match(/aria-current="([^"]+)"/)?.[1] ?? null,
            href: attrs.match(/href="([^"]+)"/)?.[1],
            label: inner.replace(/<[^>]+>/g, ''),
        }));

        expect(html).toMatch(/^<nav aria-label="[^"]+" data-width="full" data-wide="trail"/);
        expect(html.match(/<nav /g)).toHaveLength(1);
        expect(links).toEqual([
            { current: null, href: '/?widok=dzis', label: 'Dziś' },
            { current: 'step', href: '/?widok=gotowe', label: 'Podsumowanie' },
            { current: null, href: '/jutro', label: 'Jutro' },
            { current: null, href: '/tydzien', label: 'Tydzień' },
        ]);
        expect(html).not.toContain('<button');
    });

    test('Podsumowanie hugs its label, and carries the filled checkmark-circle once the day is closed', () => {
        const open = renderToStaticMarkup(<DayTrail current="today" />);
        const closed = renderToStaticMarkup(<DayTrail current="today" closed />);

        expect(open.match(/<a [^>]*data-hug="true"[^>]*>(.*?)<\/a>/)?.[1]).toBe('<span>Podsumowanie</span>');
        expect(open).not.toContain('data-icon="done"');
        expect(closed).toMatch(/<a [^>]*data-hug="true"[^>]*><span[^>]*role="img" aria-label="Dzień zamknięty"[^>]*data-icon="done"/);
        expect(closed.match(/data-icon="done"/g)).toHaveLength(1);
    });

    test('off the stages no step is current', () => {
        expect(renderToStaticMarkup(<DayTrail />)).not.toContain('aria-current');
    });

    test('Width=Full fills the column on an edged track, the current segment in the surface ink, 44 px segments', async () => {
        const text = await css();
        const full = text.match(/\.group\.segmented\[data-width="full"\] \{([^}]*)\}/)?.[1] ?? '';

        expect(full).toContain('--solo-seg-border: var(--solo-fg);');
        expect(full).toContain('--solo-seg-on: var(--solo-fg);');
        expect(full).toContain('--solo-seg-on-fg: var(--solo-bg);');
        expect(full).toContain('width: 100%;');
        expect(text).toMatch(/\.group\.segmented \.item\[data-state="on"\],\n\.group\.segmented \.item\[aria-current\] \{/);
        expect(text).toMatch(/\.group\.segmented \.item \{[^}]*height: var\(--solo-touch\);/);
    });

    test('from 768 px the same links read as the caps text trail: no track, muted caps steps, the current one underlined, arrows between', async () => {
        const wide = await wideRules();
        const rule = (selector: string) => wide.match(new RegExp(`${selector.replace(/[.[\]"()+]/g, '\\$&')} \\{([^}]*)\\}`))?.[1] ?? '';

        expect(rule('.group.segmented[data-wide="trail"]')).toMatch(/width: auto;[\s\S]*padding: 0;[\s\S]*border: 0;[\s\S]*background: none;/);
        expect(rule('.group.segmented[data-wide="trail"] .item[data-hug]')).toMatch(/height: auto;[\s\S]*text-transform: var\(--solo-label-case, uppercase\);/);
        expect(rule('.group.segmented[data-wide="trail"] .item[aria-current]')).toContain(
            'box-shadow: inset 0 calc(-1 * var(--solo-nav-line, 2px)) 0 currentColor;',
        );
        expect(rule('.group.segmented[data-wide="trail"] .item + .item::before')).toContain('content: "→" / "";');
    });
});
