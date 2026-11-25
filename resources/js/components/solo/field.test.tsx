import { describe, expect, test } from 'bun:test';
import { renderToStaticMarkup } from 'react-dom/server';

import { FieldError, fieldControl, SoloField, SoloInput } from './field';

describe('a field error outside SoloField', () => {
    test('renders the line the control points at, and nothing without an error', () => {
        const html = renderToStaticMarkup(<FieldError htmlFor="task-reportable" error="Otwórz raport ponownie." />);

        expect(html).toContain('id="task-reportable-error"');
        expect(html).toContain('Otwórz raport ponownie.');
        expect(fieldControl('task-reportable', 'x')['aria-describedby']).toBe('task-reportable-error');
        expect(renderToStaticMarkup(<FieldError htmlFor="task-reportable" />)).toBe('');
    });
});

describe('a field hint', () => {
    test('stands under the control and the control is described by it, and by the error first when there is one', () => {
        const html = renderToStaticMarkup(
            <SoloField label="Nazwa" htmlFor="client-name" hint="Wymagana.">
                <SoloInput {...fieldControl('client-name', undefined, true)} />
            </SoloField>,
        );

        expect(html).toMatch(/<input[^>]*aria-describedby="client-name-hint"[^>]*\/><p id="client-name-hint"[^>]*>Wymagana\.<\/p>/);
        expect(fieldControl('client-name', 'Pole wymagane', true)['aria-describedby']).toBe('client-name-error client-name-hint');
        expect(fieldControl('client-name')['aria-describedby']).toBeUndefined();
    });
});

describe('the field look: filled on tone', () => {
    const read = (path: string) => Bun.file(new URL(path, import.meta.url)).text();
    const rule = (css: string, selector: string) =>
        [...css.matchAll(new RegExp(`(?:^|\\n)${selector.replace(/[[\]().:'=]/g, '\\$&')} \\{([^}]*)\\}`, 'g'))].map((match) => match[1]).join('\n');

    test('a field is the page tone with no edge at rest, and focus and error are 2 px rings inside it', async () => {
        const solo = await read('../../../css/solo.css');
        const field = await read('./field.module.css');

        expect(rule(solo, '[data-day]')).toContain('--solo-field-bg: var(--role-page);');
        expect(solo).not.toContain('--solo-field-border');
        expect(rule(field, '.box')).toContain('border: 1px solid transparent;');
        expect(rule(field, '.box:focus-visible')).toContain('box-shadow: inset 0 0 0 1px var(--role-focus-ring);');
        expect(rule(field, ".box[aria-invalid='true']")).toContain('box-shadow: inset 0 0 0 1px var(--role-text-primary);');
        expect(rule(field, '.box:hover:not(:disabled, :focus-visible)')).toContain('color-mix(in srgb, var(--solo-field-fg) 6%, var(--solo-field-bg))');
    });

    test('a select field has the same rings, and its error is ink, never vermilion', async () => {
        const select = await read('../ui/select/select.module.css');

        expect(rule(select, '.soloTrigger')).toContain('border: 1px solid transparent;');
        expect(rule(select, '.soloTrigger:focus-visible')).toContain('box-shadow: inset 0 0 0 1px var(--role-focus-ring);');
        expect(select).not.toContain('var(--solo-late)');
    });
});
