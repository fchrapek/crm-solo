import { inMonth } from '@/lib/dates';
import type { TFunction } from 'i18next';
import { hoursNumber } from '../client-list/format';
import { forfeitedOf } from './format';
import type { BilledMonth } from './types';

type Figures = Pick<BilledMonth, 'opening' | 'contracted' | 'available' | 'used' | 'closing'>;

const round = (value: number) => Math.round(value * 100) / 100;
const h = (value: number, locale: string, signed = false) => `${hoursNumber(value, locale, signed)}\u00a0h`;

/** What the month draws from earlier months: the opening after the rollover cap, so the tiles add up to the balance. */
export function carriedOf(figures: Figures): number {
    return round(figures.available - (figures.contracted ?? 0));
}

/** The month on its own: its package less its billed time. */
export function monthChangeOf(figures: Figures): number {
    return round((figures.contracted ?? 0) - figures.used);
}

export function carriedCaption(figures: Figures, locale: string, t: TFunction): string {
    const forfeited = forfeitedOf(figures);
    if (forfeited > 0) {
        return t('carried_caption_cut', { hours: hoursNumber(forfeited, locale) });
    }
    const carried = carriedOf(figures);

    return carried < 0 ? t('carried_caption_borrowed') : carried > 0 ? t('carried_caption_saved') : t('carried_caption_none');
}

/** "rzeczywisty 17,85 h, 8,63 h więcej": the real time beside the billed figure, or that both are equal. */
export function billedSub(figures: Pick<BilledMonth, 'real' | 'billed' | 'used'>, locale: string, t: TFunction): string {
    const diff = round((figures.billed ?? figures.used) - figures.real);
    if (Math.abs(diff) < 0.005) {
        return t('billed_caption_equal');
    }

    return t(diff < 0 ? 'billed_sub_less' : 'billed_sub_more', { real: h(figures.real, locale), hours: h(Math.abs(diff), locale) });
}

/** "−16,5 h z poprzednich, +3,52 h w październiku": the two parts of the balance. */
export function balanceSub(period: string, figures: Figures, locale: string, t: TFunction): string {
    return t('balance_sub', { carried: h(carriedOf(figures), locale, true), month: h(monthChangeOf(figures), locale, true), in: inMonth(period, t) });
}
