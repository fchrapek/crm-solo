import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../../lang/pl.json';
import { hoursNumber } from '../client-list/format';
import { BillingFrame, ClientBillingTab, monthRow } from './client-billing';
import { invoiceChip, moneyOf, monthNote, monthPills, monthUrl, periodLabel, reportChip, reportState } from './format';
import { invoiceListUrl, yearPills } from './invoice-list';
import { correctionOf, ledgerState } from './ledger-tile';
import { emailLook } from './month-tiles';
import type { BilledMonth, BillingInvoice, BillingInvoiceList, BillingLedger, BillingMonth, LedgerRow, OrderBilling, RetainerBilling } from './types';

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
        billed: null,
        billed_editable: false,
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
    invoice_drafts: null,
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
    invoice_drafts: null,
};

const figures = (period: string, extra: Partial<BilledMonth> = {}): BilledMonth => ({
    client_id: 7,
    client: 'Pracownia Mebli',
    period,
    real: 32,
    billed: 32,
    tasks: [
        { task_id: 11, task: 'Formularz wyceny mebli na wymiar', project: 'Strona WWW', real: 20, stated: null, billed: 20, note: null },
        { task_id: 12, task: 'Migracja hostingu', project: 'Strona WWW', real: 12, stated: 10, billed: 12, note: 'godzina na mój błąd' },
    ],
    note: null,
    used: 32,
    source: 'billed',
    has_retainer: true,
    opening: -9.5,
    available: 20.5,
    closing: -11.5,
    contracted: 30,
    closed: false,
    closed_on: null,
    closure_id: null,
    report: null,
    refusal: null,
    refusal_code: null,
    ...extra,
});

const month = (period: string, extra: Partial<BillingMonth> = {}, figs: Partial<BilledMonth> = {}): BillingMonth => ({
    period,
    figures: figures(period, figs),
    report: null,
    invoices: [],
    checklist: null,
    composers: [{ key: 'structured_list', label: 'Lista' }],
    ...extra,
});

const draft = { id: 9, status: 'draft' as const, sent_on: null, generated_on: '2026-10-02', needs_recalculation: false, composer_key: 'structured_list' };

const list = (extra: Partial<BillingInvoiceList> = {}): BillingInvoiceList => ({
    rows: frame.invoices.rows,
    total: 3,
    truncated: false,
    filters: { year: 2026, month: null, years: [2026, 2025] },
    revenue: { currency: 'PLN', period_net: 5400, period_gross: 6642, period_invoice_count: 3, alltime_net: 9000, alltime_gross: 11070, share_net: 12.5, last_invoice_date: '2026-09-15' },
    ...extra,
});

const open = (r: LedgerRow): LedgerRow => ({ ...r, settlement: 'open', active: true, before_baseline: false });

const render = (billing: Fixture | OrderBilling, shown: BillingMonth | undefined = month('2026-09'), emails: Record<string, never> | null = null) =>
    renderToStaticMarkup(
        <ClientBillingTab
            clientId={7}
            clientName="Pracownia Mebli Przykładowa"
            billing={billing}
            ledger={'ledger' in billing ? billing.ledger : undefined}
            month={'ledger' in billing ? shown : undefined}
            invoices={list()}
            emails={emails}
        />,
    );
const spaces = (text: string) => text.replace(/[\u00a0\u202f]/g, ' ');
const textOf = (html: string) => spaces(html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' '));
const t = i18next.t.bind(i18next);

