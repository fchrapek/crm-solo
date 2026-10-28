import { describe, expect, test } from 'bun:test';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';

const root = join(import.meta.dir, '../..');
const generated = new Set(['routes', 'actions']);

function sources(dir: string): string[] {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const path = join(dir, entry.name);
        if (entry.isDirectory()) return dir === root && generated.has(entry.name) ? [] : sources(path);
        return /\.tsx?$/.test(entry.name) && !/\.test\.tsx?$/.test(entry.name) ? [path] : [];
    });
}

const LINKS = [/clients\.edit\([^)]*\)(?:\.url)?(?:\}#(\w+))?/g, /\/clients\/\$\{[^}]+\}\/edit(?:#(\w+))?/g];

const links = sources(root).flatMap((path) => {
    const code = readFileSync(path, 'utf8');
    return LINKS.flatMap((pattern) => [...code.matchAll(pattern)].map((match) => ({ file: relative(root, path), anchor: match[1] ?? null })));
});

const pageTabs = [...readFileSync(join(import.meta.dir, 'edit.tsx'), 'utf8').matchAll(/\{ id: '(\w+)', label:/g)].map((match) => match[1]);

describe('links into the documents and settings page', () => {
    test('the scan finds the links it guards', () => {
        expect(links.length).toBeGreaterThan(3);
        expect(pageTabs).toContain('data');
    });

    test('every anchor names a section the page still has, never a retired one', () => {
        for (const { file, anchor } of links.filter((link) => link.anchor !== null)) {
            expect({ file, anchor, kept: pageTabs.includes(anchor!) }).toEqual({ file, anchor, kept: true });
            expect(anchor).not.toBe('overview');
        }
    });

    test('only the page itself links to it without naming a section', () => {
        const bare = links.filter((link) => link.anchor === null).map((link) => link.file);
        expect([...new Set(bare)]).toEqual(['pages/clients/edit.tsx']);
    });

    test('no retired section is still a tab on the page', () => {
        expect(pageTabs).not.toContain('overview');
    });
});
