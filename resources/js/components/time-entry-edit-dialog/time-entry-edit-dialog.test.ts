import { describe, expect, test } from 'bun:test';
import { spanMinutes } from './time-entry-edit-dialog';

describe('Edytuj wpis czasu', () => {
    test('the span under Koniec is the whole minutes between the two fields', () => {
        expect(spanMinutes('2026-10-28T10:15', '2026-10-28T10:57')).toBe(42);
        expect(spanMinutes('2026-10-28T23:30', '2026-10-29T00:15')).toBe(45);
    });

    test('no span shows while the end is missing or not after the start', () => {
        expect(spanMinutes('2026-10-28T10:15', '')).toBeNull();
        expect(spanMinutes('2026-10-28T10:15', '2026-10-28T10:15')).toBeNull();
        expect(spanMinutes('2026-10-28T10:15', '2026-10-28T09:00')).toBeNull();
    });
});
