import { describe, expect, test } from 'bun:test';

const read = (path: string) => Bun.file(new URL(path, import.meta.url)).text();

describe('one task row title', () => {
    test('every task row title is Switzer 500, 17 px on phones and 15 px from 768 px', async () => {
        const css = await read('./task-row.module.css');
        const [phone, desktop] = css.split('@media (min-width: 768px)');

        expect(phone).toMatch(/\n\.title \{[^}]*font: 500 var\(--solo-mobile-body\) \/ 1\.235 var\(--solo-font-text\);/);
        expect(desktop).toMatch(/\n {4}\.title \{\s*font: 500 var\(--solo-text-action\) \/ 1\.35 var\(--solo-font-text\);/);
    });

    test('no row kind sets a title size or weight of its own', async () => {
        const css = await read('./task-row.module.css');

        expect(css).not.toMatch(/\.(day|clientRow|compact|doneRow|queueRow) \.title \{[^}]*font/);
    });
});
