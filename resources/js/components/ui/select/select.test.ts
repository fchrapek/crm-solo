import { describe, expect, test } from 'bun:test';
import { readFileSync } from 'node:fs';

const css = (path: string) => readFileSync(`${import.meta.dir}/${path}`, 'utf8');
const radii = (source: string, selector: string) =>
    [...source.matchAll(new RegExp(`\\${selector} \\{([^}]*)\\}`, 'g'))].flatMap((rule) => [...rule[1].matchAll(/border-radius:\s*([^;]+);/g)].map((m) => m[1].trim()));

describe('the Solo select trigger', () => {
    test('has the text field corner at every width', () => {
        const field = radii(css('../../solo/field.module.css'), '.box');

        expect(new Set(field).size).toBe(1);
        expect(radii(css('select.module.css'), '.soloTrigger')).toEqual([field[0]]);
    });
});
