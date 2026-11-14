export function projectHours(minutes: number, locale: string): string {
    return new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(minutes / 60);
}
