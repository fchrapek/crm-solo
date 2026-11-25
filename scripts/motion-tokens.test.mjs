import { describe, expect, test } from 'bun:test';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { hardCodedMotion } from './motion-tokens.mjs';

const fails = (declaration) => hardCodedMotion(`.a { ${declaration}; }`).length;

describe('motion token contract', () => {
    test('durations and easings from the motion tokens pass, as do zero and none', () => {
        expect(fails('transition: transform var(--motion-base) var(--ease-standard)')).toBe(0);
        expect(fails('transition: opacity var(--motion-slow-exit) var(--ease-exit), visibility 0s')).toBe(0);
        expect(fails('transition-duration: var(--motion-fast)')).toBe(0);
        expect(fails('transition-delay: 0s, 0ms, var(--motion-slow-exit)')).toBe(0);
        expect(fails('animation: popIn var(--motion-base) var(--ease-standard)')).toBe(0);
        expect(fails('transition: none')).toBe(0);
        expect(fails('transition-property: color, background-color')).toBe(0);
        expect(fails('view-transition-name: solo-tab-bar')).toBe(0);
    });

    test('a written time, an easing of its own or another variable fails', () => {
        expect(fails('transition: transform 120ms ease')).toBe(1);
        expect(fails('transition: color .15s')).toBe(1);
        expect(fails('transition-duration: 200ms')).toBe(1);
        expect(fails('animation-duration: 1.5s')).toBe(1);
        expect(fails('animation: fadeIn var(--duration-150) var(--ease-standard)')).toBe(1);
        expect(fails('--speed: 300ms; transition: opacity var(--speed)')).toBe(1);
        expect(fails('transition: opacity var(--motion-fast) cubic-bezier(0.4, 0, 1, 1)')).toBe(1);
        expect(fails('transition-timing-function: ease-in-out')).toBe(1);
        expect(fails('transition: opacity var(--motion-fast) linear')).toBe(1);
        expect(fails('transition-delay: 50ms')).toBe(1);
    });

    test('a looping animation keeps its period, and a commented-out declaration is not one', () => {
        expect(fails('animation: spin 1s linear infinite')).toBe(0);
        expect(hardCodedMotion('/* transition: transform 120ms ease; */ .a { color: red; }')).toEqual([]);
    });

    test('every stylesheet in resources/ keeps to it, variables.css and solo.css included', () => {
        const root = new URL('../resources', import.meta.url).pathname;
        const sheets = [];
        const walk = (dir) => {
            for (const entry of readdirSync(dir)) {
                const path = join(dir, entry);
                if (statSync(path).isDirectory()) walk(path);
                else if (entry.endsWith('.css')) sheets.push(path);
            }
        };
        walk(root);

        expect(sheets.some((path) => path.endsWith('css/variables.css'))).toBe(true);
        expect(sheets.flatMap((path) => hardCodedMotion(readFileSync(path, 'utf8')).map((failure) => `${path.replace(root, '')}: ${failure}`))).toEqual([]);
    });
});
