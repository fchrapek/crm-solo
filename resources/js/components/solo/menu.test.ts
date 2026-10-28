import { describe, expect, test } from 'bun:test';
import { Glob } from 'bun';

const read = (path: string) => Bun.file(new URL(path, import.meta.url)).text();

describe('one Solo menu look', () => {
    test('Menu / Actions is 300 px with 44 px rows on phones and 240 px with 34 px rows from 768 px, the chosen row red without a check', async () => {
        const css = await read('./menu.module.css');
        const [phone, desktop] = css.split('@media (min-width: 768px)');

        expect(phone).toMatch(/\.box \{[^}]*width: 300px;[^}]*padding: var\(--solo-space-4\);/);
        expect(phone).toMatch(/\.box \.row \{[^}]*min-height: var\(--solo-touch\);/);
        expect(phone).toMatch(/\[aria-pressed='true'\], \[data-state='checked'\]\):focus \{\s*color: var\(--solo-link, var\(--solo-accent\)\);/);
        expect(desktop).toMatch(/\.box \{\s*width: 240px;/);
        expect(desktop).toMatch(/\.box \.row \{\s*min-height: 34px;/);
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
