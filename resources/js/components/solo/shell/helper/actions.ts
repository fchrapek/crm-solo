import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

const ACTION_EVENT = 'solo:helper-action';

/** The query a helper link carries when the dialog it opens lives on another tab. */
export const ACTION_PARAM = 'helper';

/** What a helper answer can ask the page to open; the page that owns the dialog listens with useHelperAction. */
export type HelperEvent = 'log-time' | 'retainer-terms' | 'client-report' | 'journal-note';

export function requestHelperAction(name: HelperEvent): void {
    window.dispatchEvent(new CustomEvent<HelperEvent>(ACTION_EVENT, { detail: name }));
}

/** A link to the tab that owns the dialog, which opens it once on arrival. */
export function helperActionHref(href: string, name: HelperEvent): string {
    return `${href}${href.includes('?') ? '&' : '?'}${ACTION_PARAM}=${name}`;
}

/** The URL without the helper's query, or null when it does not ask for `name`. */
export function withoutHelperAction(href: string, name: HelperEvent): string | null {
    const url = new URL(href, 'http://localhost');
    if (url.searchParams.get(ACTION_PARAM) !== name) return null;
    url.searchParams.delete(ACTION_PARAM);

    return `${url.pathname}${url.search}${url.hash}`;
}

/**
 * Runs `handler` when the helper asks for `name`: at once on this page, or once on arrival from a helperActionHref
 * link, whose query is then dropped so a reload does not ask again. Pass a stable handler (a state setter or a
 * useCallback).
 */
export function useHelperAction(name: HelperEvent, handler: () => void): void {
    const arrived = useRef(false);

    useEffect(() => {
        const listen = (event: Event) => {
            if ((event as CustomEvent<HelperEvent>).detail === name) handler();
        };
        window.addEventListener(ACTION_EVENT, listen);

        return () => window.removeEventListener(ACTION_EVENT, listen);
    }, [name, handler]);

    useEffect(() => {
        if (arrived.current) return;
        arrived.current = true;
        const rest = withoutHelperAction(window.location.href, name);
        if (rest === null) return;
        router.replace({ url: rest, preserveState: true, preserveScroll: true });
        handler();
    }, [name, handler]);
}
