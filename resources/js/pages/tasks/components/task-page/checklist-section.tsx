import { Row } from '@/components/solo/row';
import { GroupHeading, Tile, TileHeader, TileInset, TileNote, TileRows } from '@/components/solo/tile';
import { Fragment } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './task-page.module.css';
import type { TaskCard } from './types';

/**
 * Figma: Task, the Checklist tile (1094:40618). The card's checklist from the cache, read only: Trello owns it, and
 * the CRM has no checklist of its own, so a task without a card checklist has no tile.
 */
export function ChecklistSection({ card, trelloUrl }: { card: TaskCard; trelloUrl: string | null }) {
    const { t } = useTranslation();

    return (
        <Tile kind="list" aria-labelledby="task-checklist">
            <TileHeader
                label={<span id="task-checklist">{t('Checklist')}</span>}
                count={t('{{done}} of {{total}}', { done: card.done, total: card.total })}
                end={
                    trelloUrl !== null ? (
                        <a href={trelloUrl} target="_blank" rel="noopener noreferrer" className={styles.sourceLink}>
                            {t('from Trello')} ↗
                        </a>
                    ) : (
                        t('from Trello')
                    )
                }
            />
            {card.checklists.map((list, index) => (
                <Fragment key={`${index}-${list.name}`}>
                    {card.checklists.length > 1 && <GroupHeading as="h3" label={list.name} />}
                    <TileRows>
                        {list.items.map((item, i) => (
                            <Row key={`${i}-${item.name}`} title={item.name} check={{ done: item.done, label: item.done ? t('Done') : t('Not done') }} />
                        ))}
                    </TileRows>
                </Fragment>
            ))}
            <TileInset>
                <TileNote>{t('The checklist comes from Trello and is read only here.')}</TileNote>
            </TileInset>
        </Tile>
    );
}
