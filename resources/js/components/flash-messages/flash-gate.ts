/**
 * Whether the current props carry the flash of a visit the user just made. Kept per module, not per component,
 * because a visit that swaps layouts mounts a fresh FlashMessages after the visit already started.
 * A history step restores props without a visit (Inertia fires no `before`), so it only disarms the gate.
 */
export function createFlashGate() {
    let pending = false;

    return {
        historyRestored() {
            pending = false;
        },
        visitStarted() {
            pending = true;
        },
        take(hasFlash: boolean): boolean {
            if (!pending || !hasFlash) return false;
            pending = false;
            return true;
        },
    };
}

/** An open dialog shows its errors beside the fields, so the summary toast would only cover its actions. */
export function summarisesFormErrors(errorCount: number, dialogOpen: boolean): boolean {
    return errorCount > 0 && !dialogOpen;
}

/** One refusal reads as itself ("Ten dzień jest zamknięty..."); several keep the count. */
export function formErrorText(errors: Record<string, string | string[]>, summary: string): string {
    const messages = Object.values(errors);
    if (messages.length !== 1) return summary;
    const [only] = messages;

    return Array.isArray(only) ? (only[0] ?? summary) : only;
}

export function isDialogOpen(doc: Pick<Document, 'querySelector'> | undefined = typeof document === 'undefined' ? undefined : document): boolean {
    return doc?.querySelector('[role="dialog"][data-state="open"], [role="alertdialog"][data-state="open"]') != null;
}
