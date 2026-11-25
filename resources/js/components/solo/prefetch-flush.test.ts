import { afterAll, beforeEach, expect, mock, test } from 'bun:test';

// Inertia's real router over a fake transport: each request waits until the test answers it.
const requests = new Map<string, () => void>();
const page = (url: string) => JSON.stringify({ component: 'contacts/index', props: { errors: {} }, url, version: null });
const transport = Object.assign(
    (config: { url: string }) =>
        new Promise((resolve) => {
            const url = new URL(config.url).pathname;
            requests.set(url, () => resolve({ status: 200, headers: { 'x-inertia': 'true' }, data: page(url) }));
        }),
    { isCancel: () => false, create: () => transport, defaults: { headers: { common: {} } } },
);
const axios = await import('axios');
mock.module('axios', () => ({ ...axios, default: transport }));

const storage = new Map<string, string>();
const events = new EventTarget();
const browser = ['window', 'document', 'history'] as const;
const before = Object.fromEntries(browser.map((name) => [name, Object.getOwnPropertyDescriptor(globalThis, name)]));
Object.assign(globalThis, {
    window: {
        location: new URL('https://crm-solo.test/'),
        history: { state: null, replaceState() {}, pushState() {} },
        navigator: { userAgent: 'bun' },
        addEventListener() {},
        removeEventListener() {},
        scrollTo() {},
        requestAnimationFrame: (callback: () => void) => setTimeout(callback, 0),
        setTimeout,
        clearTimeout,
        sessionStorage: {
            getItem: (key: string) => storage.get(key) ?? null,
            setItem: (key: string, value: string) => storage.set(key, value),
            removeItem: (key: string) => storage.delete(key),
        },
        crypto: globalThis.crypto,
    },
    document: Object.assign(events, { querySelectorAll: () => [], documentElement: {}, body: {} }),
    history: { state: null, replaceState() {}, pushState() {} },
});

// A copy of Inertia's router of its own, so the other test files keep theirs; the wrapper under test is handed this one.
const { router } = await import(`${new URL('../../../../node_modules/@inertiajs/core/dist/index.esm.js', import.meta.url).pathname}?prefetch-flush`);
const react = await import('@inertiajs/react');
mock.module('@inertiajs/react', () => ({ ...react, router }));
const isolated = './link-prefetch?prefetch-flush';
const { forgetPrefetchedPages } = (await import(isolated)) as typeof import('./link-prefetch');

router.init({
    initialPage: { component: 'today/index', props: { errors: {} }, url: '/', version: null, encryptHistory: false, clearHistory: false } as never,
    resolveComponent: () => ({}),
    swapComponent: async () => {},
} as never);
forgetPrefetchedPages();

const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

afterAll(() => {
    // The other test files render without a DOM.
    for (const name of browser) {
        const descriptor = before[name];
        if (descriptor) Object.defineProperty(globalThis, name, descriptor);
        else delete (globalThis as Record<string, unknown>)[name];
    }
    mock.module('axios', () => axios);
    mock.module('@inertiajs/react', () => react);
});

beforeEach(() => {
    router.flushAll();
    requests.clear();
});

test('a hover prefetch that answers keeps its page for the click', async () => {
    router.prefetch('/contacts', {}, { cacheFor: '1m' });
    await settle();
    requests.get('/contacts')?.();
    await settle();

    expect(router.getCached('/contacts')).toBeTruthy();
});

test('a prefetch that answers after a write started is never served', async () => {
    router.prefetch('/contacts', {}, { cacheFor: '1m' });
    await settle();
    expect(router.getPrefetching('/contacts')).toBeTruthy();

    // The write's own request stays out; only its start matters here.
    router.post('/day/close');
    await settle();
    requests.get('/contacts')?.();
    await settle();

    expect(router.getPrefetching('/contacts')).toBeFalsy();
    expect(router.getCached('/contacts')).toBeFalsy();
});
