import { afterEach, describe, expect, test } from 'bun:test';

import { fromDay, runningMinutesToday } from './dates';

const browserZone = process.env.TZ;

afterEach(() => {
    process.env.TZ = browserZone;
});

describe('where an earlier pick came from', () => {
    const t = ((key: string, options?: { date?: string }) => (options?.date ? key.replace('{{date}}', options.date) : key)) as never;

    test('names the weekday within the last week', () => {
        expect(fromDay('2026-10-22', '2026-10-28', 'pl', t)).toBe('from Thursday');
        expect(fromDay('2026-10-27', '2026-10-28', 'pl', t)).toBe('from Tuesday');
    });

    test('gives the date once a week has passed, so two Thursdays never read alike', () => {
        expect(fromDay('2026-10-21', '2026-10-28', 'pl', t)).toBe('from 21.10');
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
