import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../../lang/pl.json';
import { PinMenuAction } from './client-pin';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

describe('PinMenuAction', () => {
    test('the header gets the round 32 px ••• icon button, labelled, that opens a menu', () => {
        const html = renderToStaticMarkup(<PinMenuAction clientId={7} pinned={false} />);
        expect(html).toContain('aria-label="Więcej akcji"');
        expect(html).toContain('aria-haspopup="menu"');
        expect(html).toMatch(/<button[^>]*data-icon="more"[^>]*data-size="sm"/);
        expect(html).not.toContain('•••');
    });
});
