import { afterAll, describe, expect, mock, spyOn, test } from 'bun:test';
import i18next from 'i18next';
import type { ReactElement, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import * as menu from '@/components/ui/dropdown-menu';
import pl from '../../../../../lang/pl.json';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

// Radix renders a closed menu's content nowhere on the server, so while these tests run the content renders in place.
spyOn(menu, 'DropdownMenuContent').mockImplementation(({ children }) => <div data-test="menu">{children as ReactNode}</div>);
spyOn(menu, 'DropdownMenuItem').mockImplementation(({ asChild, children }) => (asChild ? (children as ReactElement) : <div role="menuitem">{children as ReactNode}</div>));
spyOn(menu, 'DropdownMenuLabel').mockImplementation(({ children }) => <div data-test="head">{children as ReactNode}</div>);
spyOn(menu, 'DropdownMenuSeparator').mockImplementation(() => <hr />);

const { AccountMenu } = await import('./rail');

afterAll(() => mock.restore());

const user = { id: 1, name: 'Filip Chrapek', first_name: 'Filip', last_name: 'Chrapek', email: 'filip@example.test', owner: 1 } as never;
const text = (html: string) => html.replace(/<hr\/>/g, '|—|').replace(/<[^>]+>/g, '|').replace(/\|+/g, '|');

describe('the rail account menu', () => {
    test('names who is signed in with the e-mail and the role, then My profile, a rule and Log out', () => {
        const html = renderToStaticMarkup(<AccountMenu user={user} />);

        expect(text(html)).toContain('|Filip Chrapek|filip@example.test|właściciel|—|Mój profil|—|Wyloguj|');
        expect(html).toContain('href="/profile"');
        expect(html).not.toContain('Wygląd');
        expect(html).toMatch(/<button type="button"><span>Wyloguj<\/span><\/button>/);
        expect(html).toContain('aria-label="Filip Chrapek"');
    });

    test('the avatar is lit only while My profile is open', () => {
        expect(renderToStaticMarkup(<AccountMenu user={user} current />)).toMatch(/<button[^>]*aria-label="Filip Chrapek"[^>]*data-current=""/);
        expect(renderToStaticMarkup(<AccountMenu user={user} />)).not.toContain('data-current');
    });

    test('a member reads the user role', () => {
        expect(text(renderToStaticMarkup(<AccountMenu user={{ ...(user as object), owner: 0 } as never} />))).toContain('|użytkownik|');
    });
});
