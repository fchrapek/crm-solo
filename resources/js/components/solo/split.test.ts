import { describe, expect, test } from 'bun:test';

const read = (path: string) => Bun.file(new URL(path, import.meta.url)).text();

const VIEWS = [
    [
        '../../pages/clients/components/client-overview/client-overview.module.css',
        '../../pages/clients/components/client-overview/client-overview.tsx',
    ],
    ['../../pages/clients/components/client-journal/client-journal.module.css', '../../pages/clients/components/client-journal/client-journal.tsx'],
    ['../../pages/clients/components/client-billing/client-billing.module.css', '../../pages/clients/components/client-billing/client-billing.tsx'],
    ['../../pages/tasks/components/task-page/task-page.module.css', '../../pages/tasks/show.tsx'],
    ['../../pages/clients/components/client-tasks/client-tasks.module.css', '../../pages/clients/components/client-tasks/client-tasks.tsx'],
    ['../../pages/today/today.module.css', '../../pages/today/index.tsx'],
    ['../../pages/today/today.module.css', '../../pages/today/plan.tsx'],
    ['../../pages/today/today.module.css', '../../pages/today/tasks.tsx'],
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

    test("an order client's overview side column is a split side too", async () => {
        expect(await read('../../pages/clients/components/client-overview/order-section.tsx')).toContain('splitSideClass');
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
