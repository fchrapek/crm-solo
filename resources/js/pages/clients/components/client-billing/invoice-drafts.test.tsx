import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../../lang/pl.json';
import { groupChip, groupHint, groupTitle, InvoiceDraftsTile } from './invoice-drafts';
import { InvoiceList, invoiceListHint } from './invoice-list';
import type { BillingInvoice, BillingInvoiceList, InvoiceDraftGroup, InvoiceDrafts } from './types';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const group = (extra: Partial<InvoiceDraftGroup>): InvoiceDraftGroup => ({
    group: 1,
    projects: ['Strona WWW meble-przykladowe.test'],
    state: 'ready',
    net: 192000,
    currency: 'PLN',
    chained: true,
    reason: null,
    source: { number: '21/09/2026', sale_date: '2026-08-31' },
    draft_url: null,
    ...extra,
});

const drafts: InvoiceDrafts = {
    period: '2026-09',
    groups: [group({}), group({ group: 2, projects: ['Ogólne'], state: 'needs_source', net: 19000, chained: false, source: null })],
};

const t = i18next.t.bind(i18next);
const textOf = (html: string) =>
    html
        .replace(/<[^>]+>/g, ' ')
        .replace(/[\u00a0\u202f]/g, ' ')
        .replace(/\s+/g, ' ');
const render = (value: InvoiceDrafts) =>
    textOf(renderToStaticMarkup(<InvoiceDraftsTile clientId={7} clientName="Pracownia Mebli" drafts={value} locale="pl-PL" />));

describe('Do wystawienia za miesiąc', () => {
    test('the Figma state: a copyable row with its amount, a row that needs a pick, and Copy all counting the ready ones', () => {
        const text = render(drafts);

        expect(text).toContain('Do wystawienia za wrzesień 2');
        expect(text).toContain('Kopiuj wszystkie (1)');
        expect(text).not.toContain('kwoty netto');
        expect(t('drafts_hint', { month: 'wrzesień' })).toBe(
            'Kwoty netto. Kopia faktury dostaje daty za wrzesień i trafia do Infaktu jako szkic; wystawiasz ją tam.',
        );
        expect(text).toContain('Strona WWW meble-przykladowe.test');
        expect(text).toContain('1 920,00 zł');
        expect(text).toContain('Kopiuj fakturę');
        expect(text).toContain('Wybierz fakturę');
        expect(text).not.toContain('190,00 zł');
    });

    test('the (i) names the source invoice, its month, the amount and the new month', () => {
        expect(groupHint(drafts.groups[0], drafts.period, 'pl-PL', t).replace(/[\u00a0\u202f]/g, ' ')).toBe(
            'Kopia faktury 21/09/2026 za sierpień, 1 920,00 zł netto, z nowymi datami za wrzesień. Powstaje szkic, wystawiasz go w Infakcie.',
        );
    });

    test('a drafted group links to its draft and nothing is left to copy', () => {
        const text = render({ ...drafts, groups: [group({ state: 'drafted', draft_url: 'https://app.infakt.test/faktury/9' })] });

        expect(text).toContain('szkic w Infakcie');
        expect(text).toContain('Otwórz w Infakcie');
        expect(text).not.toContain('Kopiuj wszystkie');
    });

    test('a source the CRM has not mirrored shows no amount, so the row never shows one figure and copies another', () => {
        const text = render({ ...drafts, groups: [group({ net: null, source: null })] });

        expect(text).toContain('Kopiuj fakturę');
        expect(text).not.toContain('zł');
    });

    test('a copyable row also offers the picker, and a refused source says why it needs one', () => {
        const ready = render({ ...drafts, groups: [group({})] });
        expect(ready).toContain('Kopiuj fakturę');
        expect(ready).toContain('Wybierz fakturę');

        expect(groupHint(group({ state: 'needs_source', reason: 'settles_advance' }), drafts.period, 'pl-PL', t)).toBe(
            'Faktura zapisana ostatnio dla tej grupy rozlicza zaliczkę. Wybierz fakturę do kopii.',
        );
    });

    test('a group without projects is named by its number', () => {
        expect(groupTitle(group({ projects: [], group: 3 }), t)).toBe('Faktura 3');
    });

    test('a group that is not an action says so in its chip: a draft on the way, one to check, no invoice to copy', () => {
        expect(groupChip(group({ state: 'processing' }), t)).toEqual({ status: 'open', label: 'szkic powstaje' });
        expect(groupChip(group({ state: 'unconfirmed' }), t)).toEqual({ status: 'waiting', label: 'sprawdź w Infakcie' });
        expect(groupChip(group({ state: 'blocked' }), t)?.status).toBe('waiting');
        expect(groupChip(group({ state: 'needs_source' }), t)).toEqual({ status: 'waiting', label: 'brak wzoru' });
        expect(groupChip(group({}), t)).toBeNull();

        const text = render({ ...drafts, groups: [group({ state: 'unconfirmed' })] });
        expect(text).not.toContain('Kopiuj fakturę');
        expect(text).not.toContain('Wybierz fakturę');
    });

    test('every group keeps its state and buttons under its name, on every width', () => {
        const html = renderToStaticMarkup(<InvoiceDraftsTile clientId={7} clientName="Pracownia Mebli" drafts={drafts} locale="pl-PL" />);

        expect(html.match(/data-stack="always"/g)?.length).toBe(2);
    });
});

