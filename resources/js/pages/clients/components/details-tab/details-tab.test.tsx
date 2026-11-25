import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../../lang/pl.json';
import { fieldControl, SoloField, SoloInput } from '../../../../components/solo/field';
import { DetailsTab } from './details-tab';
import type { ClientDetails } from './types';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const header = {
    id: 3,
    name: 'Pracownia Testowa',
    short_name: 'Pracownia Testowa',
    billing_mode: 'retainer' as const,
    is_archived: false,
    is_pinned: false,
    open_tasks_count: 0,
    live_agent_minutes: null,
};

const empty: ClientDetails = {
    type: 'business',
    name: 'Pracownia Testowa',
    tax_id: null,
    business_type: null,
    email: null,
    phone: null,
    address: null,
    postal_code: null,
    city: null,
    region: null,
    country: null,
    cooperation_type: null,
    month_close_type: null,
    infakt_id: null,
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

const inClose: ClientDetails = { ...empty, month_close_type: 'maintenance', modules: { month_close: true, report_baseline: false } };

const dialogs = { dialog: null, onDialogChange: () => undefined };

describe('DetailsTab', () => {
    test('missing company data and close settings read as muted placeholders, not blank rows', () => {
        const html = renderToStaticMarkup(<DetailsTab client={header} details={empty} contacts={[]} {...dialogs} />);

        // NIP, address, cooperation, phone and email in Company
        expect(html.match(/data-tone="muted"[^>]*>Nie podano</g)?.length).toBe(5);
        expect(html).toMatch(/data-tone="muted"[^>]*>Bez powiązania</);
        expect(html).toContain('Nie ma jeszcze kontaktów.');
    });

    test("a company's phone, email and business type show in Company once filled", () => {
        const html = renderToStaticMarkup(
            <DetailsTab
                client={header}
                details={{ ...empty, phone: '+48 100 100 200', email: 'biuro@example.com', business_type: 'Fotowoltaika' }}
                contacts={[]}
                {...dialogs}
            />,
        );

        expect(html).toContain('href="tel:+48 100 100 200"');
        expect(html).toContain('href="mailto:biuro@example.com"');
        expect(html).toContain('Rodzaj działalności');
        expect(html).toContain('Fotowoltaika');
    });

    test('a linked client shows its Infakt id in ink, an unlinked one reads muted', () => {
        const html = renderToStaticMarkup(<DetailsTab client={header} details={{ ...empty, infakt_id: '10000123' }} contacts={[]} {...dialogs} />);

        expect(html).toMatch(/Infakt<\/dt><dd[^>]*>klient 10000123</);
        expect(html).not.toMatch(/data-tone="[^"]*"[^>]*>klient 10000123</);
    });

    test('every tile action announces the dialog it opens: Company, Contacts, Month close and Documents; Baseline scope moved to Billing', () => {
        const html = renderToStaticMarkup(<DetailsTab client={header} details={inClose} contacts={[]} {...dialogs} />);

        expect(html.match(/aria-haspopup="dialog"/g)?.length).toBe(4);
        expect(html).not.toContain('Zakres bazowy raportu');
    });

    test('Month close shows only while the module is on and the client has a close type', () => {
        const tile = (details: ClientDetails) =>
            renderToStaticMarkup(<DetailsTab client={header} details={details} contacts={[]} {...dialogs} />).includes('Zamknięcie miesiąca');

        expect(tile(inClose)).toBe(true);
        expect(tile({ ...inClose, modules: { month_close: false, report_baseline: false } })).toBe(false);
        expect(tile({ ...inClose, month_close_type: null })).toBe(false);
    });

    test('the side holds the stage on top, then the month close and documents; the projects tile and the field history leave Details', () => {
        const html = renderToStaticMarkup(
            <DetailsTab client={header} details={inClose} contacts={[]} stage={{ stage: 'active', since: null, retainer_from: null }} {...dialogs} />,
        );
        const lead = html.slice(html.indexOf('data-place="lead"'), html.indexOf('data-place="main"'));
        const aside = html.slice(html.indexOf('<aside'), html.indexOf('</aside>'));

        expect(lead).toContain('Etap relacji');
        expect(aside).toContain('data-place="side"');
        expect(aside.indexOf('Zamknięcie miesiąca')).toBeLessThan(aside.indexOf('Dokumenty'));
        expect(html).not.toContain('Projekty i repozytoria');
        expect(html).not.toContain('Historia pól');
    });

    test('the DOM follows the phone column, so Tab goes stage, company, contacts, fields, month close, documents, danger zone on every width', () => {
        const card = { assignee: null, sms_opt_out: false, fields: [], users: [] };
        const html = renderToStaticMarkup(
            <DetailsTab
                client={header}
                details={inClose}
                contacts={[]}
                card={card}
                stage={{ stage: 'active', since: null, retainer_from: null }}
                owner
                {...dialogs}
            />,
        );
        const order = ['Etap relacji', 'Firma', 'Kontakty', 'Pola klienta', 'Zamknięcie miesiąca', 'Dokumenty', 'Strefa zagrożenia'].map((label) =>
            html.indexOf(`>${label}<`),
        );

        expect(html).toContain('data-phone-order=""');
        expect(html).not.toContain('data-reflow');
        expect(html).not.toContain('data-order');
        expect(order.every((at) => at > -1)).toBe(true);
        expect([...order].sort((a, b) => a - b)).toEqual(order);
    });

    test('the relationship stage has its own tile with the day it began and the way to change it', () => {
        const html = renderToStaticMarkup(
            <DetailsTab
                client={header}
                details={empty}
                contacts={[]}
                stage={{ stage: 'paused', since: '2026-06-13', retainer_from: '2026-06-02' }}
                {...dialogs}
            />,
        );
        const text = html.replace(/<[^>]+>/g, '');

        expect(text).toContain('Etap relacjiZmień etap');
        expect(text).toContain('EtapWstrzymany');
        expect(text).toContain('Od13.06.2026');
        expect(text).toContain('Abonament od02.06');
    });

    test('Company says how many projects have a board', () => {
        const html = renderToStaticMarkup(
            <DetailsTab
                client={header}
                details={empty}
                contacts={[]}
                projects={[
                    { id: 4, name: 'Strona WWW', is_private: false, include_in_month_close: true, repositories: 1 },
                    { id: 5, name: 'Galeria', is_private: true, include_in_month_close: false, repositories: 0 },
                ]}
                {...dialogs}
            />,
        );

        expect(html.replace(/<[^>]+>/g, '')).toContain('Trello1 z 2 projektów połączony');
    });

    test('the danger zone closes the main column, the owner archives there and only there, an archived client is restored', () => {
        const member = renderToStaticMarkup(<DetailsTab client={header} details={empty} contacts={[]} {...dialogs} />);
        const owner = renderToStaticMarkup(<DetailsTab client={header} details={empty} contacts={[]} owner {...dialogs} />);
        const archived = renderToStaticMarkup(<DetailsTab client={header} details={{ ...empty, archived: true }} contacts={[]} owner {...dialogs} />);
        const text = (html: string) => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');

        expect(member).not.toContain('Strefa zagrożenia');
        expect(text(owner)).toContain(
            'Strefa zagrożenia Archiwizuj klienta Klient znika z list; dane, czas i raporty zostają. Możesz go przywrócić. Archiwizuj',
        );
        expect(owner.indexOf('data-place="tail"')).toBeLessThan(owner.indexOf('Strefa zagrożenia'));
        expect(owner.indexOf('</aside>')).toBeLessThan(owner.indexOf('Strefa zagrożenia'));
        expect(owner).not.toContain('Usuń');
        expect(text(archived)).toContain('Przywróć z archiwum Wraca na listy. Przywróć');
    });

    test('an hourly rate and a segment show in Company, and the close settings in their own section', () => {
        const html = renderToStaticMarkup(
            <DetailsTab
                client={header}
                details={{
                    ...inClose,
                    cooperation_type: 'hourly',
                    hourly_rate: 165,
                    segment: 'smb',
                    ssh_config: 'meble-prod',
                    include_in_month_close: true,
                }}
                contacts={[]}
                {...dialogs}
            />,
        );
        const text = html.replace(/<[^>]+>/g, '');

        expect(text).toMatch(/Stawka165,00\s?zł netto \/ h/);
        expect(text).toContain('SegmentMała / średnia firma');
        expect(text).toContain('SSHmeble-prod');
        expect(text).toContain('W zamknięciuTak, w każdym miesiącu');
    });

    test('Rate shows only for an hourly contract', () => {
        const rate = (cooperation: ClientDetails['cooperation_type']) =>
            renderToStaticMarkup(
                <DetailsTab client={header} details={{ ...empty, cooperation_type: cooperation, hourly_rate: 165 }} contacts={[]} {...dialogs} />,
            ).includes('Stawka');

        expect(rate('hourly')).toBe(true);
        expect(rate('retainer')).toBe(false);
    });

    test('Client fields links its change history to Journal', () => {
        const card = { assignee: null, sms_opt_out: false, fields: [], users: [] } as unknown as Parameters<typeof DetailsTab>[0]['card'];
        const html = renderToStaticMarkup(<DetailsTab client={header} details={empty} contacts={[]} card={card} {...dialogs} />);

        expect(html).toMatch(/href="\/clients\/3\/journal"[^>]*>Historia zmian</);
    });
});

describe('SoloField', () => {
    test('an error is announced with its field', () => {
        const html = renderToStaticMarkup(
            <SoloField label="NIP" htmlFor="client-tax-id" error="Niepoprawny NIP">
                <SoloInput {...fieldControl('client-tax-id', 'Niepoprawny NIP')} />
            </SoloField>,
        );

        expect(html).toContain('aria-invalid="true"');
        expect(html).toContain('aria-describedby="client-tax-id-error"');
        expect(html).toContain('id="client-tax-id-error"');
    });

    test('a valid field carries no error link', () => {
        const html = renderToStaticMarkup(<SoloInput {...fieldControl('client-name')} />);

        expect(html).not.toContain('aria-describedby');
        expect(html).not.toContain('aria-invalid');
    });
});

describe('DetailsTab for a private person', () => {
    test('the block is headed Individual and has no NIP row', () => {
        const html = renderToStaticMarkup(
            <DetailsTab client={header} details={{ ...empty, type: 'individual', name: 'Jan Przykładowy' }} contacts={[]} {...dialogs} />,
        );

        expect(html).toContain('Osoba prywatna');
        expect(html).not.toContain('Firma');
        expect(html).not.toContain('NIP');
    });

    test("the person's own phone and e-mail read under Contact details, editable, with contact records kept apart", () => {
        const html = renderToStaticMarkup(
            <DetailsTab
                client={header}
                details={{ ...empty, type: 'individual', name: 'Jan Przykładowy', phone: '+48 100 000 106', email: 'jan@poczta-testowa.test' }}
                contacts={[]}
                {...dialogs}
            />,
        );
        const text = html.replace(/<[^>]+>/g, '');

        expect(text).toContain('Kontakt');
        expect(text).toContain('Telefon+48 100 000 106');
        expect(text).toContain('E-mailjan@poczta-testowa.test');
        expect(html).toContain('href="tel:+48 100 000 106"');
        expect(html).toContain('href="mailto:jan@poczta-testowa.test"');
        expect(html.match(/aria-haspopup="dialog"/g)?.length).toBe(4);
        expect(text).toContain('Kontakty0');
    });

    test('a company keeps its Company heading and NIP row, with phone and email inside Company', () => {
        const html = renderToStaticMarkup(<DetailsTab client={header} details={empty} contacts={[]} {...dialogs} />);

        expect(html).toContain('Firma');
        expect(html).toContain('NIP');
        expect(html).toContain('>Telefon<');
        expect(html).not.toContain('>Kontakt<');
    });
});
