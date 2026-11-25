import { elapsedClock, useElapsed } from '@/hooks/use-elapsed';
import { useTranslation } from 'react-i18next';

/** A running timer's time, read out as hours and minutes. */
export function Elapsed({ startedAt }: { startedAt: string }) {
    const { t } = useTranslation();
    const elapsed = useElapsed(startedAt);

    return <span aria-label={t('{{h}} h {{m}} min elapsed', { h: elapsed.h, m: elapsed.m })}>{elapsedClock(elapsed)}</span>;
}
