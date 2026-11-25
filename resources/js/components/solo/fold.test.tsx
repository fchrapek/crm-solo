import { describe, expect, test } from 'bun:test';
import { renderToStaticMarkup } from 'react-dom/server';
import { Fold } from './fold';

/** Files whose `aria-expanded` is not a fold: menus, popovers and pickers, and a clamp that shows four lines while closed. */
const NOT_A_FOLD = [
    'components/solo/date-field.tsx',
    'components/solo/month-card.tsx',
    'pages/tasks/components/task-page/description-section.tsx',
    'pages/today/add-or-pick.tsx',
    'pages/today/project-picker.tsx',
];

describe('Fold', () => {
    test('a closed fold keeps its content in the page, inert; an open one is reachable', () => {
        const fold = (open: boolean) =>
            renderToStaticMarkup(
                <Fold id="f" open={open}>
                    <p>x</p>
                </Fold>,
            );

        expect(fold(false)).toMatch(/<div id="f"[^>]*data-closed="true" inert=""><div[^>]*><p>x<\/p>/);
        expect(fold(true)).not.toMatch(/data-closed|inert/);
    });

    test('everything that opens in place goes through it', async () => {
        const root = new URL('../../', import.meta.url).pathname;
        const own: string[] = [];
        for await (const file of new Bun.Glob('**/*.tsx').scan(root)) {
            if (/\.(test|stories)\.tsx$/.test(file)) continue;
            const text = await Bun.file(root + file).text();
            if (text.includes('aria-expanded=') && !/from '[^']*\/fold'/.test(text)) own.push(file);
        }

        expect(own.sort()).toEqual(NOT_A_FOLD);
    });
});
