function readXsrfToken(): string {
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.split('=')[1]) : '';
}

/** Sends a JSON request to a Laravel endpoint with the session's XSRF token; throws on a non-2xx status. */
export async function jsonRequest<T = unknown>(url: string, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', body?: unknown): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': readXsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(data.message ?? `Request failed with ${response.status}`);
    }

    return response.json() as Promise<T>;
}
