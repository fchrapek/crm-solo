import type { TFunction } from 'i18next';
import { monthName } from './dates';
import { hoursText, localeOf } from './hours-text';

/** flash.settlement: a month close or reopen as figures, so the toast words and formats it in the viewer's language. */
export interface SettlementFlash {
    action: 'closed' | 'reopened';
    period: string;
    closing?: number;
    next?: string;
    recalculate: string | null;
}

/** "Wrzesień zamknięty" over "Bilans −11,5 h przechodzi na październik."; a reopen says the month is active again. */
export function settlementToast(flash: SettlementFlash, language: string | undefined, t: TFunction): { title: string; description: string } {
    const locale = localeOf(language);
    const month = monthName(flash.period, locale);
    const Month = month.charAt(0).toLocaleUpperCase(locale) + month.slice(1);
    const recalculate =
        flash.recalculate !== null ? t('Recalculate the report for {{month}}.', { month: `${monthName(flash.recalculate, locale)} ${flash.recalculate.slice(0, 4)}` }) : null;

    if (flash.action === 'closed') {
        const balance = t('Balance {{balance}} h carries into {{next}}.', {
            balance: hoursText(flash.closing ?? 0, locale, true),
            next: monthName(flash.next ?? flash.period, locale),
        });

        return { title: t('{{Month}} closed', { Month }), description: recalculate !== null ? `${balance} ${recalculate}` : balance };
    }

    return { title: t('{{Month}} is open again', { Month }), description: recalculate ?? t('It is the active month again.') };
}
