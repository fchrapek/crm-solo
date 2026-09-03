import { useTranslation } from 'react-i18next';

/**
 * Single source of formatting truth for money, hours, and per-period suffixes.
 *
 * Why: callers used to assemble strings like `"949.00 PLN/mo"` by hand, which
 * mixed locales (Polish UI but English `/mo`) and hardcoded decimal style.
 * Routing everything through here means: changing the decimal style, adding
 * a user-settings override, or swapping the locale comes back to ONE file.
 *
 * Use the `useFormatters()` hook inside React components. The hook is bound
 * to the current i18n language so the same call site re-renders correctly
 * on locale change. The pure `formatMoney` / `formatHours` exports are
 * also available for non-React code, but most UI should use the hook.
 */

export type Currency = 'PLN' | 'EUR' | 'USD' | 'GBP';

export function formatMoney(amount: number, currency: Currency, locale = 'en-US'): string {
    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
}

export function formatHours(hours: number): string {
    if (Number.isInteger(hours)) {
        return `${hours}h`;
    }
    return `${hours.toFixed(2).replace(/\.?0+$/, '')}h`;
}

function stripTrailingZeros(value: number): string {
    return Number.isInteger(value) ? value.toString() : value.toFixed(2).replace(/\.?0+$/, '');
}

function localeFor(language: string | undefined): string {
    return (language ?? 'en').startsWith('pl') ? 'pl-PL' : 'en-US';
}

export interface Formatters {
    /** Locale-aware money: "949,00 zł" in PL, "PLN 949.00" in EN. */
    money: (amount: number, currency: Currency) => string;
    /** Hours suffix: "15h", "2.5h". */
    hours: (hours: number) => string;
    /** Hours per month: "15h/mies.", "15h/mo". */
    hoursPerMonth: (hours: number) => string;
    /** Money per hour: "180,00 zł/h". */
    perHour: (amount: number, currency: Currency) => string;
    /** Money per month: "949,00 zł/mies.", "PLN 949.00/mo". */
    perMonth: (amount: number, currency: Currency) => string;
}

export function useFormatters(): Formatters {
    const { t, i18n } = useTranslation();
    const locale = localeFor(i18n.language);

    return {
        money: (amount, currency) => formatMoney(amount, currency, locale),
        hours: formatHours,
        hoursPerMonth: (hours) => t('{{hours}}h/mo', { hours: stripTrailingZeros(hours) }),
        perHour: (amount, currency) => t('{{money}}/h', { money: formatMoney(amount, currency, locale) }),
        perMonth: (amount, currency) => t('{{money}}/mo', { money: formatMoney(amount, currency, locale) }),
    };
}
