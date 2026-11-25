import { router, type InertiaLinkProps } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';

type Href = NonNullable<InertiaLinkProps['href']>;
export type PrefetchTrigger = 'hover' | 'click';

const HOVER = '(hover: hover)';

/** Whether a link fetches its page ahead, and on what: only plain GET pages of this app. A page that counts its opening reports it once shown. */
export function prefetchOn(href: Href, trigger: PrefetchTrigger): PrefetchTrigger | false {
    const { url, method } = typeof href === 'string' ? { url: href, method: 'get' } : href;

    return method !== 'get' || !url.startsWith('/') || url.startsWith('//') ? false : trigger;
}

function subscribe(onChange: () => void) {
    const query = window.matchMedia(HOVER);
    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
}

/**
 * Hover where the pointer can hover; on touch the press, since a tap's emulated hover would race its own visit with a second request.
 * A link keeps one answer for its life: Inertia's Link breaks its hook order when `prefetch` turns from false to a trigger.
 */
export function useLinkPrefetch(): (href: Href) => PrefetchTrigger | false {
    const hover = useSyncExternalStore(
        subscribe,
        () => window.matchMedia(HOVER).matches,
        () => true,
    );

    return (href) => prefetchOn(href, hover ? 'hover' : 'click');
}

/**
 * A prefetched page is a copy from before: a write starting and any real visit finishing (a poll, the reload after a write) drop every copy.
 * A prefetch of the page a visit is already fetching is refused.
 */
export function forgetPrefetchedPages(): void {
    let fetching: string | null = null;
    router.on('before', (event) => {
        const { visit } = event.detail;
        // Inertia's Link keeps its hover timer past a click, so the hover can come due while the click's own request is out.
        if (visit.prefetch) return visit.url.href !== fetching;
        if (visit.method !== 'get') router.flushAll();
    });
    router.on('start', (event) => {
        const { visit } = event.detail;
        if (!visit.prefetch && visit.method === 'get') fetching = visit.url.href;
    });
    router.on('finish', (event) => {
        const { visit } = event.detail;
        // Inertia clears `prefetch` before a prefetch finishes; its Purpose header still tells it apart.
        if (visit.headers.Purpose === 'prefetch') return;
        if (visit.url.href === fetching) fetching = null;
        router.flushAll();
    });
}