describe("the month's own invoices in Faktury · Infakt", () => {
    const list: BillingInvoiceList = {
        rows: [],
        total: 0,
        truncated: false,
        filters: { year: 2025, month: null, years: [2026, 2025] },
        revenue: null,
    };
    const renderList = (invoices: BillingInvoice[], drafting = false) =>
        renderToStaticMarkup(
            <InvoiceList
                clientId={7}
                period="2026-09"
                month={{ period: '2026-09', invoices, drafting }}
                list={list}
                thisYear={2026}
                locale="pl-PL"
            />,
        );

    test("the invoices booked in the month stand over the filters whatever year the list shows, marked as the month's", () => {
        const invoice: BillingInvoice = {
            id: 4,
            number: '4/08/2026',
            status: 'paid',
            currency: 'PLN',
            net: 1920,
            gross: 2361.6,
            invoice_date: '2026-08-03',
            sale_date: '2026-08-31',
            payment_date: '2026-08-17',
            paid_date: '2026-08-12',
            overdue: false,
            external_url: 'https://app.infakt.test/faktury/4',
        };
        const html = renderList([invoice]);
        const text = textOf(html);

        expect(text).toContain('Za wrzesień · 1 faktura, zaksięgowana we wrześniu');
        expect(text).toContain('4/08/2026 opłacona 12.08 03.08 1 920,00 zł');
        expect(text.indexOf('Za wrzesień')).toBeLessThan(text.indexOf('Ostatnie 12 miesięcy'));
        expect(html.match(/aria-current="true"/g)?.length).toBe(1);
        expect(textOf(renderList([invoice, { ...invoice, id: 5, number: '5/09/2026' }]))).toContain(
            'Za wrzesień · 2 faktury, zaksięgowane we wrześniu',
        );
    });

    test('a month without an invoice shows it as a quiet status, the explanation and the pointer to its groups in the (i)', () => {
        expect(textOf(renderList([]))).toContain('Brak faktury za wrzesień');
        expect(textOf(renderList([], true))).toContain('Brak faktury za wrzesień');
        expect(textOf(renderList([]))).not.toContain('nie ma faktury');
        expect(invoiceListHint({ period: '2026-09', invoices: [], drafting: false }, 'pl-PL', t)).toBe('Kwoty netto. Wrzesień nie ma faktury.');
        expect(invoiceListHint({ period: '2026-09', invoices: [], drafting: true }, 'pl-PL', t)).toBe(
            'Kwoty netto. Wrzesień nie ma jeszcze faktury. Grupy do wystawienia są przy Raporcie.',
        );
        expect(invoiceListHint(null, 'pl-PL', t)).toBe('Kwoty netto.');
    });
});
