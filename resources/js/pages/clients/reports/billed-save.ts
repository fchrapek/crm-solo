export interface EditorDraft {
    body: string;
    base: string;
    version: string;
}

/**
 * The editor after a billed time save rewrote the report's summary: an untouched editor takes the new body and version,
 * so the preview and Copy show what is stored; unsaved text is kept, and the stale notice says the report moved on.
 */
export function afterBilledSave(draft: EditorDraft, fresh: { body_markdown: string | null; version: string }): { draft: EditorDraft; kept: boolean } {
    if (draft.body !== draft.base) {
        return { draft, kept: true };
    }
    const body = fresh.body_markdown ?? '';

    return { draft: { body, base: body, version: fresh.version }, kept: false };
}
