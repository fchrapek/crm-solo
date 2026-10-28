import { router, usePoll } from '@inertiajs/react';
import { useEffect, useEffectEvent, useRef } from 'react';

const EVERY_MS = 30_000;

type ReloadOptions = NonNullable<Parameters<typeof router.reload>[0]>;

/** Reloads `only` (every prop when omitted) every 30 s and when the tab comes back, never while `paused`. */
export function useLiveReload(only?: string[], paused = false): void {
    const running = useRef<symbol | null>(null);
    // The poll and the focus reload share one slot, so an older response can never land after a newer one.
    const options = (): ReloadOptions => {
        const visit = Symbol();

        return {
            ...(only ? { only } : {}),
            onCancelToken: ({ cancel }) => (running.current !== null ? cancel() : (running.current = visit)),
            onFinish: () => {
                if (running.current === visit) running.current = null;
            },
        };
    };
    const poll = usePoll(EVERY_MS, options, { autoStart: false });
    const polling = useEffectEvent((on: boolean) => (on ? poll.start() : poll.stop()));
    const reload = useEffectEvent(() => router.reload(options()));

    useEffect(() => {
        if (paused) return;

        polling(true);
        const refresh = () => {
            if (document.visibilityState === 'visible') reload();
        };
        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', refresh);

        return () => {
            polling(false);
            window.removeEventListener('focus', refresh);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, [paused]);
}
