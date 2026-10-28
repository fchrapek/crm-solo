import { afterEach, describe, expect, test } from 'bun:test';

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
});
