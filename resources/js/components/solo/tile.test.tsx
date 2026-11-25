import { describe, expect, test } from 'bun:test';
import { renderToStaticMarkup } from 'react-dom/server';
import { TileGrid, TileStack } from './tile';

const css = await Bun.file(new URL('./tile.module.css', import.meta.url)).text();
const [phone, desktop] = css.split('@media (min-width: 1100px)');
const rule = (part: string, selector: string) => {
    const at = part.indexOf(`${selector} {`);

    return at === -1 ? null : part.slice(at, part.indexOf('}', at));
};

describe('the side column of a TileGrid', () => {
    const sticky = '.grid:not([data-reflow], [data-phone-order], [data-static-side]) > aside';

    test('from 1100 px it sticks at the shell margin while the main column scrolls, and a side taller than the screen scrolls by itself', () => {
        const side = rule(desktop, sticky);

        expect(side).not.toBeNull();
        expect(side).toContain('position: sticky;');
        expect(side).toContain('top: var(--shell-margin);');
        expect(side).toContain('max-height: calc(100dvh - 2 * var(--shell-margin));');
        expect(side).toContain('overflow-y: auto;');
        expect(css).toMatch(/\.grid \{[^}]*align-items: start;/);
    });

    test('below 1100 px nothing sticks: the one column scrolls with the page', () => {
        expect(phone).not.toContain('sticky');
    });

    test('a grid that opts out, a reflowing grid and a phone-order grid keep their side in the flow', () => {
        expect(renderToStaticMarkup(<TileGrid />)).not.toContain('data-static-side');
        expect(renderToStaticMarkup(<TileGrid stickySide={false} />)).toContain('data-static-side=""');
        expect(desktop.match(/position: sticky/g)).toHaveLength(1);
    });
});

describe('a phone-order TileGrid', () => {
    test('keeps its stacks in the DOM in the order a phone shows them, each with its place', () => {
        const html = renderToStaticMarkup(
            <TileGrid phoneOrder>
                <TileStack place="lead">Etap</TileStack>
                <TileStack place="main">Firma</TileStack>
                <TileStack as="aside" place="side">Dokumenty</TileStack>
                <TileStack place="tail">Strefa</TileStack>
            </TileGrid>,
        );

        expect(html).toContain('data-phone-order=""');
        expect(html).not.toContain('data-reflow');
        expect(html.indexOf('data-place="lead"')).toBeLessThan(html.indexOf('data-place="main"'));
        expect(html.indexOf('data-place="main"')).toBeLessThan(html.indexOf('data-place="side"'));
        expect(html.indexOf('data-place="side"')).toBeLessThan(html.indexOf('data-place="tail"'));
    });

    test('from 1100 px lead tops the side, main spans beside lead and side, tail closes main, and side rises when there is no lead', () => {
        expect(rule(desktop, ".grid[data-phone-order] > [data-place='lead']")).toContain('grid-area: 1 / 2;');
        expect(rule(desktop, ".grid[data-phone-order] > [data-place='main']")).toContain('grid-area: 1 / 1 / 3;');
        expect(rule(desktop, ".grid[data-phone-order] > [data-place='side']")).toContain('grid-area: 2 / 2 / 4;');
        expect(rule(desktop, ".grid[data-phone-order] > [data-place='tail']")).toContain('grid-area: 3 / 1;');
        expect(rule(desktop, ".grid[data-phone-order]:not(:has(> [data-place='lead'])) > [data-place='side']")).toContain('grid-row: 1 / 4;');
        expect(rule(desktop, '.grid[data-phone-order]')).toContain('grid-template-rows: auto auto 1fr;');
        expect(phone).not.toContain('data-place');
    });
});
