import { afterAll, afterEach, describe, expect, test } from 'bun:test';
import { initializeTheme } from './use-theme';

const store = new Map<string, string>();
const attributes = new Map<string, string>();
const previousStorage = globalThis.localStorage;
const previousDocument = globalThis.document;
globalThis.localStorage = {
    getItem: (key: string) => store.get(key) ?? null,
    setItem: (key: string, value: string) => void store.set(key, value),
    removeItem: (key: string) => void store.delete(key),
    clear: () => store.clear(),
    key: () => null,
    length: 0,
} as Storage;
globalThis.document = {
    documentElement: {
        setAttribute: (name: string, value: string) => void attributes.set(name, value),
        removeAttribute: (name: string) => void attributes.delete(name),
    },
} as unknown as Document;

afterEach(() => {
    store.clear();
    attributes.clear();
});
afterAll(() => {
    globalThis.localStorage = previousStorage;
    globalThis.document = previousDocument;
});

describe('the theme on load', () => {
    test('a browser that saved a retired theme, or nothing, gets Paper', () => {
        for (const saved of ['boring-saas', 'ink', 'brutal', 'bauhaus', null]) {
            store.clear();
            attributes.clear();
            if (saved !== null) store.set('theme', saved);

            expect(initializeTheme).not.toThrow();
            expect(attributes.get('data-theme')).toBe('paper');
        }
    });

    test('the saved accent is applied, and an unknown one reads as the default', () => {
        store.set('accent', 'teal');
        initializeTheme();
        expect(attributes.get('data-accent')).toBe('teal');

        store.set('accent', 'neon');
        initializeTheme();
        expect(attributes.get('data-accent')).toBe('indigo');
    });
});
