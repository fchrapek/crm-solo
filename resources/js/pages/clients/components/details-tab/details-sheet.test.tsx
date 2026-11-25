import { afterAll, describe, expect, mock, spyOn, test } from 'bun:test';
import i18next from 'i18next';
import { createContext, type ReactNode, useContext } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import * as soloWide from '@/components/solo/use-solo-wide';
import * as dialog from '@/components/ui/dialog';
import * as sheet from '@/components/ui/sheet';
import pl from '../../../../../../lang/pl.json';
import type { ClientDetails } from './types';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

// Radix portals render nothing on the server, so while these tests run the open content renders in place.
let wide = false;
spyOn(soloWide, 'useSoloWide').mockImplementation(() => wide);
const Open = createContext(false);
const RealSheet = sheet.Sheet;
spyOn(sheet, 'Sheet').mockImplementation((props) => (
    <Open.Provider value={props.open ?? false}>
        <RealSheet {...props} />
    </Open.Provider>
));
spyOn(sheet, 'SheetContent').mockImplementation(function SheetContent(props) {
    return <>{useContext(Open) ? <div data-test="sheet-content">{props.children as ReactNode}</div> : null}</>;
});
const RealDialog = dialog.Dialog;
spyOn(dialog, 'Dialog').mockImplementation((props) => (
    <Open.Provider value={props.open ?? false}>
        <RealDialog {...props} />
    </Open.Provider>
));
spyOn(dialog, 'DialogContent').mockImplementation(function DialogContent(props) {
    return <>{useContext(Open) ? <div data-test="dialog-content">{props.children as ReactNode}</div> : null}</>;
});

const { ClientDetailsDialog } = await import('./client-details-dialog');
const { ContactDialog, emailList } = await import('./contact-dialog');
const { DetailsTab, moduleActions } = await import('./details-tab');

afterAll(() => mock.restore());

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
    region: null,
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
    notes: null,
    archived: false,
    has_report_baseline: false,
    modules: { month_close: false, report_baseline: false },
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
const folded = /<div id="[^"]+-body"[^>]* data-closed="true" inert="">[\s\S]*?<\/div><\/div><\/section>/g;
const unfolded = (html: string) => html.replace(folded, '');

