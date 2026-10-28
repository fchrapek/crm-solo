import { describe, expect, test } from 'bun:test';

import type { Retainer } from './retainer-chip';
import { draftOf, errorsNeedMore, invalidNumbers, retainerPayload } from './retainer-dialog';

const stored: Retainer = {
    id: 4,
    project_id: 12,
    label: 'Opieka',
    description: 'Pakiet godzin',
    monthly_hours: 30,
    monthly_fee: 1800,
    overage_hourly_rate: 165,
    rollover_cap_hours: 60,
    invoice_group: 2,
    vat_symbol: 'zw',
    is_active: true,
    sort_order: 3,
    currency: 'EUR',
    effective_from: '2026-06-02',
    effective_to: null,
    notes: null,
};

describe('retainerPayload', () => {
    test('fields the form cannot show are never sent, so the server keeps what is stored', () => {
        const payload = retainerPayload({ ...draftOf(stored, 'PLN'), monthly_fee: '1 900' });

        expect(payload).not.toHaveProperty('project_id');
        expect(payload).not.toHaveProperty('overage_hourly_rate');
        expect(payload).not.toHaveProperty('is_active');
        expect(payload).not.toHaveProperty('sort_order');
        expect(payload.vat_symbol).toBe('zw');
        expect(payload.currency).toBe('EUR');
        expect(payload.monthly_fee).toBe(1900);
        expect(payload.effective_from).toBe('2026-06-02');
    });

    test('a new position reads a decimal comma and sends an empty cap as cleared', () => {
        const payload = retainerPayload({ ...draftOf(null, 'PLN'), monthly_hours: '7,5', rollover_cap_hours: '' });

        expect(payload.monthly_hours).toBe(7.5);
        expect(payload.rollover_cap_hours).toBeNull();
        expect(payload.vat_symbol).toBe('23');
        expect(payload.currency).toBe('PLN');
    });
});

describe('invalidNumbers', () => {
    const base = draftOf(stored, 'PLN');

    test('text that is not a number is caught before it could clear the field', () => {
        expect(invalidNumbers({ ...base, monthly_fee: 'abc' })).toEqual(['monthly_fee']);
        expect(invalidNumbers({ ...base, monthly_fee: '1200zł' })).toEqual(['monthly_fee']);
        expect(invalidNumbers({ ...base, rollover_cap_hours: 'do 60 h' })).toEqual(['rollover_cap_hours']);
        expect(invalidNumbers({ ...base, invoice_group: '2a' })).toEqual(['invoice_group']);
    });

    test('an intentionally empty optional field is fine, an empty package is not', () => {
        expect(invalidNumbers({ ...base, monthly_fee: '', rollover_cap_hours: '', invoice_group: '' })).toEqual([]);
        expect(invalidNumbers({ ...base, monthly_hours: '' })).toEqual(['monthly_hours']);
        expect(invalidNumbers({ ...base, monthly_fee: '1 800,50', monthly_hours: '7,5' })).toEqual([]);
    });
});

describe('errorsNeedMore', () => {
    test('an error in a folded field opens the disclosure, one in the six up front does not', () => {
        expect(errorsNeedMore({ invoice_group: 'x' })).toBe(true);
        expect(errorsNeedMore({ notes: 'x' })).toBe(true);
        expect(errorsNeedMore({ monthly_fee: 'x', effective_from: 'x' })).toBe(false);
    });
});
