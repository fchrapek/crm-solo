import { describe, expect, test } from 'bun:test';

import { watchedSettled, type SummaryEmail } from './summary-email-dialog';

const email = (status: SummaryEmail['status'], attempt: number, lost = false) => ({ status, attempt, lost }) as SummaryEmail;

describe('watchedSettled', () => {
    test('the attempt the dialog started reloads the page once it has an outcome, even one seen on the first read', () => {
        expect(watchedSettled(1, email('test_sent', 1))).toBe(true);
        expect(watchedSettled(2, email('sent', 2))).toBe(true);
        expect(watchedSettled(1, email('failed', 1))).toBe(true);
        expect(watchedSettled(1, email('queued', 1, true))).toBe(true);
    });

    test('a send still going, an older attempt or nothing watched does not', () => {
        expect(watchedSettled(1, email('queued', 1))).toBe(false);
        expect(watchedSettled(2, email('test_sent', 1))).toBe(false);
        expect(watchedSettled(null, email('sent', 3))).toBe(false);
        expect(watchedSettled(1, null)).toBe(false);
    });
});
