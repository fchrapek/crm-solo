/** Dates arrive as YYYY-MM-DD in the display timezone; noon avoids any DST edge when parsing. */
export function parseDay(date: string): Date {
    return new Date(`${date}T12:00:00`);
}

export function isoWeek(date: Date): number {
    const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    const day = d.getUTCDay() || 7;
    d.setUTCDate(d.getUTCDate() + 4 - day);
    const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));

    return Math.ceil(((d.getTime() - yearStart.getTime()) / 86400000 + 1) / 7);
}

export function weekdayName(date: Date, locale: string): string {
    return new Intl.DateTimeFormat(locale, { weekday: 'long' }).format(date);
}

/** "Poniedziałek, 28 września" / "Monday, 28 September", first letter capitalised. */
export function longDate(date: Date, locale: string): string {
    const text = new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long' }).format(date);

    return text.charAt(0).toUpperCase() + text.slice(1);
}

export function formatMinutes(minutes: number): string {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return h > 0 ? `${h} h ${String(m).padStart(2, '0')}` : `${m} min`;
}

/** "28 września" / "28 September": the date without the weekday the poster already shows. */
export function dayMonth(date: Date, locale: string): string {
    return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long' }).format(date);
}
