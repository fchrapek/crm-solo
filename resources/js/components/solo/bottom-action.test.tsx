import { describe, expect, test } from 'bun:test';
import { renderToStaticMarkup } from 'react-dom/server';

import { BottomAction } from './bottom-action';
import { SoloButton } from './primitives';

const read = (path: string) => Bun.file(new URL(path, import.meta.url)).text();
const rule = (css: string, selector: string) =>
    css.match(new RegExp(`(^|\\n)\\s*${selector.replace(/[.*()[\]>]/g, '\\$&')} \\{([^}]*)\\}`))?.[2] ?? '';

describe('BottomAction', () => {
    test('renders the main action once, then the secondary, and marks the page so it clears the bar', () => {
        const html = renderToStaticMarkup(
            <BottomAction secondary={<SoloButton variant="text">Zaloguj czas</SoloButton>}>
                <SoloButton variant="outline">Start</SoloButton>
            </BottomAction>,
        );

        expect(html).toContain('data-solo-bottom-action=""');
        expect(html).toContain('data-secondary=""');
        expect(html.match(/Start/g)).toHaveLength(1);
        expect(html.indexOf('Start')).toBeLessThan(html.indexOf('Zaloguj czas'));
    });

    test('without a secondary the bar asks for no room for one, and phoneOnly hides it from 768 px', async () => {
        const html = renderToStaticMarkup(
            <BottomAction phoneOnly>
                <SoloButton variant="ink">Nowe zadanie</SoloButton>
            </BottomAction>,
        );
        const css = await read('./bottom-action.module.css');
        const wide = css.slice(css.indexOf('@media (min-width: 768px)'));

        expect(html).not.toContain('data-secondary');
        expect(rule(wide, '.bar.phoneOnly')).toContain('display: none;');
    });

    test('phones fix the bar above the tab bar in the surface colour with a 48 px main button; desktop leaves the buttons in the page', async () => {
        const css = await read('./bottom-action.module.css');
        const wideAt = css.indexOf('@media (min-width: 768px)');
        const bar = rule(css.slice(0, wideAt), '.bar');

        expect(bar).toContain('position: fixed;');
        expect(bar).toContain('bottom: calc(var(--solo-tab-bar-height) + env(safe-area-inset-bottom, 0px));');
        expect(bar).toContain('background: var(--solo-bg);');
        expect(bar).toContain('flex-direction: column-reverse;');
        expect(rule(css.slice(0, wideAt), '.bar .main > *')).toContain('min-height: var(--solo-bottom-action-button);');
        expect(css.slice(wideAt)).toMatch(/\.bar,\s*\.main,\s*\.secondary \{\s*display: contents;/);
    });

    test('on phones the main button takes the surface main look whatever its variant, and from 768 px its own again', async () => {
        const css = await read('./bottom-action.module.css');
        const buttons = await read('./primitives.module.css');
        const wideAt = css.indexOf('@media (min-width: 768px)');

        expect(rule(css.slice(0, wideAt), '.main')).toContain('--solo-main-bg: var(--solo-primary, var(--solo-fg));');
        expect(rule(css.slice(0, wideAt), '.main')).toContain('--solo-main-fg: var(--solo-on-primary, var(--solo-bg));');
        expect(rule(css.slice(wideAt), '.main')).toContain('--solo-main-bg: initial;');
        for (const variant of ['.primary', '.ink', '.outline', '.stop']) {
            expect(rule(buttons, variant)).toMatch(/background: var\(--solo-main-bg, /);
            expect(rule(buttons, variant)).toMatch(/color: var\(--solo-main-fg, /);
        }
    });

    test('the page keeps 24 px between its end and the bar on phones, more with a secondary, and nothing from 768 px', async () => {
        const css = await read('../../../css/solo.css');

        expect(css).toContain(
            '--solo-bottom-action-clearance: calc(var(--solo-bottom-action-button) + 2 * var(--solo-space-3) + var(--solo-space-2));',
        );
        expect(css).toContain(
            '[data-day]:has([data-solo-tab-bar]):has([data-solo-bottom-action]) {\n    padding-bottom: calc(var(--solo-tab-bar-clearance) + var(--solo-bottom-action-clearance));',
        );
        expect(css).toMatch(
            /\[data-day\]:has\(\[data-solo-tab-bar\]\):has\(\[data-solo-bottom-action\]\[data-secondary\]\) \{\n {4}padding-bottom: calc\([^;]*--solo-bottom-action-secondary\)\);/,
        );
        expect(css).toMatch(
            /@media \(min-width: 768px\) \{\n[^}]*\[data-day\]:has\(\[data-solo-tab-bar\]\):has\(\[data-solo-bottom-action\]\) ?,?[^}]*padding-bottom: 0;/,
        );
    });
});
