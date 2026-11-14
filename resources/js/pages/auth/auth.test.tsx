import { describe, expect, mock, test } from 'bun:test';
import { readFileSync } from 'node:fs';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import en from '../../../../lang/en.json';
import pl from '../../../../lang/pl.json';

await i18next.use(initReactI18next).init({
    lng: 'pl',
    resources: { pl: { translation: pl }, en: { translation: en } },
    interpolation: { escapeValue: false },
});

const inertia = await import('@inertiajs/react');
mock.module('@inertiajs/react', () => ({ ...inertia, Head: () => null, usePage: () => ({ props: {} }) }));

const { default: Login } = await import('./login');
const { default: Waitlist } = await import('./czesc');
const { formDog, takesTheCursor } = await import('./frame');
const text = (html: string) => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');

describe('the sign-in and waitlist screens', () => {
    test('login is the cobalt page: the wordmark, the claim and the form, with no date', () => {
        const html = renderToStaticMarkup(<Login />);

        expect(html).toContain('data-tone="cobalt"');
        expect(html).toContain('aria-label="CRM Solo"');
        expect(html).toMatch(/<h1 class="sr-only">Zaloguj się<\/h1>/);
        expect(text(html)).toContain('CRM dla freelancerów i wszystkoogarniaczy');
        expect(text(html)).toContain('Zapamiętaj mnie');
        expect(text(html)).not.toMatch(/poniedziałek|wtorek|środa|czwartek|piątek|sobota|niedziela/i);
    });

    test('the waitlist is the same layout in vermilion, with its headline in the poster and the real fields', () => {
        const html = renderToStaticMarkup(<Waitlist joined={false} />);

        expect(html).toContain('data-tone="vermilion"');
        expect(html).toMatch(/<h1[^>]*>Jeszcze chwilka<\/h1>/);
        expect(text(html)).toContain('Imię (opcjonalnie)');
        expect(html).toMatch(/<button[^>]*type="submit"[^>]*>Wpisz mnie na listę<\/button>/);
    });

    test('neither screen puts the cursor in a field on open, so the dog first follows the pointer', () => {
        expect(renderToStaticMarkup(<Login />)).not.toMatch(/autofocus/i);
        expect(renderToStaticMarkup(<Waitlist joined={false} />)).not.toMatch(/autofocus/i);
    });

    test('a field with the cursor turns the dog to the form with its glasses up, and an error pose still wins', () => {
        expect(formDog('cool', false, false)).toEqual({ pose: 'cool', peek: false });
        expect(formDog('cool', false, true)).toEqual({ pose: 'look-down-left', peek: true });
        expect(formDog('wow', false, true)).toEqual({ pose: 'wow', peek: true });
        expect(formDog('cool', true, false)).toEqual({ pose: 'cool', peek: true });
    });

    test('a text field takes the dog\'s eyes, a checkbox and anything else do not', () => {
        const element = (tagName: string, type = '') => ({ tagName, type }) as unknown as EventTarget;

        expect(takesTheCursor(element('INPUT', 'email'))).toBe(true);
        expect(takesTheCursor(element('INPUT', 'password'))).toBe(true);
        expect(takesTheCursor(element('INPUT', 'checkbox'))).toBe(false);
        expect(takesTheCursor(element('BUTTON', 'submit'))).toBe(false);
        expect(takesTheCursor(null)).toBe(false);
    });

    test('on a phone the dog\'s margins scale with the dog, so a landscape phone never pulls the form over the wordmark', () => {
        const css = readFileSync(`${import.meta.dir}/login.module.css`, 'utf8');
        const phone = css.slice(0, css.indexOf('@media')).match(/\.dog \{([^}]*)\}/)?.[1] ?? '';

        expect(phone).toMatch(/margin-block: calc\(var\(--auth-dog-size\) \* -[\d.]+\) calc\(var\(--auth-dog-size\) \* -[\d.]+\);/);
        expect(phone).not.toMatch(/margin-block:[^;]*%/);
    });

    test('after joining the form gives way to the confirmation and a way to log in', () => {
        const html = renderToStaticMarkup(<Waitlist joined />);

        expect(html).toMatch(/<div[^>]*aria-live="polite"[^>]*><h1[^>]*>Jesteś na liście<\/h1><p[^>]*>Pies zapamiętał/);
        expect(text(html)).toContain('Pies zapamiętał. Napiszę, kiedy CRM Solo ruszy.');
        expect(html).not.toContain('type="email"');
    });
});
