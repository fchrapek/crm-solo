import { jsonRequest } from '@/lib/json-request';
import clients from '@/routes/clients';
import { useEffect, useRef } from 'react';

/** One showing of a client's page: the client, the tab and when the server read the data it shows (Unix seconds). */
export interface ClientShowing {
    clientId: number;
    tab: string;
    readAt: number;
}

/**
 * The reading a showing acknowledges: the first one, another client or another tab is an opening and acknowledges the
 * data it shows, from the server or from the prefetch cache; the same client and tab again (a reload of its props) is none.
 */
export function openingToReport(last: ClientShowing | null, shown: ClientShowing): number | null {
    return last !== null && last.clientId === shown.clientId && last.tab === shown.tab ? null : shown.readAt;
}

/** Records that the user saw the client as it was at `readAt`. Where New starts holds for the whole visit, so no prefetched page goes stale. */
export function reportClientView(clientId: number, readAt: number): Promise<void> {
    return jsonRequest<void>(clients.view(clientId).url, 'POST', { read_at: readAt }, undefined, { keepPrefetched: true }).catch(() => undefined);
}

/** Reports each opening once, with the moment its data was read; Inertia mounts the page afresh on every visit. */
export function useClientView(clientId: number, tab: string, readAt: number): void {
    const last = useRef<ClientShowing | null>(null);

    useEffect(() => {
        const shown = { clientId, tab, readAt };
        const report = openingToReport(last.current, shown);
        last.current = shown;
        if (report !== null) void reportClientView(clientId, report);
    }, [clientId, tab, readAt]);
}
