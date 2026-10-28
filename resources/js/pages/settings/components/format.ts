import { useNow } from '@/hooks/use-now';
import { localeOf } from '@/lib/hours-text';
import { useTranslation } from 'react-i18next';
import { whenLabel } from '../../tasks/components/task-page/format';
import type { Stamp } from '../types';

/** "3 min temu", "dziś 14:02", "30.09": the task activity's rule, with today said out loud. */
export function useStampLabel(today: string): (stamp: Stamp) => string {
    const { t, i18n } = useTranslation();
    const now = useNow(60_000);
    const locale = localeOf(i18n.language);

    return (stamp) => {
        const text = whenLabel(stamp, now ?? 0, today, locale, t);

        return text === stamp.time ? t('today {{time}}', { time: stamp.time }) : text;
    };
}
