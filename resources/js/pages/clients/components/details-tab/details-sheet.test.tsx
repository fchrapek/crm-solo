import { afterAll, describe, expect, mock, test } from 'bun:test';
import i18next from 'i18next';
import { type ComponentProps, createContext, type ReactNode, useContext } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../../lang/pl.json';
import type { ClientDetails } from './types';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

// Radix portals render nothing on the server, so while these tests run the open content renders in place.
let wide = false;
let inline = true;
mock.module('@/components/solo/use-solo-wide', () => ({ useSoloWide: () => wide }));
const Open = createContext(false);
const sheet = { ...(await import('@/components/ui/sheet')) };
const RealSheet = sheet.Sheet;
const RealSheetContent = sheet.SheetContent;
mock.module('@/components/ui/sheet', () => ({
    ...sheet,
    Sheet: (props: ComponentProps<typeof RealSheet>) => (
        <Open.Provider value={props.open ?? false}>
            <RealSheet {...props} />
        </Open.Provider>
    ),
    SheetContent: function SheetContent(props: ComponentProps<typeof RealSheetContent>) {
        const open = useContext(Open);

        return !inline ? <RealSheetContent {...props} /> : open ? <div data-test="sheet-content">{props.children as ReactNode}</div> : null;
    },
}));
const dialog = { ...(await import('@/components/ui/dialog')) };
const RealDialog = dialog.Dialog;
const RealDialogContent = dialog.DialogContent;
mock.module('@/components/ui/dialog', () => ({
    ...dialog,
    Dialog: (props: ComponentProps<typeof RealDialog>) => (
        <Open.Provider value={props.open ?? false}>
            <RealDialog {...props} />
        </Open.Provider>
    ),
    DialogContent: function DialogContent(props: ComponentProps<typeof RealDialogContent>) {
        const open = useContext(Open);

        return !inline ? <RealDialogContent {...props} /> : open ? <div data-test="dialog-content">{props.children as ReactNode}</div> : null;
    },
}));

const { ClientDetailsDialog } = await import('./client-details-dialog');
const { ContactDialog, emailList } = await import('./contact-dialog');
const { DetailsTab, detailsActionOrder } = await import('./details-tab');

afterAll(() => {
    wide = true;
    inline = false;
});

const company: ClientDetails = {
    type: 'business',
    name: 'Pracownia Mebli Przykładowa Sp. z o.o.',
    tax_id: '7311982045',
    business_type: null,
    email: null,
    phone: null,
    address: 'ul. Stolarska 12',
    postal_code: '90-410',
    city: 'Łódź',
    country: 'PL',
    cooperation_type: 'retainer',
    month_close_type: 'maintenance',
    infakt_id: '10000123',
    currency: 'PLN',
    segment: null,
    hourly_rate: null,
    report_mode: 'report',
    include_in_month_close: false,
    ssh_config: null,
    maintenance_invoice_description: null,
    report_baseline_markdown: null,
    archived: false,
};

const person: ClientDetails = {
    ...company,
    type: 'individual',
    name: 'Jan Nowak',
    tax_id: null,
    phone: '+48 100 000 106',
    email: 'jan.nowak@example.com',
    cooperation_type: 'one_off',
    month_close_type: null,
};

const header = {
    id: 3,
    name: 'Jan Nowak',
    short_name: 'Jan Nowak',
    billing_mode: 'order' as const,
    is_archived: false,
    is_pinned: false,
    open_tasks_count: 2,
    live_agent_minutes: null,
};

const textOf = (html: string) => html.replace(/<[^>]+>/g, '');
const noop = () => undefined;

