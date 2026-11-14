import type { TFunction } from 'i18next';
import { hoursNumber } from '../client-list/format';
import type { BilledFigures, BilledTaskRow } from './types';

/** The marker's text on a month billed off its real time; null when it bills exactly its real time. */
export function billedTip(billed: Pick<BilledFigures, 'real' | 'billed' | 'note'>, locale: string, t: TFunction): string | null {
    if (Math.abs(billed.billed - billed.real) < 0.005) {
        return null;
    }
    const lead = t('Your figure. Real time {{hours}} h.', { hours: hoursNumber(billed.real, locale) });

    return withNote(lead, billed.note, t);
}

/** The marker's text on a task with a stated figure; null when it bills its real time. */
export function taskTip(task: BilledTaskRow, locale: string, t: TFunction): string | null {
    if (task.stated === null) {
        return null;
    }
    const lead = t('Your figure: {{billed}} h. Real time {{real}} h.', { billed: hoursNumber(task.stated, locale), real: hoursNumber(task.real, locale) });

    return withNote(lead, task.note, t);
}

function withNote(lead: string, note: string | null, t: TFunction): string {
    return note !== null && note !== '' ? `${lead} ${t('Note: {{note}}', { note })}` : lead;
}

/** "27,3 · 26,0 h": real, then billed, as the ledger rows and task rows show them. */
export function bothValues(billed: Pick<BilledFigures, 'real' | 'billed'>, locale: string): string {
    return `${hoursNumber(billed.real, locale)} · ${hoursNumber(billed.billed, locale)} h`;
}

/** A typed figure: a comma or a dot, spaces, a real minus sign; NaN when it is not a number, null when empty. */
export function parseHours(text: string): number | null {
    const clean = text.replace(/\s/g, '').replace(',', '.').replace('−', '-');

    return clean === '' ? null : Number(clean);
}

/** A figure for a field: one decimal, two when needed, no grouping. */
export function fieldHours(value: number, locale: string): string {
    return (Math.round(value * 100) / 100)
        .toLocaleString(locale, { minimumFractionDigits: 1, maximumFractionDigits: 2, useGrouping: false })
        .replace('-', '−');
}
