import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../../lang/pl.json';
import { hoursNumber } from '../client-list/format';
import { ClientBillingTab } from './client-billing';
import { moneyOf, periodLabel } from './format';
import type { BillingInvoice, BillingLedger, LedgerRow, OrderBilling, RetainerBilling } from './types';

type Fixture = RetainerBilling & { ledger: BillingLedger };

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const row = (period: string, status: LedgerRow['status'], used: number, opening: number, sentOn: string | null = null): LedgerRow => {
    const closing = opening + 30 - used;
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
        used,
        opening,
        forfeited: 0,
        closing,
        change: closing - opening,
        available: opening + 30,
        status,
        sent_on: sentOn,
        settlement: null,
        active: false,
        before_baseline: true,
        closed_on: null,
        closure_id: null,
        reopen_refusal: null,
        reopen_blocker: null,
    };
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

const frame: Fixture = {
    mode: 'retainer',
    ledger: {
        rows: [
            row('2026-09', 'draft', 32, -9.5),
            row('2026-08', 'sent', 24, -15.5, '2026-09-03'),
            row('2026-07', 'finalized', 30.5, -15),
            row('2026-06', 'finalized', 24, -21),
        ],
        start: { date: '2026-06-02', balance: -21 },
        next: null,
    },
    cap: 60,
    settlement: { active_period: '2026-10', current_period: '2026-10', open_behind: false },
    opening_balance: { report_id: 9, value: -9.5, version: 'v1' },
    waiting_report: { report_id: 9, period: '2026-09', status: 'draft' },
    retainer: {
        active: [{ id: 1, label: null, monthly_hours: 30, monthly_fee: 1800, rollover_cap_hours: 60, currency: 'PLN' }],
        positions: [],
        active_ids: [1],
        currency: 'PLN',
    },
    invoices: {
        rows: [
            invoice('15/09/2026', '2026-08-31', '2026-09-15'),
            invoice('4/08/2026', '2026-07-31', '2026-08-26'),
            invoice('13/07/2026', '2026-06-30', '2026-07-22'),
        ],
        more: 0,
    },
};

const empty: Fixture = {
    mode: 'retainer',
    ledger: { rows: [], start: null, next: null },
    cap: null,
    settlement: { active_period: '2026-10', current_period: '2026-10', open_behind: false },
    opening_balance: null,
    waiting_report: null,
    retainer: { active: [], positions: [], active_ids: [], currency: 'PLN' },
    invoices: { rows: [], more: 0 },
};

const render = (billing: Fixture | OrderBilling) =>
    renderToStaticMarkup(
        <ClientBillingTab
            clientId={7}
            clientName="Pracownia Mebli Przykładowa"
            billing={billing}
            ledger={'ledger' in billing ? billing.ledger : undefined}
            dialog={null}
            onDialogChange={() => undefined}
        />,
    );
const spaces = (text: string) => text.replace(/[\u00a0\u202f]/g, ' ');
const textOf = (html: string) => spaces(html.replace(/<[^>]+>/g, ''));