describe('the month Rozliczenia shows', () => {
    test('the month in the props drives the figures, the tasks and the tiles around them', () => {
        const text = textOf(render(frame));

        expect(text).toContain('Pakiet 30 h na miesiąc');
        expect(text).toContain('Czas rzeczywisty 32,0 h zapisany w zadaniach');
        expect(text).toContain('Czas rozliczany 32,0 h równy rzeczywistemu');
        expect(text).toContain('Bilans po miesiącu −11,5 h 11,5 h ponad pakiet');
        expect(text).toContain('Zadania września 2');
        expect(text).toContain('Formularz wyceny mebli na wymiar 20,0 h · 20,0 h');
        expect(text).toContain('Razem 32,0 h · 32,0 h');
        expect(text).toContain('Raport za wrzesień');
        expect(text).toContain('Faktury za wrzesień');
    });

    test('a task\'s own figure is explained beside its hours, where a long task name cannot cut it off', () => {
        const html = spaces(render(frame));
        const row = html.slice(html.indexOf('Migracja hostingu'));

        expect(row.indexOf('aria-label="Twoja liczba"')).toBeGreaterThan(row.indexOf('12,0 h · 12,0 h'));
    });

    test('the retainer stands in the main column under the month\'s invoices, so the side column holds only the report and the month', () => {
        const html = render(frame);
        const aside = html.slice(html.indexOf('<aside'), html.indexOf('</aside>'));

        expect(html.indexOf('Abonament')).toBeGreaterThan(html.indexOf('Faktury za wrzesień'));
        expect(html.indexOf('Abonament')).toBeLessThan(html.indexOf('<aside'));
        expect(aside).toContain('Raport za wrzesień');
        expect(aside).not.toContain('Abonament');
    });

    test('another month reads its own figures: nothing of the page is tied to the newest month', () => {
        const text = textOf(render(frame, month('2026-08', {}, { real: 24, billed: 24, used: 24, closing: -9.5, tasks: [] })));

        expect(text).toContain('Zadania sierpnia');
        expect(text).toContain('Czas rzeczywisty 24,0 h');
        expect(text).toContain('Raport za sierpień');
        expect(text).not.toContain('Zadania września');
    });

    test('every task whose figure may change offers Zmień, named after the task', () => {
        const html = render(frame);

        expect(html.match(/aria-label="Zmień czas rozliczany: /g)?.length).toBe(2);
        expect(html).toContain('aria-label="Zmień czas rozliczany: Migracja hostingu"');
    });

    test('a month that cannot change says what locks it and offers no Zmień', () => {
        const locked = month('2026-08', {}, { closed: true, closed_on: '2026-09-04', closure_id: 4, refusal: 'zamknięty', refusal_code: 'period_settled' });
        const html = render(frame, locked);

        expect(html).not.toContain('aria-label="Zmień czas rozliczany');
        expect(textOf(html)).toContain('Sierpień jest zamknięty od 04.09, więc tych liczb nie zmienisz.');
    });

    test('a month closed before figures were kept per task shows its totals only', () => {
        const text = textOf(render(frame, month('2026-08', {}, { tasks: [], real: 24 })));

        expect(text).toContain('zostały tylko sumy');
        expect(text).not.toContain('Razem');
    });

    test('no figure for overage cost is ever shown', () => {
        const text = textOf(render(frame));

        expect(text).not.toContain('zł / h');
        expect(text).toContain('Ponad pakiet do banku, bez dopłat');
    });
});

describe('the month pills and the head', () => {
    const rows = [open(row('2026-09', 'draft', 32, -9.5)), row('2026-08', 'sent', 24, -15.5), { ...row('2026-07', 'sent', 1, 0), whole_month: false }];

    test('the pills are the month in progress, the whole months of the bank and the month shown, each a link that carries the month', () => {
        const pills = monthPills(rows, '2026-08', '2026-10', 7, 'pl', t);

        expect(pills.map((pill) => pill.label)).toEqual(['Październik', 'Wrzesień', 'Sierpień']);
        expect(pills[0].note).toBe('w toku');
        expect(pills.filter((pill) => pill.active).map((pill) => pill.label)).toEqual(['Sierpień']);
        expect(pills[1].href).toBe(monthUrl(7, '2026-09'));
        expect(String(pills[1].href)).toContain('/clients/7/rozliczenia?miesiac=2026-09');
    });

    test('a month older than the pills still gets its own pill, with the year once it is another one', () => {
        const pills = monthPills(rows, '2025-12', '2026-10', 7, 'pl', t);

        expect(pills[pills.length - 1]).toMatchObject({ label: 'Grudzień 2025', active: true });
    });

    test('the line under the month says how it stands and where its report is', () => {
        expect(monthNote(month('2026-09'), '2026-10', null, 'pl', t)).toBe('Skończył się 30.09 · otwarty · raport do wygenerowania');
        expect(monthNote(month('2026-10'), '2026-10', '2026-09', 'pl', t)).toBe('W toku · wrzesień jeszcze otwarty');
        expect(
            spaces(
                monthNote(
                    month('2026-08', { report: { ...draft, status: 'sent', sent_on: '2026-09-05' } }, { closed: true, closed_on: '2026-09-04', used: 29.5 }),
                    '2026-10',
                    null,
                    'pl',
                    t,
                ),
            ),
        ).toBe('Zamknięty 04.09 na 29,5 z 30 h · raport wysłany 05.09');
    });

    test('the head is the month as the poster with the months beside it, and an order names the order', () => {
        const client = { id: 7, name: 'Pracownia Mebli Przykładowa sp. z o.o.', short_name: 'Pracownia Mebli', billing_mode: 'retainer' as const, is_archived: false, is_pinned: false, open_tasks_count: 0, live_agent_minutes: null };
        const html = renderToStaticMarkup(<BillingFrame client={client} billing={frame} ledger={{ ...frame.ledger, rows }} month={month('2026-09')} />);

        expect(textOf(html)).toContain('Wrzesień Skończył się 30.09 · otwarty · raport do wygenerowania');
        expect(html).toContain('aria-label="Miesiące"');
        expect(html).toContain('href="/clients/7/rozliczenia?miesiac=2026-08"');
    });
});

describe('the report of the month', () => {
    const states = [
        [month('2026-10'), true, 'running', 'miesiąc trwa'],
        [month('2026-09'), false, 'to_generate', 'do wygenerowania'],
        [month('2026-09', { report: draft }), false, 'draft', 'szkic z 02.10'],
        [month('2026-09', { report: { ...draft, needs_recalculation: true } }), false, 'stale', 'wymaga przeliczenia'],
        [month('2026-09', { report: { ...draft, status: 'finalized' } }), false, 'finalized', 'gotowy · czeka na wysłanie'],
        [month('2026-09', { report: { ...draft, status: 'sent', sent_on: '2026-10-05' } }), false, 'sent', 'wysłany 05.10'],
    ] as const;

    test('each state has its chip', () => {
        for (const [shown, running, state, label] of states) {
            expect(reportState(shown, running)).toBe(state);
            expect(reportChip(shown, running, 'pl', t).label).toBe(label);
        }
    });

    test('a month without a report offers generating one, a draft opens and regenerates, a ready one is marked sent', () => {
        expect(textOf(render(frame))).toContain('Generuj raport');
        const drafted = render(frame, month('2026-09', { report: draft }));
        expect(drafted).toContain('href="/clients/7/reports/9"');
        expect(textOf(drafted)).toContain('Otwórz raport Wygeneruj ponownie');
        expect(textOf(render(frame, month('2026-09', { report: { ...draft, status: 'finalized' } })))).toContain('Oznacz jako wysłany Otwórz raport');
        expect(textOf(render(frame, month('2026-09', { report: { ...draft, needs_recalculation: true } })))).toContain('Liczby wcześniejszego miesiąca się zmieniły.');
    });
});

describe('the month tile', () => {
    const withRows = (rows: LedgerRow[]): Fixture => ({ ...frame, ledger: { ...frame.ledger, rows } });

    test('its lines add up to the balance the next month opens with', () => {
        const text = textOf(render(frame));

        expect(text).toContain('Pakiet +30,0 h');
        expect(text).toContain('Z poprzednich miesięcy −9,5 h');
        expect(text).toContain('Przepracowane · z czasu rozliczanego −32,0 h');
        expect(text).toContain('Bilans → otwarcie października −11,5 h');
    });

    test('Zamknij miesiąc stands only on an ended month that owes a close', () => {
        const owing = withRows([open(row('2026-09', 'draft', 32, -9.5)), ...frame.ledger.rows.slice(1)]);

        expect(textOf(render(owing))).toContain('Zamknij miesiąc');
        expect(textOf(render(frame))).not.toContain('Zamknij miesiąc');
        expect(textOf(render(owing, month('2026-10')))).not.toContain('Zamknij miesiąc');
    });

    test('Otwórz ponownie stands only on the latest closed month, and the checklist is one click away', () => {
        const rows = [
            { ...row('2026-09', 'sent', 32, -9.5), settlement: 'closed' as const, closed_on: '2026-10-05', closure_id: 4 },
            { ...row('2026-08', 'sent', 24, -15.5), settlement: 'closed' as const, closed_on: '2026-09-04' },
        ];
        const closed = (period: string) => month(period, { checklist: { done: 15, total: 15 } }, { closed: true, closed_on: '2026-10-05', closure_id: period === '2026-09' ? 4 : 3, refusal: 'x', refusal_code: 'period_settled' });
        const latest = render(withRows(rows), closed('2026-09'));

        expect(textOf(latest)).toContain('Lista kontrolna · 15 z 15');
        expect(latest).toContain('href="/month-close/7?period=2026-09"');
        expect(latest.match(/Otwórz ponownie<\/button>/g)?.length).toBe(1);
        expect(render(withRows(rows), closed('2026-08'))).not.toContain('Otwórz ponownie</button>');
        expect(monthRow(withRows(rows).ledger, '2026-09')?.closure_id).toBe(4);
    });
});

describe('the hours bank', () => {
    test('the month shown is the marked row, and every whole month opens from its name', () => {
        const html = render(frame);

        expect(html.match(/aria-current="true"/g)?.length).toBe(1);
        expect(html).toMatch(/<tr aria-current="true"[^>]*>.*?Wrzesień 2026/s);
        expect(html).toContain('href="/clients/7/rozliczenia?miesiac=2026-07"');
    });

    test('a month shows both values, with the marker on one billed off its real time', () => {
        const billed = { real: 27.3, billed: 26, tasks: [], note: 'zaokrąglone' };
        const html = render({ ...frame, ledger: { ...frame.ledger, rows: [{ ...row('2026-09', 'draft', 26, -9.5), billed }, ...frame.ledger.rows.slice(1)] } });
        const text = textOf(html);

        expect(text).toContain('Rzeczywisty');
        expect(text).toContain('Rozliczany');
        expect(text).toContain('27,3 h');
        expect(text).toContain('27,3 · 26,0 h');
        expect(html.match(/aria-label="Twoja liczba"/g)?.length).toBe(2);
        expect(text).not.toContain('Zaokrąglanie');
    });

    test('an opening that does not continue the month before shows as a correction row', () => {
        const [newer, older] = [row('2026-09', 'draft', 32, -9.5), row('2026-08', 'sent', 24, -15.5)];

        expect(correctionOf(newer, older)).toBeNull();
        expect(correctionOf({ ...newer, opening: 0 }, older)).toBe(9.5);
        const text = textOf(render({ ...frame, ledger: { ...frame.ledger, rows: [{ ...newer, opening: 0, closing: -2 }, older] } }));
        expect(text).toContain('Korekta 01.09');
        expect(text).toContain('bilans otwarcia ustawiony ręcznie');
    });

    test('the state keeps the settlement and the report apart', () => {
        const closed = { ...row('2026-08', 'sent', 24, -15.5, '2026-09-05'), settlement: 'closed' as const, closed_on: '2026-09-04' };

        expect(ledgerState(closed, null, 'pl', t)).toBe('zamknięty 04.09 · raport wysłany 05.09');
        expect(ledgerState(row('2026-07', 'finalized', 30.5, -15), null, 'pl', t)).toBe('sfinalizowany');
    });

    test('an older page of months loads in place through Starsze, only the ledger reloading', () => {
        const html = render({ ...frame, ledger: { ...frame.ledger, next: '2' } });

        expect(textOf(html)).toContain('Starsze');
        expect(textOf(render(frame))).not.toContain('Starsze');
    });

    test('without a row the bank says where it starts, and no opening balance is offered', () => {
        const text = textOf(render(empty, month('2026-10', {}, { has_retainer: false, contracted: null })));

        expect(text).toContain('Bank godzin zaczyna się od pierwszego raportu');
        expect(text).not.toContain('Zmień bilans otwarcia');
        expect(text).toContain('Bez abonamentu, więc liczy się każda zalogowana godzina.');
    });

    test('the opening balance is offered while its report row is on the page', () => {
        expect(textOf(render(frame))).toContain('Zmień bilans otwarcia');
    });
});

describe('retainer terms', () => {
    test('package and rollover read as entered while worked hours and balances keep a decimal', () => {
        const text = textOf(render(frame));

        expect(text).toContain('30 h w pakiecie, rollover do 60 h');
        expect(text).toContain('1 800,00 zł netto / mc');
        expect(text).toContain('Rollover do 60 h w obie strony');
        expect(text).toContain('32,0 h');
    });
});

describe('billing formats', () => {
    test('hours keep the precision the report holds', () => {
        expect(spaces(hoursNumber(-9.5, 'pl', true))).toBe('−9,5');
        expect(spaces(hoursNumber(30, 'pl'))).toBe('30,0');
        expect(spaces(hoursNumber(0.25, 'pl', true))).toBe('+0,25');
    });

    test('money groups thousands the way the frame does', () => {
        expect(spaces(moneyOf(1800, 'PLN', 'pl', false))).toBe('1 800 zł');
        expect(spaces(moneyOf(1800, 'PLN', 'pl', true))).toBe('1 800,00 zł');
    });

    test('a partial period reads as its dates', () => {
        const partial = { ...row('2026-09', 'sent', 10, 0), whole_month: false, period_start: '2026-09-01', period_end: '2026-09-14' };

        expect(periodLabel(partial, undefined, 'pl')).toBe('01.09–14.09');
    });
});

describe('the full invoice list', () => {
    test('the period is a choice of links that carry the month shown and reload the list alone', () => {
        const pills = yearPills(list().filters, 2026, (year) => invoiceListUrl(7, '2026-09', year, null), t);

        expect(pills.map((pill) => pill.label)).toEqual(['2026', 'Ostatnie 12 miesięcy', 'Wszystkie lata']);
        expect(pills[0]).toMatchObject({ active: true });
        expect('menu' in pills[0] && pills[0].menu.map((choice) => choice.label)).toEqual(['2026', '2025']);
        expect(pills[1]).toMatchObject({ href: '/clients/7/rozliczenia?miesiac=2026-09&invoice_year=last12', visit: { only: ['billing_invoices'], preserveScroll: true } });
    });

    test('a month narrows a year only, and an order has no month to keep', () => {
        expect(invoiceListUrl(7, null, 2026, 9)).toBe('/clients/7/rozliczenia?invoice_year=2026&invoice_month=9');
        expect(invoiceListUrl(7, null, 'all', 9)).toBe('/clients/7/rozliczenia?invoice_year=all');
    });

    test('the rows read number, state and both amounts, over the revenue of the same window', () => {
        const text = textOf(render(frame));

        expect(text).toContain('Faktury · Infakt 3');
        expect(text).toContain('Przychody · 2026 5 400,00 zł');
        expect(text).toContain('Udział w Twoich przychodach 12,5 %');
        expect(text).toContain('15/09/2026 opłacona 15.09');
        expect(text).toContain('1 800,00 zł');
        expect(text).toContain('2 214,00 zł');
    });

    test('an overdue invoice waits: its chip is the waiting dot, never the over-limit colour', () => {
        const overdue = invoice('3/10/2026', '2026-09-30', null, { overdue: true, payment_date: '2026-10-14' });

        expect(invoiceChip(overdue, 'pl', t)).toEqual({ status: 'waiting', label: 'po terminie od 14.10' });
        expect(invoiceChip(invoice('1/1/2026', '2026-01-01', '2026-01-09'), 'pl', t).status).toBe('done');
        expect(invoiceChip(invoice('1/1/2026', '2026-01-01', null, { status: 'draft' }), 'pl', t).status).toBe('open');
        const html = renderToStaticMarkup(
            <ClientBillingTab clientId={7} clientName="x" billing={frame} ledger={frame.ledger} month={month('2026-09')} invoices={list({ rows: [overdue] })} />,
        );
        expect(html).not.toMatch(/data-tone="over"[^>]*>po terminie/);
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
    const oneOff: OrderBilling = {
        mode: 'order',
        billed_months: [],
        invoices: { rows: [invoice('29/09/2026', '2026-09-29', null)], more: 0 },
        order,
        reports: [],
        report_period: '2026-09',
        composers: [{ key: 'retainer', label: 'Raport miesięczny' }],
        currency: 'PLN',
    };
    const client = { id: 7, name: 'Jan Przykładowy', short_name: 'Jan Przykładowy', billing_mode: 'order' as const, is_archived: false, is_pinned: false, open_tasks_count: 0, live_agent_minutes: null };

    test('the head names the order and where its invoice stands', () => {
        const text = textOf(renderToStaticMarkup(<BillingFrame client={client} billing={oneOff} ledger={undefined} month={undefined} />));

        expect(text).toContain('Zlecenie Audyt energetyczny domu · Faktura 29/09/2026 czeka na wpłatę do 14.10 · zlecenie w trakcie');
    });

    test('the figures, the order block and the invoices stand where the bank and the retainer were', () => {
        const text = textOf(render(oneOff));

        expect(text).toContain('Kwota 2 400 zł brutto, 1 951,22 zł netto');
        expect(text).toContain('Czas 10,0 h 3,5 h w tym miesiącu');
        expect(text).toContain('Zadania 2 z 4 zrobione');
        expect(text).toContain('Projekt Audyt energetyczny domu');
        expect(text).toContain('Kwota 2 400,00 zł brutto (1 951,22 zł netto)');
        expect(text).toContain('Czas 10,0 h, w tym 3,5 h w tym miesiącu');
        expect(text).toContain('Status w trakcie, 2 z 4 zadań zrobione');
        expect(text).toContain('Bez abonamentu i banku godzin');
        expect(text).toContain('Faktury · Infakt');
        expect(text).not.toContain('Bank godzin');
    });

    test('a first retainer can be added from the order block, and reports are listed with one to generate', () => {
        const html = render({ ...oneOff, reports: [{ id: 41, period: '2026-08', status: 'sent' }] });

        expect(html).toMatch(/<button[^>]*aria-haspopup="dialog"[^>]*>Dodaj abonament<\/button>/);
        expect(html).toMatch(/<a[^>]*href="\/clients\/7\/reports\/41"[^>]*>Raport za sierpień<\/a>/);
        expect(textOf(html)).toContain('wysłany');
        expect(html).toMatch(/<button[^>]*>Generuj raport<\/button>/);
        expect(textOf(render(oneOff))).toContain('Nie ma jeszcze raportów.');
    });

    test('a scope from the catch-all project reads as Zakres and Edytuj opens its editor', () => {
        const project = { id: 3, name: 'Audyt energetyczny domu', description: 'Dom jednorodzinny, 164 m2' } as NonNullable<OrderBilling['order']['project']>;
        const text = textOf(render({ ...oneOff, order: { ...order, project } }));

        expect(text).toContain('Zakres Dom jednorodzinny, 164 m2');
        expect(text).toContain('Edytuj');
    });

    test('without an invoice the head says so and the amount is gone', () => {
        const bare = { ...oneOff, order: { ...order, invoice: null } };

        expect(textOf(renderToStaticMarkup(<BillingFrame client={client} billing={bare} ledger={undefined} month={undefined} />))).toContain('Bez faktury');
        expect(textOf(render(bare))).not.toContain('Kwota');
    });

    test('its months stand alone in both values, each opening the billed time of that month', () => {
        const billed = { real: 18, billed: 16, tasks: [], note: null };
        const html = render({ ...oneOff, billed_months: [{ period: '2026-09', billed, billed_editable: true }] });

        expect(textOf(html)).toContain('Wrzesień');
        expect(textOf(html)).toContain('18,0 · 16,0 h');
        expect(html).toContain('aria-haspopup="dialog"');
    });
});

describe('a summary-email client', () => {
    const state = (status: 'draft' | 'sent' | 'test_sent' | 'failed' | 'unconfirmed' | null, extra = {}) => ({
        status,
        drafted_on: '2026-10-02',
        drafted_via: 'agent' as const,
        sent_on: '2026-10-03',
        changed_on: '2026-10-02',
        recipients: 2,
        ...extra,
    });

    test('the email tile names each state and its one action', () => {
        expect(emailLook(undefined, true, 'pl', t)).toMatchObject({ chip: { status: 'waiting', label: 'do przygotowania' }, action: 'Przygotuj szkic', primary: true });
        expect(emailLook(undefined, false, 'pl', t)).toBeNull();
        expect(emailLook(state('draft'), true, 'pl', t)).toMatchObject({ chip: { label: 'szkic 02.10 · agent' }, action: 'Otwórz szkic' });
        expect(emailLook(state('test_sent'), true, 'pl', t)?.chip.label).toBe('testowo 02.10');
        expect(emailLook(state('sent'), true, 'pl', t)).toMatchObject({ chip: { status: 'done', label: 'wysłano 03.10 · 2 osoby' }, action: 'Zobacz' });
        expect(emailLook(state('failed'), true, 'pl', t)?.notice).toContain('nie został wysłany');
        expect(emailLook(state('unconfirmed'), true, 'pl', t)?.chip).toEqual({ status: 'waiting', label: 'niepotwierdzone 02.10' });
    });

    test('the month shows its email where others show the report, and a report it has stays reachable', () => {
        const emails = { '2026-09': state('draft') } as never;
        const text = textOf(render(frame, month('2026-09'), emails));

        expect(text).toContain('E-mail za wrzesień');
        expect(text).not.toContain('Raport za wrzesień');
        expect(textOf(render(frame, month('2026-09', { report: draft }), emails))).toContain('Raport za wrzesień');
    });
});
