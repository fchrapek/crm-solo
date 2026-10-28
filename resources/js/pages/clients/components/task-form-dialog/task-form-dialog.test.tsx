import { afterAll, describe, expect, mock, test } from 'bun:test';
import i18next from 'i18next';
import type { ComponentProps, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../../lang/pl.json';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

// Radix portals render nothing on the server, so while these tests run the open content renders in place, and a button names its variant.
let wide = false;
let inline = true;
mock.module('@/components/solo/use-solo-wide', () => ({ useSoloWide: () => wide }));
const sheet = { ...(await import('@/components/ui/sheet')) };
const RealSheetContent = sheet.SheetContent;
mock.module('@/components/ui/sheet', () => ({
    ...sheet,
    SheetContent: (props: ComponentProps<typeof RealSheetContent>) =>
        inline ? <div data-test="sheet-content">{props.children as ReactNode}</div> : <RealSheetContent {...props} />,
}));
const dialog = { ...(await import('@/components/ui/dialog')) };
const RealDialogContent = dialog.DialogContent;
mock.module('@/components/ui/dialog', () => ({
    ...dialog,
    DialogContent: (props: ComponentProps<typeof RealDialogContent>) =>
        inline ? <div data-test="dialog-content">{props.children as ReactNode}</div> : <RealDialogContent {...props} />,
}));
const primitives = { ...(await import('@/components/solo/primitives')) };
const RealSoloButton = primitives.SoloButton;
mock.module('@/components/solo/primitives', () => ({
    ...primitives,
    SoloButton: (props: ComponentProps<typeof RealSoloButton>) => <RealSoloButton {...props} {...(inline ? { 'data-variant': props.variant } : {})} />,
}));

const { TaskFormDialog } = await import('./task-form-dialog');

afterAll(() => {
    wide = true;
    inline = false;
});

const render = (props: Partial<ComponentProps<typeof TaskFormDialog>> = {}) =>
    renderToStaticMarkup(
        <TaskFormDialog
            open
            onOpenChange={() => undefined}
            mode="create"
            projects={[
                { id: 3, name: 'Strona WWW' },
                { id: 4, name: 'Sklep' },
            ]}
            parentOptions={[{ id: 8, name: 'Cennik' }]}
            sheet
            {...props}
        />,
    );

describe('Nowe zadanie', () => {
    test('on a phone the sheet header holds the Anuluj pill, the title and the ink Zapisz, with no close button and no footer', () => {
        const html = render();

        expect(html).toMatch(
            /<div data-action="save"><button[^>]*data-variant="pill"[^>]*>Anuluj<\/button><h2[^>]*>Nowe zadanie<\/h2><button type="submit"[^>]*data-variant="ink"[^>]*>Zapisz<\/button><\/div>/,
        );
        const header = html.match(/<div data-action="save">.*?<\/div>/)?.[0] ?? '';
        expect(header).not.toContain('aria-label="Zamknij"');
        expect(html.match(/>Zapisz</g)).toHaveLength(1);
    });

    test('empty choices read Bez nadrzędnego and Bez priorytetu, and the due date is a date field', () => {
        const html = render();

        expect(html).toContain('id="task-parent-value">Bez nadrzędnego</span>');
        expect(html).toContain('id="task-priority-value">Bez priorytetu</span>');
        expect(html).not.toContain('>Brak<');
        expect(html).toMatch(/<input[^>]*type="date"[^>]*id="task-due"|<input[^>]*id="task-due"[^>]*type="date"/);
    });

    test('every field keeps its shared label: project, name, description in Markdown, parent, status, priority, due date, the report switch', () => {
        const html = render();

        for (const [id, label] of [
            ['task-project', 'Projekt'],
            ['task-name', 'Nazwa'],
            ['task-description', 'Opis'],
            ['task-parent', 'Zadanie nadrzędne'],
            ['task-status', 'Status'],
            ['task-priority', 'Priorytet'],
            ['task-due', 'Termin'],
        ]) {
            expect(html).toContain(`<label for="${id}" id="${id}-label">${label}</label>`);
        }
        expect(html).toContain('>Markdown</button>');
        expect(html).toContain('Pokaż w raporcie klienta');
    });

    test('from 768 px it is the dialog: the title, then Anuluj and Zapisz in the footer', () => {
        wide = true;
        const html = render();
        wide = false;

        expect(html).toContain('data-test="dialog-content"');
        expect(html).toMatch(/>Nowe zadanie<\/h2>/);
        expect(html).toMatch(/data-variant="pill"[^>]*>Anuluj<\/button><button type="submit"[^>]*data-variant="ink"[^>]*>Zapisz<\/button>/);
        expect(html).not.toContain('data-action="save"');
    });

    test('with one project there is nothing to pick', () => {
        expect(render({ projects: [{ id: 3, name: 'Strona WWW' }] })).not.toContain('task-project');
    });
});
