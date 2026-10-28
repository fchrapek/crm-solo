import type { ClosePreview } from './types';

/** "z raportu (szkic)" / "z raportu" / "z wpisów": where the frozen used hours come from. */
export function sourceKey(preview: Pick<ClosePreview, 'source' | 'report_status'>): string {
    if (preview.source === 'entries') {
        return 'close_source_entries';
    }

    return preview.report_status === 'draft' ? 'close_source_draft' : 'close_source_report';
}

/** Live logged time is information only; it shows when a report supplies the figure and the two differ. */
export function entriesDiffer(preview: Pick<ClosePreview, 'source' | 'used' | 'entries'>): boolean {
    return preview.source === 'report' && Math.abs(preview.entries - preview.used) >= 0.005;
}
