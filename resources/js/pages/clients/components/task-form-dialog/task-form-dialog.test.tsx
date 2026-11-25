import { afterAll, describe, expect, mock, spyOn, test } from 'bun:test';
import i18next from 'i18next';
import type { ComponentProps, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import * as primitives from '@/components/solo/primitives';
import * as soloWide from '@/components/solo/use-solo-wide';
import * as dialog from '@/components/ui/dialog';
import * as sheet from '@/components/ui/sheet';
import pl from '../../../../../../lang/pl.json';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

// Radix portals render nothing on the server, so while these tests run the open content renders in place, and a button names its variant.
let wide = false;
spyOn(soloWide, 'useSoloWide').mockImplementation(() => wide);
spyOn(sheet, 'SheetContent').mockImplementation((props) => <div data-test="sheet-content">{props.children as ReactNode}</div>);
spyOn(dialog, 'DialogContent').mockImplementation((props) => <div data-test="dialog-content">{props.children as ReactNode}</div>);
const RealSoloButton = primitives.SoloButton;
spyOn(primitives, 'SoloButton').mockImplementation((props) => <RealSoloButton {...props} data-variant={props.variant} />);

const { TaskFormDialog } = await import('./task-form-dialog');

afterAll(() => mock.restore());

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
    test('on a phone the sheet header holds the Cancel pill, the title and the ink Save, with no close button and no footer', () => {
        const html = render();

        expect(html).toMatch(
            /<div data-action="save"><button[^>]*data-variant="pill"[^>]*>Anuluj<\/button><h2[^>]*>Nowe zadanie<\/h2><button type="submit"[^>]*data-variant="ink"[^>]*>Zapisz<\/button><\/div>/,
        );
        const header = html.match(/<div data-action="save">.*?<\/div>/)?.[0] ?? '';
        expect(header).not.toContain('aria-label="Zamknij"');
        expect(html.match(/>Zapisz</g)).toHaveLength(1);
    });

    test('empty choices read No parent and No priority set, and the due date opens the calendar', () => {
        const html = render();

        expect(html).toContain('id="task-parent-value" title="Bez nadrzędnego">Bez nadrzędnego</span>');
        expect(html).toContain('id="task-priority-value" title="Bez priorytetu">Bez priorytetu</span>');
        expect(html).not.toContain('>Brak<');
        expect(html).toMatch(/<button[^>]*id="task-due"[^>]*>Wybierz datę<\/button>/);
        expect(html).not.toContain('type="date"');
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

    test('from 768 px it is the dialog: the title, then Cancel and Save in the footer', () => {
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
