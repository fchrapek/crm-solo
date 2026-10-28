import type { TFunction } from 'i18next';

/**
 * Days (YYYY-MM-DD) and months (YYYY-MM) arrive already in the display timezone, so they are read as local
 * calendar dates at noon (a month on its 15th): no browser timezone or DST change can move them.
 */
export function parseDay(value: string): Date {
    const [year, month, day = 15] = value.split('-').map(Number);

    return new Date(year, month - 1, day, 12);
}

function format(value: string, locale: string, options: Intl.DateTimeFormatOptions): string {
    return new Intl.DateTimeFormat(locale, options).format(parseDay(value));
}

/** "30.10", with the year when it is not `thisYear`; without `thisYear`, never. */
export function shortDate(day: string, locale: string, thisYear?: number): string {
    const withYear = thisYear !== undefined && Number(day.slice(0, 4)) !== thisYear;

    return format(day, locale, { day: '2-digit', month: '2-digit', ...(withYear ? { year: 'numeric' } : {}) });
}

/** "30.10.2026". */
export function fullDate(day: string, locale: string): string {
    return format(day, locale, { day: '2-digit', month: '2-digit', year: 'numeric' });
}

/** "październik" / "October": the standalone month name. */
export function monthName(period: string, locale: string): string {
    return format(period, locale, { month: 'long' });
}

/** "września" in Polish, where a month after a noun takes the genitive; the plain name elsewhere. */
export function monthGenitive(period: string, locale: string): string {
    const part = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long' }).formatToParts(parseDay(period)).find((p) => p.type === 'month');

    return part?.value ?? monthName(period, locale);
}

/** "WRZ": the short month mark. */
export function monthMark(period: string, locale: string): string {
    return format(period, locale, { month: 'short' }).replace('.', '').toLocaleUpperCase(locale);
}

/** "wrześniem": Polish wants the instrumental after "z otwartym", which Intl does not give, so the lang files hold the twelve forms. */
export function monthInstrumental(period: string, t: TFunction): string {
    return t(`month_instrumental_${period.slice(5, 7)}`);
}

/** "wrześniu": the locative after "po" (bank po wrześniu), held in the lang files like the instrumental. */
export function monthLocative(period: string, t: TFunction): string {
    return t(`month_locative_${period.slice(5, 7)}`);
}

/** "we wrześniu" / "in September": the month's own context picks Polish "we" before "wrz". */
export function inMonth(period: string, t: TFunction): string {
    return t('in {{month}}', { month: monthLocative(period, t), context: period.slice(5, 7) });
}

export function capitalize(text: string): string {
    return text.charAt(0).toLocaleUpperCase() + text.slice(1);
}

/** "Czwartek": a weekday as a title, first letter capitalised. */
export function weekdayName(date: Date, locale: string): string {
    return capitalize(new Intl.DateTimeFormat(locale, { weekday: 'long' }).format(date));
}

const FROM_WEEKDAY = ['from Sunday', 'from Monday', 'from Tuesday', 'from Wednesday', 'from Thursday', 'from Friday', 'from Saturday'];

/** "z czwartku": the day an earlier pick came from, by weekday within the last week, by date before that. */
export function fromDay(day: string, today: string, locale: string, t: TFunction): string {
    const days = Math.round((parseDay(today).getTime() - parseDay(day).getTime()) / 86_400_000);
    if (days < 7) return t(FROM_WEEKDAY[parseDay(day).getDay()]);

    return t('from {{date}}', { date: shortDate(day, locale, Number(today.slice(0, 4))) });
}

/** "28 września" / "28 September": the date without the weekday the poster already shows. */
export function dayMonth(date: Date, locale: string): string {
    return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long' }).format(date);
}

/** Whole minutes from `since` to `now` (ms), never negative for a clock behind the server. */
export function minutesSince(since: string, now: number): number {
    return Math.max(0, Math.floor((now - new Date(since).getTime()) / 60_000));
}

/** Running timers' minutes so far that count today: an entry counts on the day it started, running or stopped, as reports count it. */
export function runningMinutesToday(dayStart: string, startedAt: readonly string[], now: number): number {
    const start = new Date(dayStart).getTime();

    return startedAt.reduce((sum, started) => (new Date(started).getTime() >= start ? sum + minutesSince(started, now) : sum), 0);
}

/** "3 h 40", "0 h 48": the day's figures on Podsumowanie and the timer. */
export function hoursClock(minutes: number, t: TFunction): string {
    return t('{{hours}} h {{mm}}', { hours: Math.floor(minutes / 60), mm: String(minutes % 60).padStart(2, '0') });
}

/** A Stat's figure: "38 min" under an hour, then the clock form without "min" ("2 h 52"). */
export function statDuration(minutes: number, t: TFunction): string {
    return minutes < 60 ? t('{{minutes}} min', { minutes }) : hoursClock(minutes, t);
}

/** "45 min", "2 h", "3 h 15 min". */
export function durationText(minutes: number, t: TFunction): string {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0) {
        return t('{{minutes}} min', { minutes: rest });
    }

    return rest === 0 ? t('{{hours}} h', { hours }) : t('{{hours}} h {{minutes}} min', { hours, minutes: rest });
}
