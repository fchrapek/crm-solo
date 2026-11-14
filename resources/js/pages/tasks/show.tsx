import { Notice } from '@/components/solo/notice';
import { Row } from '@/components/solo/row';
import { SoloSurface } from '@/components/solo/surface';
import { Tile, TileGrid, TileHeader, TileRows, TileStack } from '@/components/solo/tile';
import { useLiveReload } from '@/hooks/use-live-reload';
import { useNow } from '@/hooks/use-now';
import tasks from '@/routes/tasks';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { ActivitySection } from './components/task-page/activity-section';
import { BriefSection } from './components/task-page/brief-section';
import { ChecklistSection } from './components/task-page/checklist-section';
import { DescriptionSection } from './components/task-page/description-section';
import { ResourcesSection } from './components/task-page/resources-section';
import { TaskHeader, taskPlace } from './components/task-page/task-header';
import { TimeSection } from './components/task-page/time-section';
import { LIVE_PROPS, type TaskPageProps } from './components/task-page/types';

/** With the whole history open the poll leaves it be, so a long trail is read once, not every 30 s. */
const LIVE_WITHOUT_HISTORY = LIVE_PROPS.filter((prop) => prop !== 'activity');

/**
 * Figma: Zadanie (1094:40618, phone 1094:40855; a CRM task 1094:41102, done 1094:41505). The head, the description,
 * the card's checklist, the brief, the resources and the child tasks; beside them from 1100 px, under them
 * otherwise, the time tile and the activity.
 */
export default function TaskShow() {
    const { props, url } = usePage<SharedData & TaskPageProps>();
    const { task, time, brief, card, resources, activity, live, clock, parent_options: parentOptions } = props;
    const { t, i18n } = useTranslation();
    const locale = i18n.language;
    const now = useNow(15_000) ?? Date.parse(clock.now);
    const historyOpen = /[?&]historia(=|&|$)/.test(url);
    useLiveReload(historyOpen ? LIVE_WITHOUT_HISTORY : LIVE_PROPS);

    return (
        <SoloSurface day="paper">
            <Head title={task.name} />
            <TileGrid>
                <TileStack as="section">
                    <TaskHeader task={task} today={clock.today} locale={locale} parentOptions={parentOptions} />
                    <DescriptionSection task={task} />
                    {card !== null && card.total > 0 && <ChecklistSection card={card} trelloUrl={task.trello_url} />}
                    <BriefSection taskId={task.id} brief={brief} now={now} today={clock.today} locale={locale} />
                    <ResourcesSection taskId={task.id} resources={resources} />
                    {card !== null && (card.fetched_at === null || card.error !== null) && (
                        <div title={card.error ?? undefined}>
                            <Notice kind="info">
                                {card.fetched_at === null
                                    ? t('Fetching the card details from Trello…')
                                    : t('The card details could not be refreshed from Trello.')}
                            </Notice>
                        </div>
                    )}
                    {task.child_tasks.length > 0 && (
                        <Tile kind="list" aria-labelledby="task-children">
                            <TileHeader label={<span id="task-children">{t('Child tasks')}</span>} count={task.child_tasks.length} />
                            <TileRows>
                                {task.child_tasks.map((child) => (
                                    <Row
                                        key={child.id}
                                        title={child.name}
                                        href={tasks.show(child.id).url}
                                        check={{ done: child.is_done, label: child.is_done ? t('Done') : t('Not done') }}
                                    />
                                ))}
                            </TileRows>
                        </Tile>
                    )}
                </TileStack>
                <TileStack as="aside">
                    <TimeSection
                        task={{ id: task.id, name: task.name, client: task.client?.short_name, is_reportable: task.is_reportable }}
                        time={time}
                        now={now}
                    />
                    <ActivitySection taskId={task.id} activity={activity} full={historyOpen} live={live} clock={clock} now={now} locale={locale} />
                </TileStack>
            </TileGrid>
        </SoloSurface>
    );
}

TaskShow.place = taskPlace;