describe('ClientBillingTab', () => {
    test('the Figma state reads as the frame does', () => {
        const html = render(frame);
        const text = textOf(html);

        expect(text).toContain(
            'Wrzesień skończył się na 32,0 z 30 h, bank po miesiącu to −11,5 h. Raport za wrzesień czeka na wysłanie, faktura za sierpień opłacona 15.09.',
        );
        expect(text).toContain('Zmień bilans otwarcia');
        expect(text).toContain('Wrzesień 2026');
        expect(text).toContain('Sierpień');
        expect(text).not.toContain('Sierpień 2026');
        expect(text).toContain('Szkic · wyślij');
        expect(html).toContain('href="/clients/7/reports/9"');
        expect(text).toContain('wysłany 03.09');
        expect(text).toContain('sfinalizowany');
        expect(text).not.toContain('zamknięty');
        expect(text).toContain('Wejście 02.06');
        expect(text).toContain('−21,0 h');
        expect(text).toContain('Bilans to co przechodzi na następny miesiąc. Rollover do 60 h.');
        expect(text).toContain('30 h w pakiecie, rollover do 60 h');
        expect(text).toContain('1 800 zł netto / mc');
        expect(text).toContain('15/09/2026 · sierpień');
        expect(text).toContain('1 800,00 zł');
        expect(text).toContain('opłacona 15.09');
    });

    test('the invoice actions of the separate track are not offered', () => {
        const text = textOf(render(frame));

        expect(text).not.toContain('Wystaw podobną');
        expect(text).not.toContain('Nowa faktura');
    });

    test('no figure for overage cost is ever shown', () => {
        const text = textOf(render(frame));

        expect(text).not.toMatch(/nadgodzin|koszt/i);
    });

    test('without a report the bank offers generating one instead of an opening balance', () => {
        const html = render(empty);
        const text = textOf(html);

        expect(text).toContain('Bank godzin zaczyna się od pierwszego raportu.');
        expect(html).toContain('href="/clients/7/edit#reports"');
        expect(text).not.toContain('Zmień bilans otwarcia');
        expect(text).toContain('Nie ma jeszcze raportów ani faktur.');
        expect(text).toContain('Bez abonamentu');
        expect(text).toContain('Dodaj');
        expect(text).toContain('Nie ma jeszcze faktur z Infaktu.');
    });

    test('an overdue invoice is flagged and a sent report is not waiting', () => {
        const billing: Fixture = {
            ...frame,
            ledger: { ...frame.ledger, rows: [row('2026-09', 'sent', 20, 0, '2026-10-02')] },
            waiting_report: null,
            invoices: { rows: [invoice('1/09/2026', '2026-09-30', null, { payment_date: '2026-10-01', overdue: true })], more: 3 },
        };
        const html = render(billing);
        const text = textOf(html);

        expect(text).toContain('bank po miesiącu to +10,0 h. Raport za wrzesień wysłany 02.10, faktura za wrzesień po terminie od 01.10.');
        expect(text).toContain('3 więcej →');
        expect(html).toContain('href="/clients/7/edit?invoice_year=all#invoices"');
    });

    test('an opening that does not continue the month before shows as a correction row', () => {
        const corrected = { ...row('2026-09', 'draft', 32, -5) };
        const html = render({ ...frame, ledger: { ...frame.ledger, rows: [corrected, ...frame.ledger.rows.slice(1)] } });
        const text = textOf(html);

        expect(text).toContain('Korekta 01.09+4,5−5,0 h');
        expect(text.indexOf('Korekta 01.09')).toBeGreaterThan(text.indexOf('Wrzesień 2026'));
        expect(text.indexOf('Korekta 01.09')).toBeLessThan(text.indexOf('Sierpień'));
        expect(textOf(render(frame))).not.toContain('Korekta');
    });

    test('an older page of months loads in place through Starsze, only the ledger reloading', () => {
        const html = render({ ...frame, ledger: { ...frame.ledger, start: null, next: '2' } });

        expect(textOf(html)).toContain('Starsze →');
        expect(html).toContain('href="/clients/7/rozliczenia?miesiace=2"');
        expect(html).not.toContain('#reports');
        expect(textOf(html)).not.toContain('Wejście');
        expect(textOf(render(frame))).not.toContain('Starsze');
    });
});

describe('retainer terms', () => {
    test('package and rollover read as entered while worked hours and balances keep a decimal', () => {
        const text = textOf(render(frame));

        expect(text).toContain('Wrzesień 202630 h32,0 h−2,0−11,5 h');
        expect(text).toContain('Rollover do 60 h.');

        const fractional = {
            ...frame,
            retainer: { ...frame.retainer, active: [{ ...frame.retainer.active[0], monthly_hours: 7.5, rollover_cap_hours: 15 }] },
        };
        expect(textOf(render(fractional))).toContain('7,5 h w pakiecie, rollover do 15 h');
    });
});

describe('billing formats', () => {
    test('hours keep the precision the report holds', () => {
        expect(hoursNumber(30, 'pl-PL')).toBe('30,0');
        expect(hoursNumber(9.45, 'pl-PL')).toBe('9,45');
        expect(hoursNumber(-0.25, 'pl-PL', true)).toBe('−0,25');
        expect(hoursNumber(-16, 'pl-PL', true)).toBe('−16,0');
        expect(hoursNumber(6, 'pl-PL', true)).toBe('+6,0');
        expect(hoursNumber(0, 'pl-PL', true)).toBe('0,0');
    });

    test('money groups thousands the way the frame does', () => {
        expect(spaces(moneyOf(1800, 'PLN', 'pl-PL', true))).toBe('1 800,00 zł');
        expect(spaces(moneyOf(1800, 'PLN', 'pl-PL', false))).toBe('1 800 zł');
    });

    test('a partial period reads as its dates', () => {
        expect(periodLabel({ ...row('2026-06', 'sent', 3, 0), period_start: '2026-06-16', whole_month: false }, undefined, 'pl-PL')).toBe(
            '16.06–30.06',
        );
    });
});

