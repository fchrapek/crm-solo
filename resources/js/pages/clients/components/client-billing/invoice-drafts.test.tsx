import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../../lang/pl.json';
import { groupChip, groupHint, groupTitle, MonthInvoices } from './invoice-drafts';
import type { BillingInvoice, InvoiceDraftGroup, InvoiceDrafts } from './types';

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
const textOf = (html: string) => html.replace(/<[^>]+>/g, ' ').replace(/[\u00a0\u202f]/g, ' ').replace(/\s+/g, ' ');
const render = (value: InvoiceDrafts | null, invoices: BillingInvoice[] = []) =>
    textOf(
        renderToStaticMarkup(
            <MonthInvoices clientId={7} clientName="Pracownia Mebli" period="2026-09" drafts={value} invoices={invoices} locale="pl-PL" />,
        ),
    );

describe('Faktury za miesiąc', () => {
    test('the Figma state: a copyable row with its amount, a row that needs a pick, and Kopiuj wszystkie', () => {
        const text = render(drafts);

        expect(text).toContain('Faktury za wrzesień');
        expect(text).toContain('Kopiuj wszystkie');
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
        expect(groupTitle(group({ projects: [] , group: 3 }), t)).toBe('Faktura 3');
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

    test('a month no draft is made for lists the invoices booked in it, with nothing to copy', () => {
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
        const text = render(null, [invoice]);

        expect(text).toContain('Faktury za wrzesień 1');
        expect(text).toContain('4/08/2026 1 920,00 zł opłacona 12.08');
        expect(text).not.toContain('Kopiuj');
        expect(render(null)).toContain('Brak faktur za ten miesiąc.');
    });
});
