import { afterAll, describe, expect, mock, test } from 'bun:test';
import i18next from 'i18next';
import { type ComponentProps, createContext, type ReactNode, useContext } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../../lang/pl.json';
import type { BillingInvoice, BillingLedger, LedgerRow, RetainerBilling } from './types';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

// Radix portals render nothing on the server, so while these tests run the open content renders in place.
let wide = false;
let inline = true;
mock.module('@/components/solo/use-solo-wide', () => ({ useSoloWide: () => wide }));
const SheetOpen = createContext(false);
const sheet = { ...(await import('@/components/ui/sheet')) };
const RealSheet = sheet.Sheet;
const RealSheetContent = sheet.SheetContent;
mock.module('@/components/ui/sheet', () => ({
    ...sheet,
    Sheet: (props: ComponentProps<typeof RealSheet>) => (
        <SheetOpen.Provider value={props.open ?? false}>
            <RealSheet {...props} />
        </SheetOpen.Provider>
    ),
    SheetContent: function SheetContent(props: ComponentProps<typeof RealSheetContent>) {
        const open = useContext(SheetOpen);

        return !inline ? <RealSheetContent {...props} /> : open ? <div data-test="sheet-content">{props.children as ReactNode}</div> : null;
    },
}));
const dialog = { ...(await import('@/components/ui/dialog')) };
const RealDialogContent = dialog.DialogContent;
mock.module('@/components/ui/dialog', () => ({
    ...dialog,
    DialogContent: (props: ComponentProps<typeof RealDialogContent>) =>
        inline ? <div data-test="dialog-content">{props.children as ReactNode}</div> : <RealDialogContent {...props} />,
}));

const { PhoneInvoiceRows, PhoneInvoicesSection, PhoneRetainerBilling, ledgerStates, ledgerSub, ledgerValue, posterLabel, posterNote, posterRow } =
    await import('./phone-billing');
const { billingActionOrder } = await import('./billing-actions');
const { ClientBillingTab } = await import('./client-billing');
const { RetainerDialog } = await import('../retainer-chip/retainer-dialog');

afterAll(() => {
    wide = true;
    inline = false;
});

const t = i18next.t.bind(i18next);
const spaces = (text: string) => text.replace(/[\u00a0\u202f]/g, ' ');
const textOf = (html: string) => spaces(html.replace(/<[^>]+>/g, ''));

const row = (period: string, extra: Partial<LedgerRow> = {}): LedgerRow => {
    const [year, month] = period.split('-').map(Number);

    return {
        key: period,
        report_id: month,
        period,
        period_start: `${period}-01`,
        period_end: `${period}-${new Date(year, month, 0).getDate()}`,
        whole_month: true,
        ended: true,
        contracted: 30,
        used: 30,
        available: 30,
        opening: 0,
        forfeited: 0,
        closing: 0,
        change: 0,
        status: 'sent',
        sent_on: null,
        settlement: null,
        active: false,
        before_baseline: true,
        closed_on: null,
        closure_id: null,
        reopen_refusal: null,
        reopen_blocker: null,
        ...extra,
    };
};

/** Figma 334:3502: October open, September closed 05.10 with its draft, then the months before the baseline. */
const ledger: BillingLedger = {
    rows: [
        row('2026-10', {
            report_id: null,
            status: null,
            ended: false,
            used: 4.5,
            available: 18.5,
            opening: -11.5,
            closing: 14,
            change: 25.5,
            settlement: 'open',
            active: true,
            before_baseline: false,
        }),
        row('2026-09', {
            status: 'draft',
            used: 32,
            opening: -9.5,
            closing: -11.5,
            change: -2,
            settlement: 'closed',
            closed_on: '2026-10-05',
            closure_id: 3,
            before_baseline: false,
        }),
        row('2026-08', { used: 24, opening: -15.5, closing: -9.5, change: 6, sent_on: '2026-09-03' }),
        row('2026-07', { status: 'finalized', used: 30.5, opening: -15, closing: -15.5, change: -0.5 }),
    ],
    start: { date: '2026-06-02', balance: -21 },
    next: null,
};

const invoice = (number: string, sale: string, paid: string | null, extra: Partial<BillingInvoice> = {}): BillingInvoice => ({
    id: Number(number.split('/')[0]),
    number,
    status: paid !== null ? 'paid' : 'sent',
    currency: 'PLN',
    net: 1800,
    gross: 2214,
    invoice_date: sale,
    sale_date: sale,
    payment_date: null,
    paid_date: paid,
    overdue: false,
    external_url: null,
    ...extra,
});

