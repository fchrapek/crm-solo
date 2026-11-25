import { router } from '@inertiajs/react';

export function readXsrfToken(): string {
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.split('=')[1]) : '';
}

/** A non-2xx answer: the server's message, its validation errors by field when it sent any, and the status. */
export class JsonRequestError extends Error {
    constructor(
        message: string,
        readonly errors: Record<string, string[]> = {},
        readonly status = 0,
    ) {
        super(message);
    }
}

/**
 * Sends a JSON request to a Laravel endpoint with the session's XSRF token; throws on a non-2xx status.
 * A write drops the prefetched pages unless `keepPrefetched` says it changes nothing a page shows.
 */
export async function jsonRequest<T = unknown>(
    url: string,
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    body?: unknown,
    signal?: AbortSignal,
    { keepPrefetched = false }: { keepPrefetched?: boolean } = {},
): Promise<T> {
    const response = await fetch(url, {
        method,
        signal,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': readXsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    }).finally(() => {
        if (method !== 'GET' && !keepPrefetched) router.flushAll();
    });

    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new JsonRequestError(data.message ?? `Request failed with ${response.status}`, data.errors, response.status);
    }

    return (response.status === 204 ? undefined : response.json()) as Promise<T>;
}
