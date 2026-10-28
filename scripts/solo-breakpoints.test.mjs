import { describe, expect, test } from 'bun:test';
import { offSystemWidthQueries } from './solo-breakpoints.mjs';

const fails = (query) => offSystemWidthQueries(`${query} { a { b: c } }`).length;

describe('Solo breakpoint contract', () => {
    test('min-width at 768 px and 1100 px pass, alone or with other conditions', () => {
        expect(fails('@media (min-width: 768px)')).toBe(0);
        expect(fails('@media (min-width:1100px)')).toBe(0);
        expect(fails('@media (min-width: 768px) and (hover: hover)')).toBe(0);
        expect(fails('@media screen and (min-width: 768px)')).toBe(0);
        expect(fails('@media (prefers-color-scheme: dark), (hover: none)')).toBe(0);
    });

    test('max-width, another breakpoint, range syntax and negation all fail', () => {
        expect(fails('@media (max-width: 640px)')).toBe(1);
        expect(fails('@media (min-width: 900px)')).toBe(1);
        expect(fails('@media (width < 768px)')).toBe(1);
        expect(fails('@media (width >= 900px)')).toBe(1);
        expect(fails('@media (768px <= width)')).toBe(1);
        expect(fails('@media (400px <= width < calc(768px))')).toBe(1);
        expect(fails('@media not (min-width: 768px)')).toBe(1);
        expect(fails('@media not all and (min-width: 768px)')).toBe(1);
        expect(fails('@media (min-width: 768px) and (max-width: 1099px)')).toBe(1);
        expect(fails('@media screen and (min-device-width: 768px)')).toBe(1);
    });

    test('a commented-out query is not a query', () => {
        expect(offSystemWidthQueries('/* @media (width < 768px) { a { b: c } } */')).toEqual([]);
        expect(offSystemWidthQueries('/* old: @media (max-width: 640px) */ @media (min-width: 768px) { a { b: c } }')).toEqual([]);
    });
});
