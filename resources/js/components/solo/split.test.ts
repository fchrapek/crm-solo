import { describe, expect, test } from 'bun:test';

const read = (path: string) => Bun.file(new URL(path, import.meta.url)).text();

/** Views rebuilt on the redesign's tiles stand on TileGrid instead of the split. */
const REBUILT = [
    [
        '../../pages/clients/components/client-overview/client-overview.module.css',
        '../../pages/clients/components/client-overview/client-overview.tsx',
    ],
    ['../../pages/clients/components/client-tasks/client-tasks.module.css', '../../pages/clients/components/client-tasks/client-tasks.tsx'],
    ['../../pages/clients/components/client-journal/client-journal.module.css', '../../pages/clients/components/client-journal/client-journal.tsx'],
    ['../../pages/clients/components/client-billing/client-billing.module.css', '../../pages/clients/components/client-billing/client-billing.tsx'],
    ['../../pages/tasks/components/task-page/task-page.module.css', '../../pages/tasks/show.tsx'],
    ['../../pages/today/day.module.css', '../../pages/today/day.tsx'],
    ['../../pages/today/day.module.css', '../../pages/today/summary.tsx'],
    ['../../pages/today/day.module.css', '../../pages/today/plan.tsx'],
    ['../../pages/today/day.module.css', '../../pages/today/week.tsx'],
] as const;

const VIEWS = [
    ['../../pages/today/today.module.css', '../../pages/today/index.tsx'],
] as const;

describe('split columns', () => {
    test('the record split is 760 + 380 with 60 between, the 1200 px frame', async () => {
        const [tokens, css] = await Promise.all([read('../../../css/solo.css'), read('./split.module.css')]);

        expect(tokens).toContain('--solo-split-main: 760px;');
        expect(tokens).toContain('--solo-split-side: 380px;');
        expect(tokens).toContain('--solo-split-gap: 60px;');
        expect(css).toContain('gap: var(--solo-space-7) var(--solo-split-gap);');
    });

    test('the main and side columns grow to their Figma widths and wrap only when 480 and 300 no longer fit', async () => {
        const css = await read('./split.module.css');

        expect(css).toMatch(/\.main \{[^}]*flex: 1 1 480px;[^}]*max-width: var\(--solo-split-main\);/);
        expect(css).toMatch(/\.side \{[^}]*flex: 1 1 300px;[^}]*max-width: var\(--solo-split-side\);/);
        expect(css).toContain('flex-flow: row wrap;');
    });

    test('a rebuilt view stands on the shared tile grid and sizes no column of its own', async () => {
        const grid = await read('./tile.module.css');

        expect(grid).toMatch(/@media \(min-width: 1100px\) \{\s*\.grid \{\s*grid-template-columns: minmax\(0, 1fr\) 380px;/);
        for (const [cssPath, tsxPath] of REBUILT) {
            const [css, tsx] = await Promise.all([read(cssPath), read(tsxPath)]);

            expect(tsx).toContain('<TileGrid');
            expect(tsx).not.toContain('splitClass');
            expect(css).not.toMatch(/grid-template-columns|max-width: 38[04]px|flex: 0 1 \d+px/);
        }
    });

    test('every two-column view uses the shared split and sizes no column of its own', async () => {
        for (const [cssPath, tsxPath] of VIEWS) {
            const [css, tsx] = await Promise.all([read(cssPath), read(tsxPath)]);

            expect(tsx).toContain('splitClass');
            expect(css).not.toMatch(
                /flex: 0 1 (690|760|792|282|380|384)px|max-width: 38[04]px|grid-template-areas|--solo-split-(main|side|gap):|repeat\(12/,
            );
        }
    });
});
