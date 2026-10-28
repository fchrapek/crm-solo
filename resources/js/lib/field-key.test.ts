import { describe, expect, it } from 'bun:test';
import { freeKey, keyFromLabel } from './field-key';

describe('keyFromLabel', () => {
    it('spells Polish letters plainly and joins words with _', () => {
        expect(keyFromLabel('Termin wizji')).toBe('termin_wizji');
        expect(keyFromLabel('Źródło')).toBe('zrodlo');
        expect(keyFromLabel('Ogrzewanie: obecne!')).toBe('ogrzewanie_obecne');
    });

    it('starts with a letter', () => {
        expect(keyFromLabel('2 piętro')).toBe('pietro');
    });
});

describe('freeKey', () => {
    it('numbers a key another one already took', () => {
        expect(freeKey('Priorytet', [], 'pole')).toBe('priorytet');
        expect(freeKey('Priorytet', ['priorytet', 'priorytet_2'], 'pole')).toBe('priorytet_3');
    });

    it('falls back when the label leaves no letters', () => {
        expect(freeKey('123', [], 'opcja')).toBe('opcja');
        expect(freeKey('!!', ['opcja'], 'opcja')).toBe('opcja_2');
    });
});