const billing: RetainerBilling = {
    mode: 'retainer',
    cap: 60,
    settlement: { active_period: '2026-10', current_period: '2026-10', open_behind: false },
    opening_balance: null,
    waiting_report: { report_id: 9, period: '2026-09', status: 'draft' },
    retainer: {
        active: [{ id: 1, label: null, monthly_hours: 30, monthly_fee: 1800, rollover_cap_hours: 60, currency: 'PLN' }],
        positions: [],
        active_ids: [1],
        currency: 'PLN',
    },
    invoices: {
        rows: [
            invoice('15/09/2026', '2026-08-31', '2026-09-15', { external_url: 'https://app.infakt.test/15' }),
            invoice('4/08/2026', '2026-07-31', '2026-08-26'),
        ],
        more: 2,
    },
};

const phone = (bankOpen: boolean, data: RetainerBilling = billing, pages: BillingLedger = ledger) =>
    renderToStaticMarkup(
        <PhoneRetainerBilling
            clientId={7}
            billing={data}
            ledger={pages}
            locale="pl-PL"
            bankOpen={bankOpen}
            onBankOpenChange={() => undefined}
            onReopen={() => undefined}
        />,
    );

describe('the Rozliczenia poster on a phone', () => {
    test('a closed month with the next one open reads as the bank it left, with the closure and the open month in the note', () => {
        const poster = posterRow(ledger.rows)!;

        expect(poster.period).toBe('2026-09');
        expect(posterLabel(poster, t)).toBe('bank po wrześniu');
        expect(spaces(posterNote(ledger.rows, billing.settlement, 'pl-PL', t)!)).toBe('Wrzesień zamknięty 05.10 · październik 4,5 z 18,5 h');

        const text = textOf(phone(false));
        expect(text).toContain('−11,5 h');
        expect(text).toContain('bank po wrześniu');
        expect(phone(false)).toContain('data-tone="over"');
    });

    test('without a closure the newest month speaks: its bank today while it runs, the bank after it once it ended', () => {
        const running = [
            row('2026-10', { report_id: null, status: null, ended: false, used: 4, available: 30, closing: 26, settlement: 'open', active: true }),
        ];

        expect(posterRow(running)!.period).toBe('2026-10');
        expect(posterLabel(running[0], t)).toBe('bank dziś');
        expect(spaces(posterNote(running, billing.settlement, 'pl-PL', t)!)).toBe('Październik: 4,0 z 30,0 h');
        expect(posterLabel(row('2026-09'), t)).toBe('bank po wrześniu');
        expect(posterLabel(row('2026-06', { whole_month: false, period_start: '2026-06-16' }), t)).toBe('bank po czerwcu');
        expect(posterNote([row('2026-09')], billing.settlement, 'pl-PL', t)).toBeNull();

        const behind = [
            row('2026-09', { settlement: 'open', active: true, status: 'draft' }),
            row('2026-08', { settlement: 'open', status: 'draft' }),
        ];
        expect(posterNote(behind, { active_period: '2026-09', current_period: '2026-10', open_behind: true }, 'pl-PL', t)).toBe(
            'Jest już październik · wrzesień jeszcze otwarty',
        );
    });

    test('the report waiting to go out is a row with the late dot and Szkic · wyślij to that report, and the newest invoice opens the Faktury sheet', () => {
        const html = phone(false);
        const text = textOf(html);

        expect(text).toContain('Raport za wrzesień');
        expect(html).toContain('>czeka na wysłanie</span>');
        expect(html).toMatch(/<a[^>]*href="\/clients\/7\/reports\/9"[^>]*>Szkic · wyślij<\/a>/);
        expect(text).toContain('15/09/2026 · sierpień');
        expect(text).toContain('opłacona 15.09');
        expect(text).toContain('1 800,00 zł');
        expect(html).toMatch(/<button[^>]*aria-haspopup="dialog"[^>]*>/);
        expect(text).not.toContain('4/08/2026');
    });

    test('with nothing waiting the newest report is a row that opens it, and without a report the bank offers generating one', () => {
        const sent = phone(false, { ...billing, waiting_report: null });
        expect(sent).toMatch(/<a[^>]*href="\/clients\/7\/reports\/9"/);
        expect(textOf(sent)).toContain('szkic');

        const none = phone(false, { ...billing, waiting_report: null, invoices: { rows: [], more: 0 } }, { rows: [], start: null, next: null });
        expect(textOf(none)).toContain('Bank godzin zaczyna się od pierwszego raportu.');
        expect(none).toContain('href="/clients/7/edit#reports"');
        expect(textOf(none)).toContain('Nie ma jeszcze faktur z Infaktu.');
    });
});

