import { shortDate } from '@/lib/dates';
import { localeOf } from '@/lib/hours-text';
import { useTranslation } from 'react-i18next';
import styles from './client-fields.module.css';
import type { FieldEvent } from './types';

/** "12.10 · Marta Lis: Wizja na umówiona"; a cleared value reads as cleared. */
export function useEventLine() {
    const { t } = useTranslation();

    return (event: FieldEvent) => {
        // The built-in assignee is stored under its English name.
        const field = event.key === 'assignee' ? t('Assignee') : event.label;

        return event.new === null
            ? t('{{field}} cleared', { field })
            : event.old === null
              ? t('{{field}}: {{value}}', { field, value: event.new })
              : t('{{field}}: {{old}} → {{value}}', { field, old: event.old, value: event.new });
    };
}

/** Figma: Klient / Dane, Historia pól (601:20846): the latest field changes, oldest last. */
export function FieldHistory({ events }: { events: FieldEvent[] }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const line = useEventLine();

    if (events.length === 0) return <p className={styles.empty}>{t('No field changes yet.')}</p>;

    return (
        <ul className={styles.history}>
            {events.map((event) => (
                <li key={event.id} className={styles.historyRow}>
                    <span className={styles.when}>{shortDate(event.day, locale)}</span>
                    <span>{event.user === null ? line(event) : `${event.user}: ${line(event)}`}</span>
                </li>
            ))}
        </ul>
    );
}
