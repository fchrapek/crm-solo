import { CheckIcon } from '@/components/solo/mobile-icons';
import { SectionToggle } from '@/components/solo/section-toggle';
import { useTranslation } from 'react-i18next';
import styles from './task-page.module.css';
import type { TaskCard } from './types';

/**
 * Figma: Checklista (770:56741; phones 849:20996). The card's checklist from the cache, read
 * only: Trello owns it, and the CRM has no checklist of its own, so a task
 * without a card checklist has no row.
 */
export function ChecklistSection({ card, trelloUrl }: { card: TaskCard; trelloUrl: string | null }) {
    const { t } = useTranslation();
    const editInTrello =
        trelloUrl !== null ? (
            <a href={trelloUrl} target="_blank" rel="noopener noreferrer" className={styles.extraLink}>
                {t('Edit in Trello')} ↗
            </a>
        ) : undefined;

    return (
        <SectionToggle
            label={t('Checklist')}
            summary={`${t('{{done}} of {{total}}', { done: card.done, total: card.total })} · ${t('from Trello')}`}
            phoneSummary={t('{{done}} of {{total}}', { done: card.done, total: card.total })}
            extra={editInTrello}
        >
            <p className={styles.readOnlyNote}>{t('The checklist comes from Trello and is read only here.')}</p>
            {card.checklists.map((list, index) => (
                <div key={`${index}-${list.name}`}>
                    {card.checklists.length > 1 && <h3 className={styles.checklistName}>{list.name}</h3>}
                    <ul className={styles.checklist}>
                        {list.items.map((item, i) => (
                            <li key={`${i}-${item.name}`} className={styles.checkItem} data-done={item.done || undefined}>
                                <span className={styles.checkBox} role="img" aria-label={item.done ? t('Done') : t('Not done')}>
                                    {item.done && <CheckIcon width={10} height={8} />}
                                </span>
                                <span className={styles.checkName}>{item.name}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
            {trelloUrl !== null && (
                <a href={trelloUrl} target="_blank" rel="noopener noreferrer" className={styles.phoneAction}>
                    {t('Edit in Trello')} ↗
                </a>
            )}
        </SectionToggle>
    );
}
