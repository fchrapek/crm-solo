import { Icon } from '@/components/ui/icon';
import { Popover, PopoverAnchor, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { capitalize, dayMonth, durationText, fullDate, longDate, monthName, parseDay, shortDate } from '@/lib/dates';
import { dashboard } from '@/routes';
import today from '@/routes/today';
import { Link } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useState, type CSSProperties } from 'react';
import { useTranslation } from 'react-i18next';
import { BottomSheet } from './bottom-sheet';
import { IconButton } from './icon-button';
import styles from './month-card.module.css';
import { SectionHead, SoloButton, Stat } from './primitives';
import { Tile } from './tile';
import { useSoloWide } from './use-solo-wide';

export type TileState = 'closed' | 'open' | 'rest' | 'today' | 'future' | 'stamped';

export interface MonthDay {
    date: string;
    weekday: number;
    state: TileState;
    /** Time logged on the day. */
    minutes?: number;
}

export interface MonthData {
    month: string;
    days: MonthDay[];
    closed: number;
    weekdays: number;
}

/** A day's own page; today is the home screen. */
const dayHref = (day: MonthDay) => (day.state === 'today' || day.state === 'stamped' ? dashboard() : today.day(day.date));

/** "śr. 11 listopada · 1 h 15 · niezamknięty": what a day's mark says on hover and to a screen reader. */
export function dayTitle(day: MonthDay, locale: string, t: TFunction): string {
    const date = parseDay(day.date);

    return [
        `${new Intl.DateTimeFormat(locale, { weekday: 'short' }).format(date)} ${dayMonth(date, locale)}`,
        day.minutes ? durationText(day.minutes, t) : null,
        t(day.state === 'open' ? 'tile_tooltip_open' : `tile_${day.state}`),
    ]
        .filter(Boolean)
        .join(' · ');
}

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as const;

/** Today's week row, else the last week that has begun. */
export function currentWeek(month: MonthData): number {
    const lead = (month.days[0]?.weekday ?? 1) - 1;
    const row = (index: number) => Math.floor((lead + index) / 7);
    const today = month.days.findIndex((day) => day.state === 'today' || day.state === 'stamped');
    if (today >= 0) return row(today);
    const begun = month.days.findLastIndex((day) => day.state !== 'future');

    return row(Math.max(begun, 0));
}

/** The two week rows the card keeps: the week before today's and today's, or the month's first two. */
export function shownWeeks(month: MonthData): number[] {
    const current = currentWeek(month);

    return current > 0 ? [current - 1, current] : [0, 1];
}

/**
 * Figma: A2f / Month card (813:59731) with its Day tiles, Monday first. From 768 px only; phones draw no calendar.
 * The card fills its column, the head sits flush with the tiles, and it keeps two weeks: the one before today's and
 * today's. monthLink (Today) adds Whole month ↓, which opens the whole month (Size=Compact). With a date the head
 * names that day (28 WRZEŚNIA), else the month. Look tile is the redesign's number tile (MonthTile), on every width;
 * there the date, or the range from date to until, follows the month name.
 */
export function MonthCard({
    month,
    date,
    until,
    monthLink = false,
    look = 'card',
}: {
    month: MonthData;
    date?: string;
    until?: string;
    monthLink?: boolean;
    look?: 'card' | 'tile';
}) {
    const { t, i18n } = useTranslation();
    const name = capitalize(monthName(month.month, i18n.language));
    if (look === 'tile') return <MonthTile month={month} name={name} label={monthTileLabel(name, i18n.language, date, until)} />;

    const label = date ? (
        <>
            <span className={styles.dayLabel}>{dayMonth(parseDay(date), i18n.language)}</span>
            <span className={styles.monthLabel}>{name}</span>
        </>
    ) : (
        name
    );

    const head = <SectionHead inset="none" label={label} meta={t('{{closed}} of {{total}} days', { closed: month.closed, total: month.weekdays })} />;
    const card = (
        <section className={styles.card} aria-label={name}>
            {head}
            <MonthGrid month={month} weeks={shownWeeks(month)} />
            {monthLink && <PopoverTrigger className={styles.whole}>{t('Whole month')} ↓</PopoverTrigger>}
        </section>
    );

    if (!monthLink) return card;

    return (
        <Popover>
            <PopoverAnchor asChild>{card}</PopoverAnchor>
            <PopoverContent align="end" sideOffset={10} className={styles.popover} aria-label={name}>
                {head}
                <MonthGrid month={month} weeks={null} />
                <Legend />
            </PopoverContent>
        </Popover>
    );
}

/**
 * Figma: Whole month (1092:28365, phone 1092:28604). The whole month with day numbers, Monday first: a closed day in
 * ink, an open one outlined, today in cobalt, the weekdays ahead quiet, weekends bare. A day past the month's end has
 * no cell.
 */
