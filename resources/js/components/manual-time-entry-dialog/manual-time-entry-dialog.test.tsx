import { afterAll, describe, expect, mock, test } from 'bun:test';
import i18next from 'i18next';
import type { ComponentProps, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

// Radix portals render nothing on the server, so while these tests run the open content renders in place.
let wide = true;
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

const { ManualTimeEntryDialog, RunningWarning } = await import('./manual-time-entry-dialog');

afterAll(() => {
    wide = true;
    inline = false;
});

const task = { id: 36, name: 'Aktualizacja cennika PDF', client: 'Pracownia Mebli' };
const render = (props: Partial<ComponentProps<typeof ManualTimeEntryDialog>> = {}) =>
    renderToStaticMarkup(<ManualTimeEntryDialog open onOpenChange={() => undefined} task={task} {...props} />);

describe('Zaloguj czas', () => {
    test('the subtitle under the title names the task and the client', () => {
        const html = render();

        expect(html).toMatch(/>Zaloguj czas<\/h2><p[^>]*>Aktualizacja cennika PDF · Pracownia Mebli<\/p>/);
    });

    test('a task without a client is named alone', () => {
        expect(render({ task: { id: 36, name: 'Porządki', client: null } })).toMatch(/<p[^>]*>Porządki<\/p>/);
    });

    test('while the running timers are checked it says so quietly, with only Anuluj to press', () => {
        const html = render();

        expect(html).toContain('role="status">Sprawdzam aktywne sesje…</p>');
        expect(html).toContain('>Anuluj</button>');
        expect(html).not.toContain('type="submit"');
    });

    test('the running-timer warning lists each entry as its title and od HH:MM, without its source', () => {
        const startedAt = new Date(2026, 9, 23, 9, 14).toISOString();
        const html = renderToStaticMarkup(
            <RunningWarning
                running={[
                    { id: 1, description: null, start_time: startedAt, source: 'terminal_session', task: { id: 5, name: 'Korekta faktury 14/09: dane odbiorcy' } },
                    { id: 2, description: 'Telefon do klienta', start_time: null, source: 'manual', task: null },
                ]}
            />,
        );

        expect(html).toContain('Wpis czasu jest obecnie aktywny. Dodanie ręcznego wpisu może się z nim pokrywać:');
        expect(html).toMatch(/<li[^>]*><span[^>]*>Korekta faktury 14\/09: dane odbiorcy<\/span><span[^>]*>od 09:14<\/span><\/li>/);
        expect(html).toMatch(/<li[^>]*><span[^>]*>Telefon do klienta<\/span><\/li>/);
        expect(html).not.toContain('Sesja');
    });
});
