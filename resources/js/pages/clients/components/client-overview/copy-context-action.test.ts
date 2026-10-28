import { afterEach, describe, expect, mock, test } from 'bun:test';

import { ClipboardUnavailableError, copyErrorMessage, copyFromUrl, SessionExpiredError } from './copy-context-action';

const realFetch = globalThis.fetch;
const realItem = (globalThis as { ClipboardItem?: unknown }).ClipboardItem;

class FakeClipboardItem {
    constructor(public items: Record<string, Promise<Blob>>) {}
}

function respond(body: string, init: ResponseInit & { redirected?: boolean } = {}) {
    globalThis.fetch = mock(async () => {
        const response = new Response(body, { status: 200, headers: { 'content-type': 'application/json' }, ...init });
        if (init.redirected) {
            Object.defineProperty(response, 'redirected', { value: true });
        }

        return response;
    }) as unknown as typeof fetch;
}

const brief = JSON.stringify({ context: '{"id": 7}' });

afterEach(() => {
    globalThis.fetch = realFetch;
    (globalThis as { ClipboardItem?: unknown }).ClipboardItem = realItem;
});

describe('copyFromUrl', () => {
    test('a pending clipboard item gets the brief when the browser takes one', async () => {
        (globalThis as { ClipboardItem?: unknown }).ClipboardItem = FakeClipboardItem;
        respond(brief);
        let written = '';
        const clipboard = {
            write: mock(async ([item]: FakeClipboardItem[]) => {
                written = await (await item.items['text/plain']).text();
            }),
            writeText: mock(async () => undefined),
        };

        await copyFromUrl('/clients/7/agent-context', clipboard as never);

        expect(written).toBe('{"id": 7}');
        expect(clipboard.writeText).not.toHaveBeenCalled();
    });

    test('a refused clipboard item falls back to plain text', async () => {
        (globalThis as { ClipboardItem?: unknown }).ClipboardItem = FakeClipboardItem;
        respond(brief);
        const clipboard = {
            write: mock(async () => {
                throw new DOMException('denied', 'NotAllowedError');
            }),
            writeText: mock(async () => undefined),
        };

        await copyFromUrl('/clients/7/agent-context', clipboard);

        expect(clipboard.writeText).toHaveBeenCalledWith('{"id": 7}');
    });

    test('a denied write rejects instead of leaving an unhandled rejection', async () => {
        (globalThis as { ClipboardItem?: unknown }).ClipboardItem = FakeClipboardItem;
        respond(brief);
        const denied = async () => {
            throw new DOMException('denied', 'NotAllowedError');
        };

        await expect(copyFromUrl('/clients/7/agent-context', { write: denied, writeText: denied })).rejects.toThrow('denied');
    });

    test('a failed request rejects with its own error and writes nothing', async () => {
        (globalThis as { ClipboardItem?: unknown }).ClipboardItem = FakeClipboardItem;
        respond('{}', { status: 500 });
        const clipboard = {
            write: mock(async () => {
                throw new TypeError('item rejected');
            }),
            writeText: mock(async () => undefined),
        };

        await expect(copyFromUrl('/clients/7/agent-context', clipboard)).rejects.toThrow('agent context 500');
        expect(clipboard.writeText).not.toHaveBeenCalled();
    });

    test('an expired session is told apart from a failure', async () => {
        respond('<html>login</html>', { headers: { 'content-type': 'text/html' }, redirected: true });
        await expect(copyFromUrl('/clients/7/agent-context', { writeText: async () => undefined })).rejects.toBeInstanceOf(SessionExpiredError);

        respond('{"message":"Unauthenticated."}', { status: 401 });
        await expect(copyFromUrl('/clients/7/agent-context', { writeText: async () => undefined })).rejects.toBeInstanceOf(SessionExpiredError);

        expect(copyErrorMessage(new SessionExpiredError())).toBe('Your session has expired. Sign in again to copy the context.');
        expect(copyErrorMessage(new Error('x'))).toBe('Could not copy the agent context.');
    });

    test('no clipboard means no request', async () => {
        respond(brief);

        await expect(copyFromUrl('/clients/7/agent-context', undefined)).rejects.toBeInstanceOf(ClipboardUnavailableError);
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });
});
