import { router } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { jsonRequest } from '@/lib/json-request';
import timeEntries from '@/routes/time-entries';

/** crypto.randomUUID needs a secure context; getRandomValues does not. */
export function requestId(): string {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/**
 * The id of a start whose outcome this page does not know yet, per task. A
 * retry reuses it, so a start that committed but lost its response is
 * returned rather than doubled. It is forgotten once a response arrives or a
 * reload shows the page as the server holds it.
 */
const unresolvedStarts = new Map<number, string>();

/**
 * Start and Stop with one request in flight at a time. A start carries a
 * request id the server honours, so a click that slips past the guard
 * still opens one timer. Failures surface as a toast, and the page reloads
 * either way so the screen shows what the server holds.
 */
export function useTimerActions() {
    const { t } = useTranslation();
    const busy = useRef(false);
    const [pending, setPending] = useState(false);

    const run = useCallback(async (send: () => Promise<unknown>, failure: string, settled?: () => void) => {
        if (busy.current) return;
        busy.current = true;
        setPending(true);
        try {
            await send();
            settled?.();
        } catch (error) {
            toast.error(failure, { description: error instanceof Error ? error.message : undefined });
        }
        router.reload({
            onSuccess: () => settled?.(),
            onFinish: () => {
                busy.current = false;
                setPending(false);
            },
        });
    }, []);

    const start = (taskId: number) => {
        const id = unresolvedStarts.get(taskId) ?? requestId();
        unresolvedStarts.set(taskId, id);
        const send = async () => {
            const entry = await jsonRequest<{ end_time: string | null }>(timeEntries.start(taskId).url, 'POST', { request_id: id });
            // The kept id belonged to a start that landed and has since been stopped: this click is a new timer,
            // whose id is kept in turn until its own outcome is known.
            if (entry.end_time !== null) {
                const replacement = requestId();
                unresolvedStarts.set(taskId, replacement);
                await jsonRequest(timeEntries.start(taskId).url, 'POST', { request_id: replacement });
            }
        };
        return run(
            send,
            t('The timer did not start.'),
            () => unresolvedStarts.delete(taskId),
        );
    };

    return {
        pending,
        start,
        stop: (entryId: number) => run(() => jsonRequest(timeEntries.stop(entryId).url, 'POST'), t('The timer did not stop.')),
    };
}