describe('the Bank godzin sheet', () => {
    test('stays unmounted until it opens', () => {
        expect(phone(false)).not.toContain('Październik 2026');
    });

    test('lists the terms, every month with settlement and report on their own lines, the entry row and the footnote (334:3502)', () => {
        const html = phone(true);
        const text = textOf(html);

        expect(html).toContain('>Bank godzin</h2>');
        expect(text).toContain('30 h w pakiecie, rollover do 60 h1 800 zł netto / mc');
        expect(text).toContain('Październik 2026pakiet 30 h · zużyte 4,5 h·otwarty, od −11,5 h');
        expect(text).toContain('Wrzesieńpakiet 30 h · zużyte 32,0 h · zmiana −2,0−11,5 hzamknięty 05.10szkic raportuOtwórz ponownie');
        expect(text).toContain('Sierpieńpakiet 30 h · zużyte 24,0 h · zmiana +6,0−9,5 hwysłany 03.09');
        expect(text).toContain('Lipiecpakiet 30 h · zużyte 30,5 h · zmiana −0,5−15,5 hsfinalizowany');
        expect(text).toContain('Wejście 02.06 · start−21,0 h');
        expect(text).toContain('Bilans to co przechodzi na następny miesiąc. Rollover do 60 h.');
        expect(html).toMatch(/<a[^>]*href="\/clients\/7\/reports\/9"[^>]*>szkic raportu<\/a>/);
        expect(html.match(/data-tone="accent"/g)?.length).toBe(1);
        expect(html).toMatch(/data-tone="accent">−11,5\u00a0h</);
        expect(html.match(/>Otwórz ponownie</g)?.length).toBe(1);
    });

    test('a report link over Otwórz ponownie takes its 44 px upward, so the button keeps every tap on its own box', async () => {
        const css = await Bun.file(new URL('./client-billing.module.css', import.meta.url)).text();
        const rule = css.match(/\.reportLink:has\(\+ \.monthAction\)::before \{([^}]*)\}/)?.[1] ?? '';

        expect(css).toMatch(/\.monthBalance \{\s*--month-balance-gap: 3px;[^}]*gap: var\(--month-balance-gap\);/);
        expect(rule).toContain('top: auto;');
        expect(rule).toContain('bottom: calc(-1 * var(--month-balance-gap));');
        expect(css).not.toMatch(/\.monthAction \{[^}]*position/);
    });

    test('pages older months through Starsze inside the sheet, only the ledger reloading', () => {
        const html = phone(true, billing, { ...ledger, start: null, next: '2' });

        expect(html).toContain('href="/clients/7/rozliczenia?miesiace=2"');
        expect(textOf(html)).toContain('Starsze →');
        expect(textOf(phone(true))).not.toContain('Starsze');
    });

    test('row helpers: an open month without a report has no balance yet, a closed month names its report', () => {
        expect(ledgerValue(ledger.rows[0], 'pl-PL')).toBe('·');
        expect(ledgerSub(ledger.rows[0], 'pl-PL', t)).not.toContain('zmiana');
        expect(ledgerStates(ledger.rows[1], 'pl-PL', t)).toEqual({ settlement: 'zamknięty 05.10', report: 'szkic raportu' });
        expect(ledgerStates(ledger.rows[2], 'pl-PL', t)).toEqual({ settlement: null, report: 'wysłany 03.09' });
        expect(ledgerStates({ ...ledger.rows[1], status: 'sent', sent_on: '2026-10-06' }, 'pl-PL', t).report).toBe('raport wysłany 06.10');
    });
});