describe('the Dane forms on a phone', () => {
    test('Nowy klient is a sheet with Dodaj, every field of the client form, Nazwa first, Polska and PLN by default (573:15240)', () => {
        const html = renderToStaticMarkup(<ClientDetailsDialog open onOpenChange={noop} />);
        const labels = [...html.matchAll(/<label[^>]*for="client-([a-z-]+)"/g)].map(([, id]) => id);

        expect(html).toMatch(/<button[^>]*>Anuluj<\/button><h2[^>]*>Nowy klient<\/h2><button type="submit"[^>]*>Dodaj<\/button>/);
        expect(labels).toEqual([
            'name',
            'type',
            'tax-id',
            'business-type',
            'email',
            'phone',
            'address',
            'postal-code',
            'city',
            'country',
            'currency',
            'segment',
            'cooperation',
            'notes',
        ]);
        expect(textOf(html)).toContain('Polska');
        expect(textOf(html)).toContain('PLN - złoty');
        expect(html).not.toContain('client-hourly-rate');
    });

    test('Edytuj dane firmy is a Dane firmy sheet with Anuluj, the title and Zapisz in its header and no footer (347:5096)', () => {
        const html = renderToStaticMarkup(<ClientDetailsDialog clientId={3} details={company} open onOpenChange={noop} />);

        expect(html).toContain('data-test="sheet-content"');
        expect(html).toMatch(/^<div[^>]*><form noValidate="">/);
        expect(html).toMatch(
            /<div data-action="save"><button[^>]*>Anuluj<\/button><h2[^>]*>Dane firmy<\/h2><button type="submit"[^>]*>Zapisz<\/button><\/div>/,
        );
        expect(html.match(/type="submit"/g)?.length).toBe(1);
        expect(html).toContain('value="Pracownia Mebli Przykładowa Sp. z o.o."');
    });

    test("a private person's sheet is titled Dane", () => {
        const html = renderToStaticMarkup(<ClientDetailsDialog clientId={3} details={person} open onOpenChange={noop} />);

        expect(html).toContain('>Dane</h2>');
        // Imię i nazwisko on phones, Nazwa from 768 px; a company's form reads Nazwa alone.
        expect(html).toMatch(/<label[^>]*for="client-name"[^>]*><span[^>]*>Nazwa<\/span><span[^>]*>Imię i nazwisko<\/span><\/label>/);
        expect(renderToStaticMarkup(<ClientDetailsDialog clientId={3} details={company} open onOpenChange={noop} />)).toMatch(
            /<label[^>]*for="client-name"[^>]*>Nazwa<\/label>/,
        );
    });

    test('Nowy kontakt opens the contact form as a sheet with Dodaj in the header', () => {
        const html = renderToStaticMarkup(<ContactDialog clientId={3} open onOpenChange={noop} />);

        expect(html).toContain('data-test="sheet-content"');
        expect(html).toMatch(/>Nowy kontakt<\/h2><button type="submit"[^>]*>Dodaj<\/button>/);
    });

    test('from 768 px both stay the dialog with its footer', () => {
        wide = true;
        const details = renderToStaticMarkup(<ClientDetailsDialog clientId={3} details={company} open onOpenChange={noop} />);
        const contact = renderToStaticMarkup(<ContactDialog clientId={3} open onOpenChange={noop} />);
        wide = false;

        expect(details).toContain('data-test="dialog-content"');
        expect(details).toMatch(/>Anuluj<\/button><button type="submit"[^>]*>Zapisz<\/button>/);
        expect(contact).toMatch(/>Anuluj<\/button><button type="submit"[^>]*>Dodaj kontakt<\/button>/);
        expect(details).not.toContain('data-action="save"');
        expect(details).toContain('Edytuj dane firmy');
        expect(contact).toContain('Dodaj kontakt');
    });
});

describe('Dane for a private person on a phone (347:5178)', () => {
    const html = renderToStaticMarkup(<DetailsTab client={header} details={person} contacts={[]} dialog={null} onDialogChange={noop} />);
    const text = textOf(html);

    test('reads Osoba prywatna with Imię i nazwisko on phones, Nazwa from 768 px, and no NIP', () => {
        expect(text).toContain('Osoba prywatna');
        expect(html).toMatch(/<span[^>]*>Nazwa<\/span><span[^>]*>Imię i nazwisko<\/span>/);
        expect(text).not.toContain('NIP');
    });

    test('Kontakt keeps the phone and e-mail as tel: and mailto: links', () => {
        expect(text).toContain('Kontakt');
        expect(html).toContain('href="tel:+48 100 000 106"');
        expect(html).toContain('href="mailto:jan.nowak@example.com"');
    });

    test('a company lists its contacts as rows to their records on phones, beside the desktop list', () => {
        const contacts = [{ id: 11, name: 'Marek Dąbek', email: 'marek@meble-przykladowe.test', phone: null, position: null }];
        const markup = renderToStaticMarkup(<DetailsTab client={header} details={company} contacts={contacts} dialog={null} onDialogChange={noop} />);

        expect(markup).toContain('href="mailto:marek@meble-przykladowe.test"');
        expect(markup).toMatch(/<a[^>]*href="\/contacts\/11\/edit"[^>]*>.*Marek Dąbek.*marek@meble-przykladowe\.test/);
        expect(textOf(markup)).toContain('Kontakty · 1');
        expect(textOf(markup)).toContain('731-198-20-45');
    });
});

