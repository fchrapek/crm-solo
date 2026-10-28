const MINUS = '−';

/** The Intl locale the Solo screens format numbers and months in. */
export function localeOf(language: string | undefined): string {
    return (language ?? 'pl').startsWith('pl') ? 'pl-PL' : 'en-GB';
}

/**
 * "−16,0", "−0,25", "+11,5": worked hours and balances, one decimal always, a second only when it carries something,
 * thousands grouped, a real minus sign. App\Support\HoursText::number() prints the same on the server.
 */
export function hoursText(value: number, locale: string, signed = false): string {
    return new Intl.NumberFormat(locale, {
        minimumFractionDigits: 1,
        maximumFractionDigits: 2,
        signDisplay: signed ? 'exceptZero' : 'auto',
        useGrouping: 'always',
    })
        .format(value)
        .replace('-', MINUS);
}

/** "30", "7,5": a retainer's package or rollover cap as it was entered; App\Support\HoursText::terms() on the server. */
export function hoursTerms(value: number, locale: string): string {
    return new Intl.NumberFormat(locale, { maximumFractionDigits: 2, useGrouping: 'always' }).format(value).replace('-', MINUS);
}
