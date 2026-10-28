import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';

import pl from '../../../../../../lang/pl.json';
import { addressLine, cooperationLine, taxIdLine } from './format';

await i18next.init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

describe('Dane lines', () => {
    const t = (key: string) => i18next.t(key);

    test('cooperation reads how the client pays, then what the monthly close does', () => {
        expect(cooperationLine({ cooperation_type: 'retainer', month_close_type: 'maintenance' }, t)).toBe('Abonament, utrzymanie stron');
        expect(cooperationLine({ cooperation_type: 'hourly', month_close_type: null }, t)).toBe('Rozliczenie godzinowe');
        expect(cooperationLine({ cooperation_type: null, month_close_type: null }, t)).toBe('');
    });

    test('the address prints street, postcode and city, skipping what is missing', () => {
        expect(addressLine({ address: 'ul. Przykładowa', postal_code: '00-950', city: 'Warszawa' })).toBe('ul. Przykładowa, 00-950 Warszawa');
        expect(addressLine({ address: null, postal_code: null, city: 'Poznań' })).toBe('Poznań');
        expect(addressLine({ address: null, postal_code: null, city: null })).toBe('');
    });

    test('a ten digit NIP prints in groups, other ids stay as typed', () => {
        expect(taxIdLine('1234567890')).toBe('123-456-78-90');
        expect(taxIdLine('123-456-78-90')).toBe('123-456-78-90');
        expect(taxIdLine('DE123456789')).toBe('DE123456789');
        expect(taxIdLine(null)).toBe('');
    });
});
