import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';

import { ContactTable } from './index';
import { contactsPlace } from './place';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const context = { t: (key: string, options?: Record<string, unknown>) => i18next.t(key, options), url: '/contacts', locale: 'pl-PL' };

const contact = {
    id: 1,
    client_id: 7,
    first_name: 'Anna',
    last_name: 'Testowa',
    name: 'Anna Testowa',
    emails: ['anna@example.test'],
    phones: [],
    deleted_at: null,
    client: { id: 7, name: 'Fundacja Testowa' },
} as never;

const table = (rows: never[]) => renderToStaticMarkup(<ContactTable rows={rows} onOpen={() => {}} onRestore={() => {}} />);

describe('Kontakty', () => {
    test('Contacts and Leads are sibling pills and the command bar narrows the list, keeping the deleted in view', () => {
        const place = contactsPlace({ filters: { search: '', trashed: 'with' } }, context);

        expect(place.tokens).toBeUndefined();
        expect(place.pills?.map((pill) => [pill.label, pill.active])).toEqual([
            ['Kontakty', true],
            ['Leady', false],
        ]);
        expect(place.filter?.href('anna')).toBe('/contacts?search=anna&trashed=with');
    });

    test('a row names the person as a button that opens the contact, links the client and shows the first e-mail from 768 px', () => {
        const html = table([contact]);

        expect(html).toMatch(/<button[^>]*title="Anna Testowa"[^>]*>Anna Testowa<\/button>/);
        expect(html).toMatch(/<a title="Fundacja Testowa" href="\/clients\/7\/overview"[^>]*>Fundacja Testowa<\/a>/);
        expect(html).toMatch(/data-wide=""[^>]*>anna@example\.test/);
        expect(html).not.toContain('Przywróć');
    });

    test('a contact without a client says so, and a deleted one carries its chip and Restore', () => {
        const html = table([{ ...(contact as object), client: null, deleted_at: '2026-11-01' } as never]);

        expect(html).toContain('Bez klienta');
        expect(html).toContain('usunięty');
        expect(html.match(/<button[^>]*>Przywróć<\/button>/g)).toHaveLength(2);
    });
});
