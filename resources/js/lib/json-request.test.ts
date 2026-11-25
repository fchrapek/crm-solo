import { router } from '@inertiajs/react';
import { afterEach, describe, expect, spyOn, test } from 'bun:test';

import { jsonRequest, JsonRequestError } from './json-request';

const realFetch = globalThis.fetch;
const realDocument = globalThis.document;

afterEach(() => {
    globalThis.fetch = realFetch;
    globalThis.document = realDocument;
});

describe('jsonRequest', () => {
    test('a refused request carries the validation errors by field, so a caller can offer another action', async () => {
        globalThis.document = { cookie: '' } as Document;
        globalThis.fetch = (async () =>
            new Response(JSON.stringify({ message: 'Archive it instead.', errors: { archive_instead: ['Archive it instead.'] } }), {
                status: 422,
            })) as unknown as typeof fetch;

        const error = await jsonRequest('/tasks/1', 'DELETE').catch((caught: unknown) => caught);

        expect(error).toBeInstanceOf(JsonRequestError);
        expect((error as JsonRequestError).message).toBe('Archive it instead.');
        expect((error as JsonRequestError).errors).toEqual({ archive_instead: ['Archive it instead.'] });
    });

    test('a write drops the prefetched pages once it is answered, a read keeps them', async () => {
        const flushAll = spyOn(router, 'flushAll').mockImplementation(() => {});
        globalThis.document = { cookie: '' } as Document;
        globalThis.fetch = (async () => new Response('{}', { status: 200 })) as unknown as typeof fetch;

        await jsonRequest('/search?q=acme', 'GET');
        expect(flushAll).not.toHaveBeenCalled();
        await jsonRequest('/time-entries/9/stop', 'POST');
        expect(flushAll).toHaveBeenCalledTimes(1);

        globalThis.fetch = (async () => new Response('{}', { status: 422 })) as unknown as typeof fetch;
        await jsonRequest('/tasks/1', 'DELETE').catch(() => undefined);
        expect(flushAll).toHaveBeenCalledTimes(2);
        flushAll.mockRestore();
    });

    test('a write that changes no page keeps the prefetched pages, and an empty answer resolves', async () => {
        const flushAll = spyOn(router, 'flushAll').mockImplementation(() => {});
        globalThis.document = { cookie: '' } as Document;
        globalThis.fetch = (async () => new Response(null, { status: 204 })) as unknown as typeof fetch;

        expect(await jsonRequest('/clients/7/view', 'POST', undefined, undefined, { keepPrefetched: true })).toBeUndefined();
        expect(flushAll).not.toHaveBeenCalled();
        flushAll.mockRestore();
    });
});
