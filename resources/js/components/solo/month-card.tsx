import { Icon } from '@/components/ui/icon';
import { Popover, PopoverAnchor, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { capitalize, dayMonth, monthName, parseDay } from '@/lib/dates';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { BottomSheet } from './bottom-sheet';
import styles from './month-card.module.css';
import { SectionHead, SoloButton, Stat } from './primitives';
import { Tile } from './tile';
import { useSoloWide } from './use-solo-wide';

export type TileState = 'closed' | 'open' | 'rest' | 'today' | 'future' | 'stamped';

export interface MonthData {
    month: string;
    days: { date: string; weekday: number; state: TileState }[];
    closed: number;
    weekdays: number;
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
 * today's. monthLink (Dziś) adds "Cały miesiąc ↓", which opens the whole month (Size=Compact). With a date the head
 * names that day (28 WRZEŚNIA), else the month. Look tile is the redesign's number tile (MonthTile), on every width.
 */
export function MonthCard({ month, date, monthLink = false, look = 'card' }: { month: MonthData; date?: string; monthLink?: boolean; look?: 'card' | 'tile' }) {
    const { t, i18n } = useTranslation();
    const name = capitalize(monthName(month.month, i18n.language));
    if (look === 'tile') return <MonthTile month={month} name={name} />;

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
 * Figma: Cały miesiąc (1092:28365, phone 1092:28604). The whole month with day numbers, Monday first: a closed day in
 * ink, an open one outlined, today in cobalt, the weekdays ahead quiet, weekends bare. A day past the month's end has
 * no cell.
 */
export function MonthCalendar({ month }: { month: MonthData }) {
    const { t } = useTranslation();
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
                    <span
                        key={day.date}
                        role="listitem"
                        className={styles.cell}
                        data-state={day.state}
                        aria-label={`${Number(day.date.slice(8))}: ${t(`tile_${day.state}`)}`}
                    >
                        {Number(day.date.slice(8))}
                    </span>
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

/**
 * Figma: Tile · Miesiąc. The closed weekdays over all weekdays as the figure, one mark per weekday
 * (closed in ink, today in cobalt, the rest quiet), and "Cały miesiąc" opening the whole calendar: a popover from
 * 768 px, a sheet below it.
 */
function MonthTile({ month, name }: { month: MonthData; name: string }) {
    const { t } = useTranslation();
    const wide = useSoloWide();
    const [open, setOpen] = useState(false);
    const weekdays = month.days.filter((day) => day.weekday <= 5);
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
                    <Stat kind="tile" label={name} value={t('{{done}} of {{count}}', { done: month.closed, count: month.weekdays })} sub={t('days closed')} />
                    <div className={styles.marks} role="list">
                        {weekdays.map((day) => (
                            <span
                                key={day.date}
                                role="listitem"
                                className={styles.mark}
                                data-state={day.state}
                                title={`${day.date} · ${t(`tile_${day.state}`)}`}
                                aria-label={`${Number(day.date.slice(8))}: ${t(`tile_${day.state}`)}`}
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
