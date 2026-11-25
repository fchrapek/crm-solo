import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';
import { AccountTile } from './components/account-tile';
import { AppearanceTiles } from './components/appearance-tiles';
import { ProfileTile } from './components/profile-tile';
import { TasksTile } from './components/tasks-tile';
import Profile from './index';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const context = { t: (key: string, options?: Record<string, unknown>) => i18next.t(key, options), url: '/profile', locale: 'pl-PL' };
const switches = (html: string) =>
    [...html.matchAll(/<li[^>]*><span[^>]*>([^<]+)<\/span><button[^>]*role="switch"[^>]*aria-checked="(true|false)"/g)].map(([, label, on]) => [
        label,
        on,
    ]);

describe('Mój profil', () => {
    test('the place is Mój profil, with no pills', () => {
        expect(Profile.place({} as never, context)).toEqual({ tokens: [{ label: 'Mój profil' }] });
    });

    test('Wygląd offers the language and the mode, and no accent; what it does sits in its (i)', () => {
        const html = renderToStaticMarkup(<AppearanceTiles />);

        expect(html).toContain('aria-label="Język"');
        expect(html).toContain('aria-label="Tryb"');
        expect(html).not.toContain('Akcent');
        expect(html).toContain('zapisane w tej przeglądarce');
        expect(html).toContain('aria-label="Więcej informacji"');
        expect(html).not.toContain('Podgląd');
    });

    test('Pomocnik stands apart from the browser caption: two switch rows, saved to the account', () => {
        const html = renderToStaticMarkup(<AppearanceTiles helper={{ helper_desktop: true, helper_phone: false }} />);
        const tile = html.slice(html.lastIndexOf('<section', html.indexOf('>Pomocnik<')));

        expect(tile).not.toContain('zapisane w tej przeglądarce');
        expect(switches(tile)).toEqual([
            ['Pomocnik na komputerze', 'true'],
            ['Pomocnik na telefonie', 'false'],
        ]);
        expect(renderToStaticMarkup(<AppearanceTiles />)).not.toContain('Pomocnik');
    });

    test('Zadania is one tile; a member has no billed time switch', () => {
        const owner = renderToStaticMarkup(<TasksTile settings={{ warn_full_day: true, show_billed_on_tasks: false }} />);
        const member = renderToStaticMarkup(<TasksTile settings={{ warn_full_day: false, show_billed_on_tasks: null }} />);

        expect(owner.match(/<section/g)).toHaveLength(1);
        expect(switches(owner)).toEqual([
            ['Ostrzegaj, gdy dzień ma więcej niż 5 zadań', 'true'],
            ['Pokazuj czas rozliczany przy zadaniach', 'false'],
        ]);
        expect(switches(member)).toEqual([['Ostrzegaj, gdy dzień ma więcej niż 5 zadań', 'false']]);
    });

    test('Profil labels the person by Imię i nazwisko, leaving Nazwa to the account', () => {
        const html = renderToStaticMarkup(<ProfileTile me={{ id: 1, first_name: 'Ewa', last_name: 'Owner', email: 'ewa@example.test' }} owner />);

        expect(html).toContain('Imię i nazwisko');
        expect(html).not.toContain('>Nazwa<');
        expect(renderToStaticMarkup(<AccountTile account={{ name: 'Pracownia', owners: [] }} owner />)).toContain('>Nazwa<');
    });

    test('Konto names the account and my role; an owner gets the way to the users, a member reads the owner instead', () => {
        const owner = renderToStaticMarkup(<AccountTile account={{ name: 'Pracownia', owners: [] }} owner />);
        const member = renderToStaticMarkup(<AccountTile account={{ name: 'Pracownia', owners: ['Ewa Owner'] }} owner={false} />);

        expect(owner).toContain('Pracownia');
        expect(owner).toContain('właściciel');
        expect(owner).toContain('href="/settings/general"');
        expect(owner).toContain('>Użytkownicy<');
        expect(member).toContain('użytkownik');
        expect(member).toContain('Ewa Owner');
        expect(member).not.toContain('href=');
    });
});
