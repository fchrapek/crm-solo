import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../lang/pl.json';
import { SkippedColumns } from './integrations-tab';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const rows = (html: string) =>
    [...html.matchAll(/<label[^>]*><input type="checkbox"([^>]*)\/>([^<]*)<\/label>/g)].map(
        ([, attrs, name]) => `${name}${attrs.includes('checked') ? ' ✓' : ''}`,
    );

describe('SkippedColumns', () => {
    test('lists every seen column, ticks the skipped ones whatever their case, and keeps a saved name no board has', () => {
        const html = renderToStaticMarkup(
            <SkippedColumns seen={['Doing', 'Raporty']} value={['raporty ', 'Podsumowania']} onChange={() => {}} disabled={false} />,
        );

        expect(rows(html)).toEqual(['Doing', 'Raporty ✓', 'Podsumowania ✓']);
        expect(html).toContain('Kolumny pomijane');
    });
});
