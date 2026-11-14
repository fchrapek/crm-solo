import { Fragment, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { initials } from '@/components/solo/actor-mark';
import { SoloButton } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { Tile, TileActions, TileHeader, TileRows } from '@/components/solo/tile';
import { localeOf } from '@/lib/hours-text';

import styles from './stage-history.module.css';

export interface StageEvent {
    id: number;
    from_stage: string | null;
    to_stage: string;
    note: string | null;
    created_at: string | null;
    user_name: string | null;
}

const SHOWN = 3;

/**
 * The lead's stage moves, newest first: the move, who made it and when, and its note as the entry's text. A move can
 * be deleted (an accidental or test one); the capture cannot.
 */
export function StageHistory({ events, stageName, onDelete }: { events: StageEvent[]; stageName: (stage: string) => string; onDelete: (id: number) => void }) {
    const { t, i18n } = useTranslation();
    const [all, setAll] = useState(false);
    const day = (stamp: string | null) => (stamp ? new Date(stamp).toLocaleDateString(localeOf(i18n.language), { day: '2-digit', month: '2-digit' }) : '');

    return (
        <Tile kind="list">
            <TileHeader label={t('Stage history')} count={events.length} />
            <TileRows>
                {(all ? events : events.slice(0, SHOWN)).map((event, index) => (
                    <Fragment key={event.id}>
                        <Row
                            rule={index > 0}
                            wrap
                            title={
                                event.from_stage === null
                                    ? t('Captured at {{stage}}', { stage: stageName(event.to_stage) })
                                    : `${stageName(event.from_stage)} → ${stageName(event.to_stage)}`
                            }
                            time={day(event.created_at)}
                            meta={
                                event.user_name ? (
                                    <span title={event.user_name} aria-label={event.user_name}>
                                        {initials(event.user_name)}
                                    </span>
                                ) : undefined
                            }
                            action={
                                event.from_stage !== null ? (
                                    <SoloButton variant="quiet" size="control" aria-label={`${t('Delete history entry?')} ${day(event.created_at)}`} onClick={() => onDelete(event.id)}>
                                        {t('Delete')}
                                    </SoloButton>
                                ) : undefined
                            }
                        />
                        {event.note && (
                            <li className={styles.note} data-note="">
                                {event.note}
                            </li>
                        )}
                    </Fragment>
                ))}
            </TileRows>
            {events.length > SHOWN && (
                <TileActions>
                    <SoloButton variant="quiet" size="control" onClick={() => setAll((value) => !value)}>
                        {all ? t('Show less') : t('Show full history ({{count}})', { count: events.length })}
                    </SoloButton>
                </TileActions>
            )}
        </Tile>
    );
}
