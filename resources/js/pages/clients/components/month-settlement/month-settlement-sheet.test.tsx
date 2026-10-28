import { afterAll, describe, expect, mock, test } from 'bun:test';
import i18next from 'i18next';
import type { ComponentProps, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../../lang/pl.json';

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

const { CloseMonthDialog } = await import('./close-month-dialog');
const { ReopenMonthDialog } = await import('./reopen-month-dialog');

afterAll(() => {
    wide = true;
    inline = false;
});

const close = () => renderToStaticMarkup(<CloseMonthDialog clientId={7} period="2026-09" open onOpenChange={() => undefined} />);
const reopen = (refusal: string | null = null, blocker: { report_id: number; period: string } | null = null) =>
    renderToStaticMarkup(
        <ReopenMonthDialog
            clientId={7}
            clientName="Pracownia Mebli Przykładowa"
            target={{ period: '2026-09', closure_id: 3, used: 32, closing: -11.5, refusal, blocker }}
            open
            onOpenChange={() => undefined}
        />,
    );
const buttons = (html: string) => [...html.matchAll(/<button([^>]*)>([^<]*)<\/button>/g)].map(([, attrs, label]) => ({ attrs, label }));

describe('the close and reopen dialogs on a phone', () => {
    test('Zamknij miesiąc opens as a bottom sheet: one form, the close button in the header, Anuluj then the submit in its footer', () => {
        wide = false;
        const html = close();
        expect(html).toContain('data-test="sheet-content"');
        expect(html).not.toContain('data-test="dialog-content"');
        expect(html).toMatch(/^<div[^>]*><form noValidate="">/);
        expect(html).toContain('>Zamknij wrzesień</h2>');
        expect(html).toContain('aria-label="Zamknij"');
        const actions = buttons(html).filter(({ label }) => label === 'Anuluj' || label === 'Zamknij miesiąc');
        expect(actions.map(({ label }) => label)).toEqual(['Anuluj', 'Zamknij miesiąc']);
        expect(actions[1].attrs).toContain('type="submit"');
        expect(actions[1].attrs).toContain('disabled=""');
        expect(html.indexOf('</form>')).toBeGreaterThan(html.indexOf('Zamknij miesiąc'));
    });

    test('Otwórz ponownie opens as a bottom sheet with the same actions (Figma 347:4931)', () => {
        wide = false;
        const html = reopen();
        expect(html).toContain('data-test="sheet-content"');
        expect(html).toContain('>Otworzyć wrzesień ponownie?</h2>');
        expect(html).toContain('aria-label="Zamknij"');
        expect(html).toContain('Zamrożone liczby (32,0 h, bilans −11,5 h)');
        expect(buttons(html).map(({ label }) => label)).toEqual(['Anuluj', 'Otwórz ponownie']);
    });

    test('a later report already out opens the blocked sheet instead: its refusal, the month kept, Anuluj and that report (347:4982)', () => {
        wide = false;
        const refusal = 'Raport za październik jest już sfinalizowany lub wysłany; najpierw go otwórz.';
        const html = reopen(refusal, { report_id: 41, period: '2026-10' });

        expect(html).toContain('>Nie można otworzyć września</h2>');
        expect(html).toContain(`role="alert">${refusal}</p>`);
        expect(html).toContain('Wrzesień zostaje zamknięty, liczby się nie zmieniają.');
        expect(html).not.toContain('Otwórz ponownie');
        expect(buttons(html).map(({ label }) => label)).toEqual(['Anuluj']);
        // The footer keeps Anuluj first and the report link last, so the stacked layout puts the link on top at 320 px.
        expect(html).toMatch(/>Anuluj<\/button><a[^>]*href="\/clients\/7\/reports\/41"[^>]*>Raport za październik<\/a>/);

        const unnamed = reopen(refusal, null);
        expect(unnamed).toContain('>Nie można otworzyć września</h2>');
        expect(unnamed).not.toContain('Raport za październik</a>');
    });

    test('from 768 px a refused reopen stays the confirm dialog with its submit off', () => {
        wide = true;
        const html = reopen('Raport za październik jest już gotowy.', { report_id: 41, period: '2026-10' });

        expect(html).toContain('>Otworzyć wrzesień ponownie?</h2>');
        expect(html).toContain('role="alert"');
        expect(buttons(html).find(({ label }) => label === 'Otwórz ponownie')?.attrs).toContain('disabled=""');
        expect(html).not.toContain('Nie można otworzyć');
    });

    test('from 768 px both stay the themed dialog with its header and footer', () => {
        wide = true;
        for (const html of [close(), reopen()]) {
            expect(html).toContain('data-test="dialog-content"');
            expect(html).not.toContain('data-test="sheet-content"');
            expect(html).not.toContain('data-action=');
        }
        expect(close()).toContain('Zamknij wrzesień');
    });
});
