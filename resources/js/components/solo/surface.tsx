import { createContext, useContext, useSyncExternalStore, type HTMLAttributes } from 'react';

export type DayState = 'paper' | 'timer' | 'done';

const SoloSurfaceContext = createContext(false);

export function useInSoloSurface(): boolean {
    return useContext(SoloSurfaceContext);
}

export function SoloSurface({ day, ...rest }: { day: DayState } & HTMLAttributes<HTMLDivElement>) {
    return (
        <SoloSurfaceContext.Provider value={true}>
            <div data-day={day} {...rest} />
        </SoloSurfaceContext.Provider>
    );
}

type PortalDocument = Pick<Document, 'createElement' | 'body'>;

let portalRoot: HTMLElement | null = null;

export function soloPortalRoot(doc: PortalDocument | undefined = typeof document === 'undefined' ? undefined : document): HTMLElement | undefined {
    if (!doc) return undefined;
    if (!portalRoot || !portalRoot.isConnected) {
        portalRoot = doc.createElement('div');
        portalRoot.dataset.day = 'paper';
        portalRoot.dataset.soloPortal = '';
        portalRoot.style.display = 'contents';
        doc.body.appendChild(portalRoot);
    }

    return portalRoot;
}

const noSubscription = () => () => {};
const noContainer = () => undefined;

/** A container, not a wrapper, so Radix Presence still animates exits; hydration reads the empty server snapshot so an overlay open on first load cannot mismatch. */
export function useSoloPortalSurface(): HTMLElement | undefined {
    const inSolo = useInSoloSurface();
    const container = useSyncExternalStore(noSubscription, soloPortalRoot, noContainer);

    return inSolo ? container : undefined;
}