describe('the Dane forms on a phone', () => {
    test('New client is a sheet with Add: the type and Name first, then Contact details and Billing, the company data and the notes folded (1395:36518)', () => {
        const html = renderToStaticMarkup(<ClientDetailsDialog open onOpenChange={noop} />);
        const labels = [...unfolded(html).matchAll(/<label[^>]*for="client-([a-z-]+)"/g)].map(([, id]) => id);
        const text = textOf(html);

        expect(html).toMatch(/<button[^>]*>Anuluj<\/button><h2[^>]*>Nowy klient<\/h2><button type="submit"[^>]*>Dodaj<\/button>/);
        expect(text.indexOf('Firma')).toBeLessThan(text.indexOf('Nazwa'));
        expect(labels).toEqual(['name', 'cooperation', 'email', 'phone', 'currency']);
        expect(html).toMatch(/aria-describedby="client-name-hint"/);
        expect(text).toContain('Wymagana. Resztę możesz uzupełnić później.');
        expect(text).toContain('KontaktE-mail');
        expect(text).toContain('RozliczeniaWaluta');
        expect(text).toContain('PLN (złoty)');
        expect(text).toContain('Stawka godzinowa pojawia się tylko przy rozliczeniu godzinowym.');
        expect(text).toContain('Dane firmy i adresNIP, adres, województwo, kraj');
        expect(text).toContain('Notatkipusto');
        expect(html.match(/aria-expanded="false"/g)?.length).toBeGreaterThanOrEqual(2);
        expect(text).not.toContain(' - brak - ');
        expect(html).not.toContain('client-hourly-rate');
    });

    test('a refused field in a folded group is in the form, inert until its group opens, so it can take the focus', () => {
        const html = renderToStaticMarkup(<ClientDetailsDialog open onOpenChange={noop} />);
        const foldedFields = [...html.matchAll(folded)].map(([body]) => body).join('');

        expect(foldedFields).toContain('id="client-tax-id"');
        expect(foldedFields).toContain('id="client-notes"');
        expect(unfolded(html)).not.toContain('id="client-tax-id"');
    });

    test('Company data opens both groups, the company data with Region beside Country and the notes, and Segment reads None when empty', () => {
        const html = renderToStaticMarkup(<ClientDetailsDialog clientId={3} details={company} open onOpenChange={noop} />);
        const labels = [...html.matchAll(/<label[^>]*for="client-([a-z-]+)"/g)].map(([, id]) => id);

        expect(labels).toEqual(['name', 'cooperation', 'email', 'phone', 'currency', 'tax-id', 'business-type', 'address', 'postal-code', 'city', 'region', 'country', 'segment', 'notes']);
        expect(textOf(html)).toContain('SegmentBrak');
        expect(html).not.toContain('client-name-hint');
    });

    test('an hourly client has the rate beside Currency with its hint, and the Currency hint goes', () => {
        const html = renderToStaticMarkup(<ClientDetailsDialog clientId={3} details={{ ...company, cooperation_type: 'hourly', hourly_rate: 150 }} open onOpenChange={noop} />);
        const text = textOf(html);

        expect(html).toContain('value="150"');
        expect(text).toContain('Tylko przy rozliczeniu godzinowym.');
        expect(text).not.toContain('Stawka godzinowa pojawia się');
        expect(text.indexOf('Stawka godzinowa (netto)')).toBeLessThan(text.indexOf('Waluta'));
    });

    test('Edit company data is a Company data sheet with Cancel, the title and Save in its header and no footer (347:5096)', () => {
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
        // Full name on phones, Name from 768 px; a company's form reads Name alone.
        expect(html).toMatch(/<label[^>]*for="client-name"[^>]*><span[^>]*>Nazwa<\/span><span[^>]*>Imię i nazwisko<\/span><\/label>/);
        expect(renderToStaticMarkup(<ClientDetailsDialog clientId={3} details={company} open onOpenChange={noop} />)).toMatch(
            /<label[^>]*for="client-name"[^>]*>Nazwa<\/label>/,
        );
    });

    test('New contact opens the contact form as a sheet with Add in the header', () => {
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

    test('reads Individual with Full name on phones, Name from 768 px, and no NIP', () => {
        expect(text).toContain('Osoba prywatna');
        expect(html).toMatch(/<span[^>]*>Nazwa<\/span><span[^>]*>Imię i nazwisko<\/span>/);
        expect(text).not.toContain('NIP');
    });

    test('Contact details keeps the phone and e-mail as tel: and mailto: links', () => {
        expect(text).toContain('Kontakt');
        expect(html).toContain('href="tel:+48 100 000 106"');
        expect(html).toContain('href="mailto:jan.nowak@example.com"');
    });

    test('a company lists its contacts as rows that open their records, with the address beside the name from 768 px', () => {
        const contacts = [{ id: 11, name: 'Marek Dąbek', email: 'marek@meble-przykladowe.test', phone: null, position: null }];
        const markup = renderToStaticMarkup(<DetailsTab client={header} details={company} contacts={contacts} dialog={null} onDialogChange={noop} />);

        expect(markup).toContain('href="mailto:marek@meble-przykladowe.test"');
        expect(markup).toMatch(/<a[^>]*href="\/contacts\/11\/edit"[^>]*>Marek Dąbek<\/a>/);
        expect(markup).toMatch(
            /<a[^>]*aria-label="Edytuj: Marek Dąbek"[^>]*href="\/contacts\/11\/edit"|<a[^>]*href="\/contacts\/11\/edit"[^>]*aria-label="Edytuj: Marek Dąbek"/,
        );
        expect(textOf(markup)).toContain('Kontakty1');
        expect(textOf(markup)).toContain('731-198-20-45');
    });
});

describe('the Dane ⋯ menu', () => {
    test('a module entry stands only while the module is on and the client does not use it yet', () => {
        const base = { month_close_type: null, has_report_baseline: false };

        expect(moduleActions({ ...base, modules: { month_close: true, report_baseline: true } })).toEqual(['month-close', 'baseline']);
        expect(moduleActions({ ...base, modules: { month_close: false, report_baseline: false } })).toEqual([]);
        expect(
            moduleActions({ month_close_type: 'maintenance', has_report_baseline: true, modules: { month_close: true, report_baseline: true } }),
        ).toEqual([]);
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

    test('from 768 px: the client select, the folded address and notes, and Delete contact before Cancel and Save', () => {
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

    test('on a phone it is a save sheet with Delete contact at the end of the fields', () => {
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

    test('the Details tab of a client keeps its fixed client: no client field and no delete', () => {
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
        region: 'mazowieckie',
    };

    test('editing a client that is not hourly sends no rate, so a stored rate survives a phone change', async () => {
        const { clientPayload } = await import('./client-details-dialog');

        expect(clientPayload(draft)).not.toHaveProperty('hourly_rate');
        expect(clientPayload({ ...draft, cooperation_type: 'hourly' })).toHaveProperty('hourly_rate', '165');
    });

    test('notes and the region go along on an edit too, so they can be changed after the client exists', async () => {
        const { clientPayload } = await import('./client-details-dialog');

        expect(clientPayload(draft)).toHaveProperty('notes', 'z formularza');
        expect(clientPayload(draft)).toHaveProperty('region', 'mazowieckie');
    });
    test('a private person sends no company fields, so a NIP typed under Company is neither sent nor refused out of sight', async () => {
        const { clientPayload } = await import('./client-details-dialog');
        const person = clientPayload({ ...draft, type: 'individual', tax_id: '123', business_type: 'JDG' });

        expect(person).not.toHaveProperty('tax_id');
        expect(person).not.toHaveProperty('business_type');
        expect(clientPayload({ ...draft, tax_id: '123' })).toHaveProperty('tax_id', '123');
    });
});

describe('a refused client form', () => {
    test('points at the first refused field in the form order, with the folded group that holds it', async () => {
        const { firstInvalid } = await import('./client-details-dialog');

        expect(firstInvalid({ postal_code: 'Kod', tax_id: 'NIP' })).toMatchObject({ id: 'client-tax-id', group: 'company' });
        expect(firstInvalid({ notes: 'Za długie', email: 'E-mail' })).toMatchObject({ id: 'client-email' });
        expect(firstInvalid({ notes: 'Za długie', email: 'E-mail' })?.group).toBeUndefined();
        expect(firstInvalid({ notes: 'Za długie' })).toMatchObject({ id: 'client-notes', group: 'notes' });
        expect(firstInvalid({})).toBeNull();
    });

    test('a refusal no field shows, such as the type, is listed at the top instead of lost', async () => {
        const { unmappedErrors } = await import('./client-details-dialog');

        expect(unmappedErrors({ type: 'Wybierz typ.', name: 'Pole wymagane.', infakt: 'Za długie.' })).toEqual(['Wybierz typ.', 'Za długie.']);
        expect(unmappedErrors({ tax_id: 'NIP' })).toEqual([]);
    });
});
