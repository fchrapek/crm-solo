import { describe, expect, test } from 'bun:test';
import { renderToStaticMarkup } from 'react-dom/server';

import { Icon, ICON_NAMES } from './icon';

describe('Icon', () => {
    test('every icon carries a Fluent glyph for Paper and a Lucide one for Boring SaaS, at the asked size', () => {
        expect(ICON_NAMES.length).toBeGreaterThan(50);
        for (const name of ICON_NAMES) {
            for (const size of [16, 20, 24] as const) {
                const html = renderToStaticMarkup(<Icon name={name} size={size} />);
                const glyphs = [...html.matchAll(/<svg[^>]*>/g)].map(([svg]) => svg);

                expect(glyphs).toHaveLength(2);
                expect(glyphs[0]).toContain(`viewBox="0 0 ${size} ${size}"`);
                expect(html).toMatch(/<svg[^>]*viewBox[^>]*><path d="[^"]+"/);
                expect(glyphs[1]).toContain('class="lucide');
                expect(glyphs.every((svg) => svg.includes(`width="${size}"`) && svg.includes(`height="${size}"`))).toBe(true);
            }
        }
    });

    test('Paper draws the outline glyph, and the filled one only where asked for a state', () => {
        const paper = (html: string) => html.match(/<svg[^>]*fill="currentColor" aria-hidden="true">(.*?)<\/svg>/)?.[1];

        expect(paper(renderToStaticMarkup(<Icon name="calendar" size={24} filled />))).not.toBe(paper(renderToStaticMarkup(<Icon name="calendar" size={24} />)));
        expect(paper(renderToStaticMarkup(<Icon name="trash" size={24} filled />))).toBe(paper(renderToStaticMarkup(<Icon name="trash" size={24} />)));
    });

    test('filled draws the solid glyph in both themes; decorative unless labelled', () => {
        const pinned = renderToStaticMarkup(<Icon name="pin" filled />);
        const open = renderToStaticMarkup(<Icon name="pin" />);
        const labelled = renderToStaticMarkup(<Icon name="pin" aria-label="Przypięty" role="img" />);

        expect(pinned).toMatch(/class="lucide lucide-pin"/);
        expect(pinned.match(/<svg[^>]*class="lucide[^"]*"[^>]*>/)?.[0] ?? pinned).toMatch(/fill="currentColor"/);
        expect(open).toMatch(/<svg[^>]*fill="none"[^>]*class="lucide lucide-pin"/);
        expect(open).toMatch(/^<span aria-hidden="true"/);
        expect(labelled).not.toContain('aria-hidden="true" class=""');
        expect(labelled).toMatch(/^<span role="img" aria-label="Przypięty"/);
    });

    test('an icon named by aria-label or aria-labelledby is an image, any other is hidden', () => {
        expect(renderToStaticMarkup(<Icon name="pin" aria-label="Przypięty" />)).toMatch(/^<span role="img" aria-label="Przypięty"/);
        expect(renderToStaticMarkup(<Icon name="pin" aria-labelledby="pin-label" />)).toMatch(/^<span role="img" aria-labelledby="pin-label"/);
        expect(renderToStaticMarkup(<Icon name="pin" />)).toMatch(/^<span aria-hidden="true"/);
    });

    test('a badge leaves an Icon at its own size', async () => {
        const css = await Bun.file(new URL('../badge/badge.module.css', import.meta.url)).text();

        expect(css).toContain('.badge svg:not([data-icon] > svg) {');
    });

    test('Paper shows the Fluent glyph and Boring SaaS the Lucide one, with the stroke set in pixels per size', async () => {
        const css = await Bun.file(new URL('./icon.module.css', import.meta.url)).text();
        const rule = (selector: string) => css.match(new RegExp(`(^|\\n)${selector.replace(/[.*()[\]'>]/g, '\\$&')} \\{([^}]*)\\}`))?.[2] ?? '';

        // Hiding must outrank the .icon > svg display rule, or Paper draws both glyphs.
        expect(rule('.icon > svg')).toContain('display: block;');
        expect(rule('.icon > .saas')).toContain('display: none;');
        expect(css).toMatch(/:global\(:root\[data-theme='boring-saas'\]\) \.paper \{\s*display: none;/);
        expect(css).toMatch(/:global\(:root\[data-theme='boring-saas'\]\) \.icon > \.saas \{\s*display: block;/);
        expect(css).toMatch(/\.size16 > :global\(svg\.lucide\) \{\s*stroke-width: calc\(var\(--icon-stroke-16\) \* 24 \/ 16\);/);
    });
});
