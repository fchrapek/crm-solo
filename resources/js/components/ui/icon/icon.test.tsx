import { describe, expect, test } from 'bun:test';
import { renderToStaticMarkup } from 'react-dom/server';

import { Icon, ICON_NAMES } from './icon';

describe('Icon', () => {
    test('every icon draws exactly one svg, the Fluent glyph at the asked size', () => {
        expect(ICON_NAMES.length).toBeGreaterThan(50);
        for (const name of ICON_NAMES) {
            for (const size of [16, 20, 24] as const) {
                const html = renderToStaticMarkup(<Icon name={name} size={size} />);
                const glyphs = [...html.matchAll(/<svg[^>]*>/g)].map(([svg]) => svg);

                expect(glyphs).toHaveLength(1);
                expect(glyphs[0]).toContain(name === 'arrow-up-right' && size === 16 ? 'viewBox="-2 -2 16 16"' : `viewBox="0 0 ${size} ${size}"`);
                expect(glyphs[0]).toContain(`width="${size}"`);
                expect(glyphs[0]).toContain(`height="${size}"`);
                expect(html).toMatch(/<svg[^>]*viewBox[^>]*><path d="[^"]+"/);
                expect(html).not.toContain('lucide');
            }
        }
    });

    test('an icon draws the outline glyph, and the filled one only where asked for a state', () => {
        const paper = (html: string) => html.match(/<svg[^>]*fill="currentColor" aria-hidden="true">(.*?)<\/svg>/)?.[1];

        expect(paper(renderToStaticMarkup(<Icon name="calendar" size={24} filled />))).not.toBe(paper(renderToStaticMarkup(<Icon name="calendar" size={24} />)));
        expect(paper(renderToStaticMarkup(<Icon name="trash" size={24} filled />))).toBe(paper(renderToStaticMarkup(<Icon name="trash" size={24} />)));
    });

    test('the link arrow after a title is the compact firm glyph at 16: Fluent 12 filled, centred and unscaled, not the thin 16 regular', () => {
        const html = renderToStaticMarkup(<Icon name="arrow-up-right" size={16} />);

        expect(html).toContain('<svg width="16" height="16" viewBox="-2 -2 16 16"');
        expect(html).toContain('<path d="M1.22 10.78a.75 .75 0 0 1 0-1.06L8.44 2.5H4.75');
        expect(renderToStaticMarkup(<Icon name="arrow-up-right" size={20} />)).toContain('viewBox="0 0 20 20"');
    });

    test('an icon is decorative unless labelled', () => {
        const open = renderToStaticMarkup(<Icon name="pin" />);
        const labelled = renderToStaticMarkup(<Icon name="pin" aria-label="Przypięty" role="img" />);

        expect(open).toMatch(/^<span aria-hidden="true"/);
        expect(labelled).not.toContain('aria-hidden="true" class=""');
        expect(labelled).toMatch(/^<span role="img" aria-label="Przypięty"/);
    });

    test('an icon named by aria-label or aria-labelledby is an image, any other is hidden', () => {
        expect(renderToStaticMarkup(<Icon name="pin" aria-label="Przypięty" />)).toMatch(/^<span role="img" aria-label="Przypięty"/);
        expect(renderToStaticMarkup(<Icon name="pin" aria-labelledby="pin-label" />)).toMatch(/^<span role="img" aria-labelledby="pin-label"/);
        expect(renderToStaticMarkup(<Icon name="pin" />)).toMatch(/^<span aria-hidden="true"/);
    });
});