describe('the Dane ••• menu (347:5037)', () => {
    test('Edytuj dane firmy then Nowy kontakt, only in the phone menu', () => {
        expect(detailsActionOrder(true)).toEqual(['details', 'contact']);
        expect(detailsActionOrder(false)).toEqual([]);
    });
});

describe('Kontakty: the same contact form edits a contact (477:8940, 477:9101)', () => {
    const ewa = {
        id: 5,
        first_name: 'Ewa',
        last_name: 'Dąbek',
        emails: ['ewa@meble-przykladowe.test', 'biuro@meble-przykladowe.test'],
        phone: '+48 100 000 103',
        address: null,
        city: 'Łódź',
        region: null,
        country: null,
        postal_code: null,
        position: 'Właścicielka',
        phone_secondary: null,
        notes: 'Woli telefon',
        deleted_at: null,
        client_id: 7,
    };
    const clients = [{ id: 7, name: 'Pracownia Mebli' }];

    test('from 768 px: the Klient select, the folded address and notes, and Usuń kontakt before Anuluj and Zapisz', () => {
        wide = true;
        const html = renderToStaticMarkup(<ContactDialog open onOpenChange={noop} clients={clients} contact={ewa} onRemove={noop} />);
        wide = false;

        expect(html).toContain('Edytuj kontakt');
        expect(html).toMatch(/<label[^>]*for="contact-client"[^>]*>Klient<\/label>/);
        expect(html).toContain('value="ewa@meble-przykladowe.test"');
        expect(html).toContain('Adres i notatki');
        expect(html).toContain('Łódź · 1 notatka');
        expect(html).toMatch(/>Usuń kontakt<\/button><button[^>]*>Anuluj<\/button><button type="submit"[^>]*>Zapisz<\/button>/);
    });

    test('on a phone it is a save sheet with Usuń kontakt at the end of the fields', () => {
        const html = renderToStaticMarkup(<ContactDialog open onOpenChange={noop} clients={clients} contact={ewa} onRemove={noop} />);

        expect(html).toContain('data-test="sheet-content"');
        expect(html).toMatch(/>Edytuj kontakt<\/h2><button type="submit"[^>]*>Zapisz<\/button>/);
        expect(html.lastIndexOf('Usuń kontakt')).toBeGreaterThan(html.indexOf('Adres i notatki'));
    });

    test('every address the contact holds is editable, the first stays first and none is sent twice', () => {
        expect(emailList(' ewa@meble-przykladowe.test ', 'biuro@meble-przykladowe.test, EWA@meble-przykladowe.test;  ')).toEqual([
            'ewa@meble-przykladowe.test',
            'biuro@meble-przykladowe.test',
        ]);
        expect(emailList('', 'biuro@meble-przykladowe.test')).toEqual(['biuro@meble-przykladowe.test']);
        expect(emailList('', '')).toEqual([]);
    });

    test('Klient Dane keeps its fixed client: no Klient field and no delete', () => {
        const html = renderToStaticMarkup(<ContactDialog clientId={3} open onOpenChange={noop} />);

        expect(html).not.toContain('for="contact-client"');
        expect(html).not.toContain('Usuń kontakt');
        expect(html).not.toContain('Adres i notatki');
    });
});

describe('what the client form sends', () => {
    const draft = {
        type: 'business' as const,
        name: 'Pracownia',
        tax_id: '',
        address: '',
        postal_code: '',
        city: '',
        country: 'PL',
        email: '',
        phone: '+48 1',
        cooperation_type: 'retainer',
        business_type: '',
        currency: 'PLN',
        segment: '',
        hourly_rate: '165',
        notes: 'z formularza',
    };

    test('editing a client that is not hourly sends no rate, so a stored rate survives a phone change', async () => {
        const { clientPayload } = await import('./client-details-dialog');

        expect(clientPayload(draft, false)).not.toHaveProperty('hourly_rate');
        expect(clientPayload({ ...draft, cooperation_type: 'hourly' }, false)).toHaveProperty('hourly_rate', '165');
    });

    test('notes go along on create only', async () => {
        const { clientPayload } = await import('./client-details-dialog');

        expect(clientPayload(draft, true)).toHaveProperty('notes', 'z formularza');
        expect(clientPayload(draft, false)).not.toHaveProperty('notes');
    });
});
