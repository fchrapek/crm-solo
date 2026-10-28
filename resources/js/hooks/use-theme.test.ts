import { afterAll, afterEach, describe, expect, test } from 'bun:test';
import { storedTheme } from './use-theme';

const store = new Map<string, string>();
const previous = globalThis.localStorage;
globalThis.localStorage = {
    getItem: (key: string) => store.get(key) ?? null,
    setItem: (key: string, value: string) => void store.set(key, value),
    removeItem: (key: string) => void store.delete(key),
    clear: () => store.clear(),
    key: () => null,
    length: 0,
} as Storage;

afterEach(() => store.clear());
afterAll(() => {
    globalThis.localStorage = previous;
});

describe('the stored theme', () => {
    test('Boring SaaS is kept once chosen', () => {
        store.set('theme', 'boring-saas');

        expect(storedTheme()).toBe('boring-saas');
    });

    test('a retired theme or nothing saved reads as Paper', () => {
        for (const saved of ['ink', 'brutal', 'bauhaus']) {
            store.set('theme', saved);
            expect(storedTheme()).toBe('paper');
        }

        store.clear();
        expect(storedTheme()).toBe('paper');
    });
});
