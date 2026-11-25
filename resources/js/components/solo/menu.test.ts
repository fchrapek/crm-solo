import { describe, expect, test } from 'bun:test';
import { Glob } from 'bun';
import { menuCollisionPadding } from './menu';

const read = (path: string) => Bun.file(new URL(path, import.meta.url)).text();

describe('one Solo menu look', () => {
    test('Menu / Actions is 300 px with 44 px rows on phones and 240 px with 34 px rows from 768 px, the chosen row red without a check', async () => {
        const css = await read('./menu.module.css');
        const [phone, desktop] = css.split('@media (min-width: 768px)');

        expect(phone).toMatch(/\.box \{[^}]*width: 300px;[^}]*padding: var\(--solo-space-2\) var\(--solo-space-4\);/);
        expect(phone).toMatch(/\.box \.row \{[^}]*min-height: var\(--solo-touch\);/);
        expect(phone).toMatch(/\[aria-pressed='true'\], \[data-state='checked'\]\):focus \{\s*color: var\(--solo-accent\);/);
        expect(desktop).toMatch(/\.box \{\s*width: 240px;/);
        expect(desktop).toMatch(/\.box \.row \{\s*min-height: 34px;/);
    });

    test('the box pads 8 px top and bottom, so a row band sits 8 px from every edge, and text that opens a menu adds its own 8 px', async () => {
        const css = await read('./menu.module.css');
        const opening = css.match(
            /\.box:not\(\.fill\) > :first-child:is\(\.head, \.groupLabel, \.fill, \[data-slot='dropdown-menu-label'\]\) \{([^}]*)\}/,
        );

        expect(css).toMatch(/\.box \.row \{[^}]*margin-inline: calc\(-1 \* var\(--solo-menu-inset, 0px\)\);/);
        expect(await read('../../../css/solo.css')).toContain('--solo-menu-inset: var(--solo-space-2);');
        expect(opening?.[1]).toContain('padding-top: var(--solo-space-2);');
        expect(css).not.toMatch(/\.box \{[^}]*padding: var\(--solo-space-4\);/);
    });

    test('the dropdown, the menu popover and the menu select all wear it, and no screen or primitive keeps a menu look of its own', async () => {
        for (const primitive of ['../ui/dropdown-menu/dropdown-menu.tsx', '../ui/popover/popover.tsx', '../ui/select/select.tsx']) {
            expect(await read(primitive)).toContain('menuBoxClass');
        }

        const root = new URL('../../', import.meta.url).pathname;
        for await (const file of new Glob('**/*.{tsx,css}').scan(root)) {
            const text = await Bun.file(root + file).text();
            expect(text).not.toContain('look="action"');
            expect(text).not.toMatch(/\.content\.action|\.soloMenu \.item \{|:global\(\[data-day\]\) \.menuItem/);
        }
    });
});

describe('a menu near the bottom of a phone', () => {
    test("keeps the caller's edge and adds the fixed bottom bars to the bottom one, so Radix flips it up instead of squeezing it", () => {
        expect({ ...menuCollisionPadding(16, () => '152px') }).toEqual({ top: 16, right: 16, bottom: 168, left: 16 });
        expect({ ...menuCollisionPadding(undefined, () => '88px') }).toEqual({ top: 0, right: 0, bottom: 88, left: 0 });
        expect({ ...menuCollisionPadding({ top: 8 }, () => '0px') }).toEqual({ top: 8, right: 0, bottom: 0, left: 0 });
        expect({ ...menuCollisionPadding(16, () => '') }).toEqual({ top: 16, right: 16, bottom: 16, left: 16 });
    });

    test('reads the bars when Radix copies the padding on open, not when the page first renders the closed menu', () => {
        let bars = '88px';
        const padding = menuCollisionPadding(16, () => bars);
        bars = '152px';

        // the copy Radix makes: const padding = { top: 0, right: 0, bottom: 0, left: 0, ...collisionPadding }
        const defaults: Record<string, number> = { top: 0, right: 0, bottom: 0, left: 0 };
        expect({ ...defaults, ...padding }.bottom).toBe(168);
    });

    test('the bars are a registered length on the root: the tab bar, the dock and the timer strip on a phone, none from 768 px', async () => {
        const css = await read('../../../css/solo.css');

        expect(css).toMatch(/@property --menu-floor \{\s*syntax: '<length>';/);
        expect(css).toMatch(/\n:root \{\s*--menu-floor: calc\(var\(--solo-tab-bar-clearance\) \+ var\(--shell-dock\) \+ var\(--shell-strip\)\);\s*\}/);
        expect(css).toMatch(/@media \(min-width: 768px\) \{\s*:root:root \{[^}]*--menu-floor: 0px;/);
    });

    test('the dropdown and the popover pass it to Radix, and the box keeps a 200 px floor', async () => {
        for (const primitive of ['../ui/dropdown-menu/dropdown-menu.tsx', '../ui/popover/popover.tsx']) {
            expect(await read(primitive)).toContain('collisionPadding={inSolo ? menuCollisionPadding(collisionPadding, readMenuFloor) : collisionPadding}');
        }
        expect(await read('./menu.module.css')).toMatch(/max-height: max\(var\(--radix-popover-content-available-height, var\(--radix-dropdown-menu-content-available-height, 100dvh\)\), 200px\);/);
    });
});
