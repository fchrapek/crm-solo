import { useSyncExternalStore } from 'react';

const WIDE = '(min-width: 768px)';

function subscribe(onChange: () => void) {
    const query = window.matchMedia(WIDE);
    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
}

/** Only for where content mounts, never for layout, which CSS owns. */
export function useSoloWide(): boolean {
    return useSyncExternalStore(subscribe, () => window.matchMedia(WIDE).matches, () => true);
}
