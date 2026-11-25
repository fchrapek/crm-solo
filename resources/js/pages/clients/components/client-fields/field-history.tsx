import { useTranslation } from 'react-i18next';
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