describe('Rozliczenia for a client in the order layout', () => {
    const order: OrderBilling['order'] = {
        title: 'Audyt energetyczny domu',
        project: null,
        started_on: '2026-09-15',
        hours: 10,
        month_hours: 3.5,
        tasks: { done: 2, total: 4 },
        state: 'in_progress',
        invoice: {
            id: 1,
            number: '29/09/2026',
            state: 'unpaid',
            currency: 'PLN',
            gross: 2400,
            net: 1951.22,
            invoice_date: '2026-09-29',
            payment_date: '2026-10-14',
            paid_date: null,
            overdue: false,
        },
    };
    const oneOff: OrderBilling = { mode: 'order', invoices: { rows: [invoice('29/09/2026', '2026-09-29', null)], more: 0 }, order };

    test('the brief, the order block and the invoices stand where the bank and the retainer were', () => {
        const html = render(oneOff);
        const text = textOf(html);

        expect(text).toContain('Audyt energetyczny domu za 2 400 zł brutto. Faktura 29/09/2026 czeka na wpłatę do 14.10, zlecenie w trakcie.');
        expect(html).toContain('<span>czeka na wpłatę</span>');
        expect(text).toContain('ProjektAudyt energetyczny domu');
        expect(text).toContain('Kwota2 400,00 zł brutto (1 951,22 zł netto)');
        expect(text).toContain('Czas10,0 h, w tym 3,5 h w tym miesiącu');
        expect(text).toContain('Statusw trakcie, 2 z 4 zadań zrobione');
        expect(text).toContain('Bez abonamentu i banku godzin');
        expect(text).toContain('Faktury · Infakt');
        expect(text).not.toContain('Bank godzin');
        expect(text).not.toContain('Abonament');
    });

    test('a scope from the catch-all project reads as Zakres and Edytuj opens its editor', () => {
        const project = {
            id: 3,
            name: 'Audyt energetyczny domu',
            description: 'Dom jednorodzinny, 164 m2',
            include_in_month_close: false,
            backup_path: null,
        };
        const html = render({ ...oneOff, order: { ...order, project } });
        const text = textOf(html);

        expect(text).toContain('ZakresDom jednorodzinny, 164 m2');
        expect(html).toMatch(/<button[^>]*>Edytuj<\/button>/);
        expect(textOf(render(oneOff))).not.toContain('Zakres');
        expect(render(oneOff)).not.toMatch(/>Edytuj</);
    });

    test('without an invoice the brief says so and the amount row is gone', () => {
        const text = textOf(render({ ...oneOff, order: { ...order, invoice: null, state: 'done', tasks: { done: 4, total: 4 } } }));

        expect(text).toContain('Audyt energetyczny domu. Bez faktury, zlecenie zakończone.');
        expect(text).not.toContain('Kwota');
    });

    test('a paid invoice and a draft each read in the brief', () => {
        expect(textOf(render({ ...oneOff, order: { ...order, invoice: { ...order.invoice!, state: 'paid', paid_date: '2026-10-02' } } }))).toContain(
            'Faktura 29/09/2026 opłacona 02.10, zlecenie w trakcie.',
        );
        expect(textOf(render({ ...oneOff, order: { ...order, invoice: { ...order.invoice!, state: 'draft' } } }))).toContain(
            'Faktura 29/09/2026 to szkic w Infakcie, zlecenie w trakcie.',
        );
    });
});

describe('the invoice rail amount basis', () => {
    const shared = invoice('29/09/2026', '2026-09-29', null);

    test('a retainer client reads the same invoice net and an order client reads it gross', () => {
        const retainer = textOf(render({ ...empty, invoices: { rows: [shared], more: 0 } }));
        const order = textOf(
            render({
                mode: 'order',
                invoices: { rows: [shared], more: 0 },
                order: {
                    title: 'Audyt',
                    project: null,
                    started_on: null,
                    hours: 0,
                    month_hours: 0,
                    tasks: { done: 0, total: 0 },
                    state: null,
                    invoice: null,
                },
            }),
        );

        expect(retainer).toContain('29/09/2026 · wrzesień1 800,00 zł');
        expect(order).toContain('29/09/2026 · wrzesień2 214,00 zł');
        expect(order).not.toContain('1 800,00 zł');
    });
});
