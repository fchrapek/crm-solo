import { MonthCard, type MonthData } from '@/components/solo/month-card';
import { Poster } from '@/components/solo/primitives';
import type { ReactNode } from 'react';
import styles from './day.module.css';

/**
 * Figma: the day screens' head (Screen · Today 1511:25540, Tomorrow and day view 1515:26853, Week 1516:27842, Summary
 * 1516:27999, All tasks 1516:28115). The heading alone, never a date line under it: the month tile beside the day
 * carries the date (DayMonth). inTile is Today's, where the heading stands inside the day's tile from 768 px.
 */
export function DayHeading({ title, inTile = false, children }: { title: ReactNode; inTile?: boolean; children?: ReactNode }) {
    return (
        <header className={inTile ? styles.heading : styles.summaryHead}>
            <Poster>{title}</Poster>
            {children}
        </header>
    );
}

/** The month tile beside a day screen, its label naming the day shown, or the week from date to until. */
export function DayMonth({ month, date, until }: { month: MonthData; date: string; until?: string }) {
    return <MonthCard look="tile" month={month} date={date} until={until} />;
}
