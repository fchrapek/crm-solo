import { useTranslation } from 'react-i18next';
import { SectionHead } from './primitives';
import styles from './month-card.module.css';

export type TileState = 'closed' | 'open' | 'rest' | 'today' | 'future' | 'stamped';

export interface MonthData {
    month: string;
    days: { date: string; weekday: number; state: TileState }[];
    closed: number;
    weekdays: number;
}

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as const;

/** Figma: Day tile, composed into the month card. One tile per day, Monday first. */
export function MonthCard({ month, showLegend = true }: { month: MonthData; showLegend?: boolean }) {
    const { t, i18n } = useTranslation();
    const first = month.days[0]?.weekday ?? 1;
    const name = new Intl.DateTimeFormat(i18n.language, { month: 'long' }).format(new Date(month.month + 'T12:00:00'));

    return (
        <section className={styles.card} aria-label={name}>
            <SectionHead label={name} meta={t('{{closed}} of {{total}} days', { closed: month.closed, total: month.weekdays })} />
            <div className={styles.grid} role="list">
                {WEEKDAYS.map((d, i) => (
                    <span key={d} className={[styles.weekday, i > 4 ? styles.weekend : ''].join(' ')} aria-hidden="true">
                        {t(`weekday_initial_${d}`)}
                    </span>
                ))}
                {Array.from({ length: first - 1 }, (_, i) => (
                    <span key={`pad-${i}`} aria-hidden="true" />
                ))}
                {month.days.map((day) => (
                    <span
                        key={day.date}
                        role="listitem"
                        className={[styles.tile, styles[day.state]].join(' ')}
                        title={`${day.date} · ${t(`tile_${day.state}`)}`}
                        aria-label={`${Number(day.date.slice(8))}: ${t(`tile_${day.state}`)}`}
                    >
                        {day.state === 'stamped' && <span aria-hidden="true">✓</span>}
                    </span>
                ))}
            </div>
            {showLegend && (
                <ul className={styles.legend}>
                    {(['closed', 'open', 'today'] as const).map((s) => (
                        <li key={s}>
                            <span className={[styles.swatch, styles[s]].join(' ')} aria-hidden="true" />
                            {t(`tile_${s}`)}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