export function MonthCalendar({ month, pick }: { month?: MonthData; pick?: DayPicking }) {
    const { t, i18n } = useTranslation();

    if (pick !== undefined || month === undefined) {
        return pick === undefined ? null : <PickCalendar {...pick} />;
    }

    const lead = (month.days[0]?.weekday ?? 1) - 1;

    return (
        <div className={styles.calendar}>
            <p className={styles.calendarCount}>{t('{{closed}} of {{total}} days closed', { closed: month.closed, total: month.weekdays })}</p>
            <div className={styles.cells} role="list">
                {WEEKDAYS.map((d) => (
                    <span key={d} className={styles.cellHead} aria-hidden="true">
                        {t(`weekday_initial_${d}`)}
                    </span>
                ))}
                {Array.from({ length: lead }, (_, i) => (
                    <span key={`pad-${i}`} aria-hidden="true" />
                ))}
                {month.days.map((day) => (
                    <Link
                        key={day.date}
                        role="listitem"
                        href={dayHref(day)}
                        className={`${styles.pick} ${styles.cell}`}
                        data-state={day.state}
                        title={dayTitle(day, i18n.language, t)}
                        aria-label={dayTitle(day, i18n.language, t)}
                    >
                        {Number(day.date.slice(8))}
                    </Link>
                ))}
            </div>
            <ul className={styles.cellLegend}>
                {(['closed', 'open', 'today', 'future'] as const).map((state) => (
                    <li key={state}>
                        <span className={styles.cellSwatch} data-state={state} aria-hidden="true" />
                        {t(`tile_${state}`)}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** The calendar as a date picker: a click chooses the day. */
export interface DayPicking {
    value: string | null;
    onPick: (date: string) => void;
    /** Days before it are quiet and cannot be chosen. */
    min?: string;
    /** The local day, for tests; the browser's by default. */
    today?: string;
}

const pad = (n: number) => String(n).padStart(2, '0');

export function localToday(now = new Date()): string {
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** The days of a month (YYYY-MM), Monday as weekday 1. */
export function monthDays(month: string): { date: string; weekday: number }[] {
    const [year, index] = month.split('-').map(Number);
    const count = new Date(Date.UTC(year, index, 0)).getUTCDate();

    return Array.from({ length: count }, (_, i) => ({
        date: `${month}-${pad(i + 1)}`,
        weekday: ((new Date(Date.UTC(year, index - 1, i + 1)).getUTCDay() + 6) % 7) + 1,
    }));
}

export function shiftMonth(month: string, by: number): string {
    const [year, index] = month.split('-').map(Number);
    const moved = new Date(Date.UTC(year, index - 1 + by, 1));

    return `${moved.getUTCFullYear()}-${pad(moved.getUTCMonth() + 1)}`;
}

/** Figma: the calendar in pick mode (1260:8200, sheet 1260:8463). The same cells with a month to turn: the chosen day is marked like today, weekdays ahead are filled, days before `min` are quiet. */
function PickCalendar({ value, onPick, min, today = localToday() }: DayPicking) {
    const { t, i18n } = useTranslation();
    const [month, setMonth] = useState((value ?? (min !== undefined && min > today ? min : today)).slice(0, 7));
    const days = monthDays(month);
    const lead = days[0].weekday - 1;
    const state = (day: { date: string; weekday: number }) =>
        day.date === value || day.date === today ? 'today' : min !== undefined && day.date < min ? 'past' : day.weekday <= 5 ? 'future' : undefined;

    return (
        <div className={styles.calendar}>
            <div className={styles.pickHead}>
                <p className={styles.calendarTitle} aria-live="polite">
                    {capitalize(monthName(month, i18n.language))} {month.slice(0, 4)}
                </p>
                <IconButton
                    icon="chevron-left"
                    look="quiet"
                    label={t('Previous month')}
                    disabled={min !== undefined && month <= min.slice(0, 7)}
                    onClick={() => setMonth(shiftMonth(month, -1))}
                />
                <IconButton icon="chevron-right" look="quiet" label={t('Next month')} onClick={() => setMonth(shiftMonth(month, 1))} />
            </div>
            <div className={styles.cells}>
                {WEEKDAYS.map((d) => (
                    <span key={d} className={styles.cellHead} aria-hidden="true">
                        {t(`weekday_initial_${d}`)}
                    </span>
                ))}
                {Array.from({ length: lead }, (_, i) => (
                    <span key={`pad-${i}`} aria-hidden="true" />
                ))}
                {days.map((day) => (
                    <button
                        key={day.date}
                        type="button"
                        className={`${styles.pick} ${styles.cell}`}
                        data-state={state(day)}
                        aria-label={longDate(day.date, i18n.language)}
                        aria-pressed={day.date === value}
                        disabled={min !== undefined && day.date < min}
                        onClick={() => onPick(day.date)}
                    >
                        {Number(day.date.slice(8))}
                    </button>
                ))}
            </div>
        </div>
    );
}

/** Figma 1457:3384: the label of a day screen's month figure carries the date its heading no longer shows ("Listopad · 21.11.2026", a week "Listopad · 23.11 – 29.11.2026"). */
export function monthTileLabel(name: string, locale: string, date?: string, until?: string): string {
    if (date === undefined) return name;

    return until === undefined ? `${name} · ${fullDate(date, locale)}` : `${name} · ${shortDate(date, locale)} – ${fullDate(until, locale)}`;
}

/**
 * Figma: Tile · Month. The closed weekdays over all weekdays as the figure, one mark per weekday
 * (closed in ink, today in cobalt, the rest quiet), and Whole month opening the whole calendar: a popover from
 * 768 px, a sheet below it.
 */
function MonthTile({ month, name, label }: { month: MonthData; name: string; label: string }) {
    const { t, i18n } = useTranslation();
    const wide = useSoloWide();
    const [open, setOpen] = useState(false);
    const title = `${name} ${month.month.slice(0, 4)}`;
    const trigger = (
        <SoloButton variant="secondary" size="control" aria-haspopup="dialog" aria-expanded={open} onClick={wide ? undefined : () => setOpen(true)}>
            {t('Whole month')}
        </SoloButton>
    );

    return (
        <Popover open={wide && open} onOpenChange={setOpen}>
            <PopoverAnchor asChild>
                <Tile kind="plain" aria-label={name}>
                    <Stat
                        kind="tile"
                        label={label}
                        value={t('{{done}} of {{count}}', { done: month.closed, count: month.weekdays })}
                        sub={t('days closed')}
                    />
                    <div className={styles.marks} role="list" style={{ '--marks-columns': Math.ceil(month.days.length / 2) } as CSSProperties}>
                        {month.days.map((day) => (
                            <Link
                                key={day.date}
                                role="listitem"
                                href={dayHref(day)}
                                className={styles.mark}
                                data-state={day.state}
                                title={dayTitle(day, i18n.language, t)}
                                aria-label={dayTitle(day, i18n.language, t)}
                            />
                        ))}
                    </div>
                    {wide ? <PopoverTrigger asChild>{trigger}</PopoverTrigger> : trigger}
                </Tile>
            </PopoverAnchor>
            <PopoverContent align="end" sideOffset={10} className={styles.monthPopover} aria-label={title}>
                <p className={styles.calendarTitle}>{title}</p>
                <MonthCalendar month={month} />
            </PopoverContent>
            {!wide && (
                <BottomSheet open={open} onOpenChange={setOpen} title={title}>
                    {open && <MonthCalendar month={month} />}
                </BottomSheet>
            )}
        </Popover>
    );
}

function MonthGrid({ month, weeks }: { month: MonthData; weeks: number[] | null }) {
    const { t } = useTranslation();
    const lead = (month.days[0]?.weekday ?? 1) - 1;
    const other = (index: number) => (weeks !== null && !weeks.includes(Math.floor(index / 7)) ? '' : undefined);

    return (
        <div className={styles.grid} role="list">
            {WEEKDAYS.map((d, i) => (
                <span key={d} className={[styles.weekday, i > 4 ? styles.weekend : ''].join(' ')} aria-hidden="true">
                    {t(`weekday_initial_${d}`)}
                </span>
            ))}
            {Array.from({ length: lead }, (_, i) => (
                <span key={`pad-${i}`} aria-hidden="true" data-other-week={other(i)} />
            ))}
            {month.days.map((day, i) => (
                <span
                    key={day.date}
                    role="listitem"
                    className={[styles.tile, styles[day.state]].join(' ')}
                    data-other-week={other(lead + i)}
                    title={`${day.date} · ${t(`tile_${day.state}`)}`}
                    aria-label={`${Number(day.date.slice(8))}: ${t(`tile_${day.state}`)}`}
                >
                    {day.state === 'closed' && <Icon name="check" size={20} />}
                    {day.state === 'stamped' && <Icon name="done" size={16} filled />}
                </span>
            ))}
        </div>
    );
}

function Legend() {
    const { t } = useTranslation();

    return (
        <ul className={styles.legend}>
            {(['closed', 'open', 'today'] as const).map((s) => (
                <li key={s}>
                    <span className={[styles.swatch, styles[s]].join(' ')} aria-hidden="true" />
                    {t(`tile_${s}`)}
                </li>
            ))}
        </ul>
    );
}
