import { describe, expect, test } from 'bun:test';
import { renderToStaticMarkup } from 'react-dom/server';
import { HoursBar, hoursBarSegments } from './hours-bar';

describe('HoursBar', () => {
    test('over the pool, the pool fills and the overrun follows', () => {
        expect(hoursBarSegments(36.5, 20.5)).toEqual({ inPool: 20.5, rest: 16, over: true });
    });

    test('under the pool, the used hours fill and the free hours follow', () => {
        expect(hoursBarSegments(9.5, 14.5)).toEqual({ inPool: 9.5, rest: 5, over: false });
    });

    test('a negative pool counts as empty, so every used hour is an overrun', () => {
        expect(hoursBarSegments(3, -2)).toEqual({ inPool: 0, rest: 3, over: true });
    });

    test('an untouched empty pool still draws one free segment', () => {
        const html = renderToStaticMarkup(<HoursBar used={0} available={0} />);
        expect(html).toContain('data-state="under"');
        expect(html.match(/flex-grow/g)).toHaveLength(1);
    });
});
