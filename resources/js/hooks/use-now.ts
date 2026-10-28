import { useCallback, useSyncExternalStore } from 'react';

/** The browser clock in steps of `every` ms; null while the server renders and hydrates, so the markup matches. */
export function useNow(every: number): number | null {
    const subscribe = useCallback(
        (onTick: () => void) => {
            const id = window.setInterval(onTick, every);

            return () => window.clearInterval(id);
        },
        [every],
    );
    const step = useSyncExternalStore(
        subscribe,
        () => Math.floor(Date.now() / every),
        () => null,
    );

    return step === null ? null : step * every;
}
