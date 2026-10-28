import { describe, expect, test } from 'bun:test';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const root = join(import.meta.dir, '..');

function sources(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) {
            return name === 'routes' || name === 'actions' ? [] : sources(path);
        }

        return path.endsWith('.tsx') && !path.endsWith('.test.tsx') ? [path] : [];
    });
}

/** Every `<Link ...>` opening tag that points into a client's record page. */
function recordLinks(source: string): string[] {
    return [...source.matchAll(/<(?:Link|BodyLink|SectionLink|SoloButtonLink)\b[^>]*?>/gs)].map((m) => m[0]).filter((tag) => tag.includes('clients.show('));
}

describe('links into a client record', () => {
    // Opening the record page is what records a visit; a prefetched response would be shown without that request.
    test('never prefetch', () => {
        const offenders = sources(root).flatMap((path) =>
            recordLinks(readFileSync(path, 'utf8'))
                .filter((tag) => /\bprefetch\b/.test(tag))
                .map(() => relative(root, path)),
        );

        expect(offenders).toEqual([]);
    });

    test('the guard sees a prefetching record link', () => {
        expect(recordLinks("<Link href={clients.show({ client: 1, tab: 'przeglad' })} prefetch>x</Link>")).toHaveLength(1);
    });
});
