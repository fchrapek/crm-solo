import { describe, expect, mock, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';

const inertia = { ...(await import('@inertiajs/react')) };
mock.module('@inertiajs/react', () => ({ ...inertia, Head: () => null }));
const { default: ErrorPage } = await import('./error');

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

describe('the error page', () => {
    test('a guest gets the code as the poster, one line and a link to log in, with no masthead', () => {
        const html = renderToStaticMarkup(<ErrorPage status={404} />);

        expect(html).toContain('data-day="paper"');
        expect(html).toMatch(/<h1[^>]*>404<\/h1>/);
        expect(html).toContain('Tej strony nie ma albo zmieniła adres.');
        expect(html).toMatch(/href="\/login"[^>]*>Zaloguj się →<\/a>/);
        expect(html).not.toContain('<header');
    });

    test('an unknown code reads as a server error', () => {
        expect(renderToStaticMarkup(<ErrorPage status={502} />)).toContain('Coś poszło nie tak po naszej stronie.');
    });
});
