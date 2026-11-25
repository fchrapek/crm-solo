import { afterEach, describe, expect, test } from 'bun:test';

import { runningMinutesToday, weekdayDate } from './dates';

const browserZone = process.env.TZ;

afterEach(() => {
    process.env.TZ = browserZone;
});

describe('the day an earlier pick came from', () => {
    test('is a weekday and a date, with the year once it is another one', () => {
        expect(weekdayDate('2026-11-19', 'pl', 2026)).toBe('czw. 19.11');
        expect(weekdayDate('2025-12-30', 'pl', 2026)).toBe('wt. 30.12.2025');
        expect(weekdayDate('2026-11-19', 'en', 2026)).toBe('Thu 11/19');
    });
});

describe("today's running minutes", () => {
    // Warsaw's 23 October begins at 22:00 UTC; the clock reads 01:00 there.
    const dayStart = '2026-10-22T22:00:00Z';
    const now = Date.parse('2026-10-22T23:00:00Z');

    test('a timer started after the display midnight counts, whatever zone the browser is in', () => {
        for (const zone of ['Europe/Warsaw', 'UTC', 'America/Los_Angeles']) {
            process.env.TZ = zone;
            expect(runningMinutesToday(dayStart, ['2026-10-22T22:30:00Z'], now)).toBe(30);
        }
    });

    test('a timer started before midnight counts on its own day, running or stopped, so the total does not drop at Stop', () => {
        expect(runningMinutesToday(dayStart, ['2026-10-22T21:30:00Z', '2026-10-22T22:45:00Z'], now)).toBe(15);
        expect(runningMinutesToday(dayStart, [], now)).toBe(0);
    });
});
