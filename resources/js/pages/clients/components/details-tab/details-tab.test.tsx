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
    report_baseline_markdown: null,
    notes: null,
    archived: false,
};

const dialogs = { dialog: null, onDialogChange: () => undefined };

describe('DetailsTab', () => {
    test('missing company data and close settings read as muted placeholders, not blank rows', () => {
        const html = renderToStaticMarkup(<DetailsTab client={header} details={empty} contacts={[]} {...dialogs} />);

        // NIP, address, cooperation, phone and email in Firma; SSH and the invoice line in Zamknięcie miesiąca
        expect(html.match(/data-tone="muted"[^>]*>Nie podano</g)?.length).toBe(7);
        expect(html).toMatch(/data-tone="muted"[^>]*>Bez powiązania</);
        expect(html).toContain('Nie ma jeszcze kontaktów.');
    });

    test("a company's phone, email and business type show in Firma once filled", () => {
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

    test('every tile action announces the dialog it opens: Firma, Kontakty, Projekty, Zamknięcie miesiąca, Zakres bazowy and Dokumenty', () => {
        const html = renderToStaticMarkup(<DetailsTab client={header} details={empty} contacts={[]} {...dialogs} />);

        expect(html.match(/aria-haspopup="dialog"/g)?.length).toBe(6);
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

    test('projects list what stands behind each one and open its page; Firma says how many have a board', () => {
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
        const text = html.replace(/<[^>]+>/g, '');

        expect(text).toContain('Trello1 z 2 projektów połączony');
        expect(text).toContain('Strona WWWTrello · w zamknięciu · 1 repozytoriumOtwórz');
        expect(text).toContain('Galeriaprywatny · bez repozytoriumOtwórz');
        expect(html).toContain('href="/clients/3/projects/4"');
        expect(html).not.toContain('/edit#');
    });

    test('only an owner can archive or delete the client; an archived client can only be restored', () => {
        const member = renderToStaticMarkup(<DetailsTab client={header} details={empty} contacts={[]} {...dialogs} />);
        const owner = renderToStaticMarkup(<DetailsTab client={header} details={empty} contacts={[]} owner {...dialogs} />);
        const archived = renderToStaticMarkup(<DetailsTab client={header} details={{ ...empty, archived: true }} contacts={[]} owner {...dialogs} />);

        expect(member).not.toContain('Archiwizuj');
        expect(member).not.toContain('Usuń klienta');
        expect(owner).toMatch(/Archiwizuj klienta<\/span><button[^>]*>Archiwizuj<\/button>/);
        expect(owner).toMatch(/Usuń klienta<\/span><button[^>]*>Usuń<\/button>/);
        expect(archived).toMatch(/Przywróć klienta<\/span><button[^>]*>Przywróć<\/button>/);
        expect(archived).not.toContain('Usuń klienta');
    });

    test('an hourly rate and a segment show in Firma, and the close settings in their own section', () => {
        const html = renderToStaticMarkup(
            <DetailsTab
                client={header}
                details={{
                    ...empty,
                    hourly_rate: 165,
                    segment: 'smb',
                    month_close_type: 'maintenance',
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
    test('the block is headed Osoba prywatna and has no NIP row', () => {
        const html = renderToStaticMarkup(
            <DetailsTab client={header} details={{ ...empty, type: 'individual', name: 'Jan Przykładowy' }} contacts={[]} {...dialogs} />,
        );

        expect(html).toContain('Osoba prywatna');
        expect(html).not.toContain('Firma');
        expect(html).not.toContain('NIP');
    });

    test("the person's own phone and e-mail read under Kontakt, editable, with contact records kept apart", () => {
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
        expect(html.match(/aria-haspopup="dialog"/g)?.length).toBe(7);
        expect(text).toContain('Kontakty0');
    });

    test('a company keeps its Firma heading and NIP row, with phone and email inside Firma', () => {
        const html = renderToStaticMarkup(<DetailsTab client={header} details={empty} contacts={[]} {...dialogs} />);

        expect(html).toContain('Firma');
        expect(html).toContain('NIP');
        expect(html).toContain('>Telefon<');
        expect(html).not.toContain('>Kontakt<');
    });
});
