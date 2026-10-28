import { describe, expect, test } from 'bun:test';
import { type CooperationCounts, cooperationOptions, listQuery, PHONE_STAGES } from './filters';

const counts: CooperationCounts = { all: 10, retainer: 6, hourly: 3, project: 0, one_off: 0, none: 1 };

describe('cooperationOptions', () => {
    test('Wszyscy leads and equals the sum of the rows under it, the untyped clients included', () => {
        const options = cooperationOptions(counts, null);

        expect(options.map(({ value, count }) => [value, count])).toEqual([
            [null, 10],
            ['retainer', 6],
            ['hourly', 3],
            ['none', 1],
        ]);
        expect(options.slice(1).reduce((sum, { count }) => sum + count, 0)).toBe(options[0].count);
        expect(options.filter(({ active }) => active).map(({ value }) => value)).toEqual([null]);
    });

    test('an empty type stays listed while it is the filter, so the active row never vanishes', () => {
        const options = cooperationOptions(counts, 'project');

        expect(options.find(({ value }) => value === 'project')).toEqual({ value: 'project', label: 'Project', count: 0, active: true });
        expect(options[0].active).toBe(false);
    });

    test('every row and segment lands on its own query string, and the defaults stay out of the URL', () => {
        expect(cooperationOptions(counts, null).map(({ value }) => listQuery({ stage: 'active', cooperation_type: value, search: '' }))).toEqual([
            {},
            { cooperation_type: 'retainer' },
            { cooperation_type: 'hourly' },
            { cooperation_type: 'none' },
        ]);
        expect(PHONE_STAGES.map((stage) => listQuery({ stage, cooperation_type: null, search: 'kawa' }))).toEqual([
            { search: 'kawa' },
            { stage: 'paused', search: 'kawa' },
            { stage: 'churned', search: 'kawa' },
        ]);
    });
});
