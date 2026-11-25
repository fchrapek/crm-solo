import { describe, expect, test } from 'bun:test';

const read = (path: string) => Bun.file(new URL(path, import.meta.url)).text();
const rule = (css: string, selector: string) =>
    [...css.matchAll(new RegExp(`(?:^|\\n)${selector.replace(/[[\]().]/g, '\\$&')} \\{([^}]*)\\}`, 'g'))].map((match) => match[1]).join('\n');

describe('one dialog and sheet frame on Solo screens', () => {
    test('the footer is a tone band to the dialog edges with no rule, and Cancel is the quiet button', async () => {
        const footer = rule(await read('./dialog.module.css'), ':global([data-day]) .footer');

        expect(footer).toContain('background-color: var(--solo-field-bg);');
        expect(footer).toMatch(/margin: .+ calc\(-1 \* var\(--dialog-pad-inline\)\) calc\(-1 \* var\(--dialog-pad-bottom\)\);/);
        expect(footer).toContain('--solo-pill-edge: transparent;');
        expect(footer).not.toContain('border-top');
    });

    test("a confirm's footer has the same band and quiet Cancel", async () => {
        const footer = rule(await read('../alert-dialog/alert-dialog.module.css'), ':global([data-day]) .footer');

        expect(footer).toContain('background-color: var(--solo-field-bg);');
        expect(footer).toContain('--solo-pill-edge: transparent;');
        expect(footer).not.toContain('border-top');
    });

    test('a dialog and a confirm have no edge in either appearance', async () => {
        expect(rule(await read('./dialog.module.css'), ':global([data-day]) .content')).toContain('border: 0;');
        expect(rule(await read('../alert-dialog/alert-dialog.module.css'), ':global([data-day]) .content')).toContain('border: 0;');
        expect(await read('../sheet/sheet.module.css')).not.toContain(":global(.dark [data-day='paper']) .bottom {");
    });

    test('the close × keeps its corner and gets a 44 px hit area', async () => {
        const close = rule(await read('./dialog.module.css'), ':global([data-day]) .closeButton');

        expect(close).toContain('--dialog-close-target: 44px;');
        expect(close).toMatch(/width: var\(--dialog-close-target\);\s*height: var\(--dialog-close-target\);/);
    });

    test("a sheet's header has no rule under it and its actions sit on the tone band", async () => {
        const css = await read('../../solo/mobile.module.css');

        expect(rule(css, '.sheetHeader')).not.toContain('border-bottom');
        expect(rule(css, '.sheetFoot')).not.toContain('border-top');
        expect(rule(css, '.sheetFoot')).toContain('background-color: var(--solo-field-bg);');
    });

    test('the dialog body and the sheet body keep a stable gutter with the 4 px themed scrollbar, in the side padding', async () => {
        const solo = await read('../../../../css/solo.css');

        // a dialog without DialogForm (the older ones) scrolls in its outer container, which gets the same gutter unless its footer band sits in it
        expect(solo).toContain(
            "[data-day] :is([data-slot='dialog-body'], [data-slot='sheet-body'], [data-slot='dialog-scroll']:not(:has(> [data-slot='dialog-form'], [data-slot='dialog-footer']))) {\n    scrollbar-gutter: stable;",
        );
        expect(await read('./dialog.tsx')).toContain('<div className={styles.scroll} data-slot="dialog-scroll">');
        expect(solo).toMatch(/::-webkit-scrollbar \{\s*width: 4px;/);
        expect(solo).toMatch(/scrollbar-color: var\(--role-rule\) transparent;/);
        expect(rule(await read('./dialog.module.css'), ':global([data-day]) .body')).toContain('margin-right: calc(-1 * var(--solo-space-4));');
        expect(rule(await read('../../solo/mobile.module.css'), '.sheetBody')).toContain('margin-right: calc(-1 * var(--solo-space-3));');
    });
});
