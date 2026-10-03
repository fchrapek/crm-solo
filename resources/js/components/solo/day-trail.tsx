import { Link } from '@inertiajs/react';
import { Fragment, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { dashboard } from '@/routes';
import today from '@/routes/today';
import styles from './day-trail.module.css';

export type DayStep = 'today' | 'done' | 'tomorrow';

/** Figma: Day trail. The day's three stages; every step is a link, and only the close button on the summary writes. `current` is omitted off the stages (the task list). */
export function DayTrail({ current, closed = false }: { current?: DayStep; closed?: boolean }) {
    const { t } = useTranslation();
    const steps = [
        { key: 'today', label: t('Today'), href: dashboard({ query: { widok: 'dzis' } }) },
        { key: 'done', label: t('Summary'), href: dashboard({ query: { widok: 'gotowe' } }) },
        { key: 'tomorrow', label: t('Tomorrow'), href: today.plan() },
    ] as const;

    return (
        <nav className={styles.trail} aria-label={t('The day')}>
            {steps.map((step, i) => (
                <Fragment key={step.key}>
                    {i > 0 && (
                        <span className={styles.sep} aria-hidden="true">
                            →
                        </span>
                    )}
                    <Link href={step.href} className={styles.step} aria-current={current === step.key ? 'step' : undefined}>
                        {step.label}
                        {step.key === 'done' && closed && <span aria-label={t('Day closed')}> ✓</span>}
                    </Link>
                </Fragment>
            ))}
        </nav>
    );
}

/** Figma: Day row. The trail on the left and the date (or the live timer line) on the right, one shared baseline. */
export function DayRow({ current, closed, children }: { current?: DayStep; closed?: boolean; children: ReactNode }) {
    return (
        <div className={styles.row}>
            <DayTrail current={current} closed={closed} />
            <div className={styles.aside}>{children}</div>
        </div>
    );
}
