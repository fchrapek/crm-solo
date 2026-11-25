import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import pl from '../../../../../../lang/pl.json';
import { retainerFacts } from './retainer-details-dialog';
import type { Retainer } from './types';

await i18next.init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const position = (extra: Partial<Retainer> = {}): Retainer => ({
    id: 1,
    project_id: null,
    label: 'Strona WWW',
    description: 'Opieka nad stroną WWW',
    monthly_hours: 24,
    monthly_fee: 1920,
    overage_hourly_rate: 150,
    rollover_cap_hours: null,
    invoice_group: 1,
    vat_symbol: '23',
    is_active: true,
    sort_order: 0,
    currency: 'PLN',
    effective_from: '2026-07-01',
    effective_to: null,
    notes: null,
    ...extra,
});

describe('retainerFacts', () => {
    test('a position reads as its fields, without the empty ones and never the overage rate', () => {
        const facts = Object.fromEntries(retainerFacts(position(), 'pl', i18next.t));

        expect(facts).toMatchObject({ Pakiet: '24 h', 'Rollover do': 'bez limitu', 'Ponad pakiet': 'do banku, bez dopłat', Od: '01.07.2026', Do: 'bez końca', VAT: '23%' });
        expect(facts['Kwota netto / mc'].replace(/\s/g, ' ')).toBe('1 920,00 zł');
        expect(Object.keys(facts)).not.toContain('Notatki');
        expect(JSON.stringify(facts)).not.toContain('150');
    });

    test('a position without hours says so and drops the rows about the package', () => {
        const facts = Object.fromEntries(retainerFacts(position({ monthly_hours: 0 }), 'pl', i18next.t));

        expect(facts.Pakiet).toBe('bez godzin');
        expect(Object.keys(facts)).not.toContain('Rollover do');
        expect(Object.keys(facts)).not.toContain('Ponad pakiet');
    });
});
