import { router } from '@inertiajs/react';

interface RefusedVisit {
    status: number;
    config?: { url?: string; method?: string };
}

/** Where the browser goes when Cloudflare Access refuses a visit for lack of a session, or null for any other response. */
export function accessLoginTarget(response: RefusedVisit, current: string): string | null {
    if (response.status !== 401) {
        return null;
    }

    const url = response.config?.url;
    const method = (response.config?.method ?? 'get').toLowerCase();

    return method === 'get' && url ? new URL(url, current).href : current;
}

// Access answers a background request with 401 instead of its login redirect, so a full page load lets the browser follow it.
export function loadAccessLoginOnRefusal(): void {
    router.on('invalid', (event) => {
        const target = accessLoginTarget(event.detail.response, window.location.href);
        if (target === null) {
            return;
        }

        event.preventDefault();
        window.location.assign(target);
    });
}
