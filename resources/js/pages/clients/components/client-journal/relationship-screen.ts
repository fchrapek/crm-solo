import { router } from '@inertiajs/react';
import { withRelationshipScreen } from './format';

/** The Dziennik URL this tab last pushed the screen from, so its back link can pop the entry instead of stacking a new one. */
let pushedFrom: string | null = null;

function here(): string {
    return `${window.location.pathname}${window.location.search}`;
}

/** A client-side visit: no request, the props stay, and the history entry makes the browser's back close the screen. */
export function openRelationshipScreen(currentUrl: string = here()): void {
    pushedFrom = withRelationshipScreen(currentUrl, false);
    router.push({ url: withRelationshipScreen(currentUrl, true), preserveScroll: false, preserveState: true });
}

export function closeRelationshipScreen(currentUrl: string = here()): void {
    const journal = withRelationshipScreen(currentUrl, false);

    // Kept after popping, so Forward then this link pops again instead of replacing.
    if (pushedFrom === journal) {
        window.history.back();

        return;
    }

    router.replace({ url: journal, preserveScroll: false, preserveState: true });
}

export interface CarriedJournal {
    journal: unknown;
    notes: unknown;
}

/** Whether Dziennik came back with older data than the screen held (back restores the snapshot from before it opened). */
export function journalChanged(carried: CarriedJournal, current: CarriedJournal): boolean {
    return JSON.stringify(carried) !== JSON.stringify(current);
}

/** Module state, since popstate remounts the page: the journal as the screen last held it, per Dziennik URL. */
let held: { journalUrl: string; data: CarriedJournal; returned: boolean } | null = null;
let listening = false;

/** A history return (Back, Forward, the back link) restores a snapshot without asking the server. */
export function markHistoryReturn(): void {
    if (held !== null) held.returned = true;
}

/** The Dziennik URL whose Relacja row gets the focus back once the screen closes. */
let focusReturn: string | null = null;

/** Any visit that asks the server brings fresh props, so the hold is dropped, and that page owns the focus. */
export function forgetHeldJournal(): void {
    held = null;
    focusReturn = null;
}

function listen(): void {
    if (!listening && typeof window !== 'undefined' && typeof document !== 'undefined') {
        window.addEventListener('popstate', markHistoryReturn);
        router.on('start', forgetHeldJournal);
        listening = true;
    }
}

export function holdJournal(journalUrl: string, data: CarriedJournal): void {
    held = { journalUrl, data, returned: false };
    focusReturn = journalUrl;
    listen();
}

/** True once for the Dziennik the screen closed back to, so its Relacja row takes the focus instead of the page root. */
export function takeFocusReturn(journalUrl: string): boolean {
    const returning = focusReturn === journalUrl;
    focusReturn = null;

    return returning;
}

export function takeHeldJournal(journalUrl: string): CarriedJournal | null {
    const data = held !== null && held.returned && held.journalUrl === journalUrl ? held.data : null;
    held = null;

    return data;
}
