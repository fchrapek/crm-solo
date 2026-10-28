import { hoursText as hoursNumber, hoursTerms, localeOf } from '@/lib/hours-text';
import type { TFunction } from 'i18next';

export { hoursNumber, hoursTerms, localeOf };

export interface ClientListHours {
    /** The row's active month: the oldest one still owing a close, else the running month. */
    period: string;
    behind: boolean;
    has_retainer: boolean;
    used: number;
    available: number;
    remaining: number;
    logged: number;
}

export type Balance = { text: string; tone: 'over' | 'under' | 'none' };

/** A retainer reads against what the client can draw on; anything else is every hour logged this month. */
export function hoursLine(hours: ClientListHours, locale: string, t: TFunction): string {
    return hours.has_retainer
        ? t('{{used}} of {{available}} h', { used: hoursNumber(hours.used, locale), available: hoursNumber(hours.available, locale) })
        : `${hoursNumber(hours.logged, locale)} h`;
}

export function balanceOf(hours: ClientListHours, locale: string): Balance {
    if (!hours.has_retainer) {
        return { text: '·', tone: 'none' };
    }

    return { text: `${hoursNumber(hours.remaining, locale, true)} h`, tone: hours.remaining < 0 ? 'over' : 'under' };
}

/** The pagination range with Figma's en dash, kept out of the lang files. */
export function rangeOf(from: number, to: number): string {
    return `${from}\u2013${to}`;
}