describe('the Faktury rows', () => {
    test('a retainer client reads net, an order client gross, and a linked invoice opens Infakt in a new tab', () => {
        const net = renderToStaticMarkup(<PhoneInvoiceRows clientId={7} invoices={billing.invoices} basis="net" locale="pl-PL" />);
        const gross = renderToStaticMarkup(<PhoneInvoiceRows clientId={7} invoices={billing.invoices} basis="gross" locale="pl-PL" />);

        expect(textOf(net)).toContain('15/09/2026 · sierpieńopłacona 15.091 800,00 zł');
        expect(textOf(gross)).toContain('15/09/2026 · sierpieńopłacona 15.092 214,00 zł');
        expect(net).toMatch(/<a[^>]*target="_blank" rel="noopener noreferrer" href="https:\/\/app\.infakt\.test\/15"/);
        expect(net.match(/<a /g)?.length).toBe(2);
        expect(textOf(net)).toContain('2 więcej →');
    });

    test("an order's list is headed Faktury with the count, the overdue state in accent (335:3956)", () => {
        const overdue = invoice('29/09/2026', '2026-09-29', null, { payment_date: '2026-10-14', overdue: true });
        const html = renderToStaticMarkup(<PhoneInvoicesSection clientId={7} invoices={{ rows: [overdue], more: 0 }} basis="gross" locale="pl-PL" />);

        expect(textOf(html)).toContain('Faktury · 1');
        expect(textOf(html)).toContain('2 214,00 zł');
        expect(html).toContain('data-tone="accent">po terminie od 14.10</span>');
        expect(renderToStaticMarkup(<PhoneInvoiceRows clientId={7} invoices={billing.invoices} basis="net" locale="pl-PL" />)).not.toContain(
            'data-tone="accent"',
        );
    });

    test("an order's unpaid invoice waits for payment in accent with its due day, a paid one reads its day and a draft reads szkic (335:3956)", () => {
        const rows = [
            invoice('29/09/2026', '2026-09-29', null, { payment_date: '2026-10-14' }),
            invoice('1/09/2026', '2026-09-01', '2026-09-10'),
            invoice('2/10/2026', '2026-10-02', null, { status: 'draft' }),
        ];
        const html = renderToStaticMarkup(<PhoneInvoicesSection clientId={7} invoices={{ rows, more: 0 }} basis="gross" locale="pl-PL" />);

        expect(html).toContain('data-tone="accent">czeka na wpłatę, termin 14.10</span>');
        expect(html).toContain('<span>opłacona 10.09</span>');
        expect(html).toContain('<span>szkic</span>');
        expect(html.match(/data-tone="accent"/g)?.length).toBe(1);
    });

    test('the retainer Faktury sheet keeps the desktop wording for the same invoices', () => {
        const unpaid = invoice('29/09/2026', '2026-09-29', null, { payment_date: '2026-10-14' });
        const draft = invoice('2/10/2026', '2026-10-02', null, { status: 'draft' });
        const text = textOf(
            renderToStaticMarkup(<PhoneInvoiceRows clientId={7} invoices={{ rows: [unpaid, draft], more: 0 }} basis="net" locale="pl-PL" />),
        );

        expect(text).toContain('do zapłaty do 14.10');
        expect(text).toContain('szkic w Infakcie');
        expect(text).not.toContain('czeka na wpłatę');
    });

    test('the order layout has no poster, no ledger and no Bank godzin on phones', () => {
        const html = renderToStaticMarkup(
            <ClientBillingTab
                clientId={7}
                clientName="Jan Nowak"
                billing={{
                    mode: 'order',
                    invoices: { rows: [invoice('29/09/2026', '2026-09-29', null)], more: 0 },
                    order: {
                        title: 'Audyt',
                        project: null,
                        started_on: null,
                        hours: 10,
                        month_hours: 3,
                        tasks: { done: 2, total: 4 },
                        state: 'in_progress',
                        invoice: null,
                    },
                }}
                ledger={undefined}
                dialog={null}
                onDialogChange={() => undefined}
            />,
        );
        const text = textOf(html);

        expect(text).toContain('Faktury · 1');
        expect(text).toContain('Zlecenie');
        expect(text).not.toMatch(/Bank godzin|bank po|Wystaw podobną|Nowa faktura/);
    });
});

describe('the Rozliczenia ••• menu', () => {
    test('offers Zmień abonament, and Zmień bilans otwarcia only while the rules let the opening change; never Nowa faktura', () => {
        const opening = { ...billing, opening_balance: { report_id: 9, value: -9.5, version: 'v1' } };

        expect(billingActionOrder(true, opening, ledger)).toEqual(['retainer', 'opening']);
        expect(billingActionOrder(true, billing, ledger)).toEqual(['retainer']);
        expect(billingActionOrder(true, { ...opening, opening_balance: { report_id: 99, value: 0, version: 'v' } }, ledger)).toEqual(['retainer']);
        expect(billingActionOrder(false, opening, ledger)).toEqual([]);
    });
});

describe('Zmień abonament on a phone', () => {
    const dialogFor = (asSheet: boolean) =>
        renderToStaticMarkup(
            <RetainerDialog open onOpenChange={() => undefined} clientId={7} clientCurrency="PLN" retainers={[]} activeIds={[]} sheet={asSheet} />,
        );

    test('opens as a bottom sheet on the record, with Anuluj and the submit in the footer', () => {
        const html = dialogFor(true);

        expect(html).toContain('data-test="sheet-content"');
        expect(html).not.toContain('data-test="dialog-content"');
        expect(html).toContain('>Dodaj abonament</h2>');
        expect(html).toMatch(/>Anuluj<\/button><button type="submit"[^>]*>Dodaj pozycję<\/button>/);
    });

    test('stays the dialog where the old page opens it', () => {
        expect(dialogFor(false)).toContain('data-test="dialog-content"');
        expect(dialogFor(false)).not.toContain('data-test="sheet-content"');
    });
});
