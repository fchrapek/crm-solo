import FlashMessages from '@/components/flash-messages';
import { Masthead } from '@/components/solo/masthead';
import { SectionHead } from '@/components/solo/primitives';
import { splitClass, splitMainClass, splitSideClass } from '@/components/solo/split';
import { SoloSurface } from '@/components/solo/surface';
import { SoloToaster } from '@/components/solo/toaster';
import { useLiveReload } from '@/hooks/use-live-reload';
import { useNow } from '@/hooks/use-now';
import tasks from '@/routes/tasks';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { ActivitySection } from './components/task-page/activity-section';
import { BriefSection } from './components/task-page/brief-section';
import { ChecklistSection } from './components/task-page/checklist-section';
import { DescriptionSection } from './components/task-page/description-section';
import { ResourcesSection } from './components/task-page/resources-section';
import { TaskHeader } from './components/task-page/task-header';
import styles from './components/task-page/task-page.module.css';
import { TimeSection, TimeStats } from './components/task-page/time-section';
import { LIVE_PROPS, type TaskPageProps } from './components/task-page/types';

/** With the whole history open the poll leaves it be, so a long trail is read once, not every 30 s. */
const LIVE_WITHOUT_HISTORY = LIVE_PROPS.filter((prop) => prop !== 'activity');

/** Figma: Zadanie: desktop 770:56741 (with child tasks 740:18599), phones 849:20996 (timer running 849:21057). */
export default function TaskShow() {
    const { props, url } = usePage<SharedData & TaskPageProps>();
    const { task, time, brief, card, resources, activity, live, clock } = props;
    const { t, i18n } = useTranslation();
    const locale = i18n.language;
    const now = useNow(15_000) ?? Date.parse(clock.now);
    const historyOpen = /[?&]historia(=|&|$)/.test(url);
    useLiveReload(historyOpen ? LIVE_WITHOUT_HISTORY : LIVE_PROPS);

    return (
        <SoloSurface className={styles.page} day="paper">
            <Head title={task.name} />
            <div className={styles.frame}>
                <Masthead current="clients" />
                <main>
                    <TaskHeader task={task} today={clock.today} locale={locale}>
                        <TimeStats time={time} now={now} />
                    </TaskHeader>
                    <div className={`${splitClass} ${styles.columns}`}>
                        <div className={`${splitMainClass} ${styles.main}`}>
                            <DescriptionSection task={task} />
                            <div className={styles.toggles}>
                                {card !== null && card.total > 0 && <ChecklistSection card={card} trelloUrl={task.trello_url} />}
                                <BriefSection taskId={task.id} brief={brief} now={now} today={clock.today} locale={locale} />
                                <ResourcesSection taskId={task.id} resources={resources} />
                                {card !== null && (card.fetched_at === null || card.error !== null) && (
                                    <p className={styles.cardState} title={card.error ?? undefined}>
                                        {card.fetched_at === null
                                            ? t('Fetching the card details from Trello…')
                                            : t('The card details could not be refreshed from Trello.')}
                                    </p>
                                )}
                            </div>
                            {task.child_tasks.length > 0 && (
                                <section className={styles.children} aria-labelledby="task-children">
                                    <SectionHead
                                        kind="body"
                                        label={<span id="task-children">{t('Child tasks')}</span>}
                                        meta={String(task.child_tasks.length)}
                                    />
                                    <ul className={styles.childList}>
                                        {task.child_tasks.map((child) => (
                                            <li key={child.id}>
                                                <Link
                                                    href={tasks.show(child.id).url}
                                                    className={styles.childLink}
                                                    data-done={child.is_done || undefined}
                                                >
                                                    {child.name}
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            )}
                        </div>
                        <div className={`${splitSideClass} ${styles.side}`}>
                            <TimeSection task={{ id: task.id, name: task.name, client: task.client?.short_name }} time={time} now={now} />
                            <ActivitySection
                                taskId={task.id}
                                activity={activity}
                                full={historyOpen}
                                live={live}
                                clock={clock}
                                now={now}
                                locale={locale}
                            />
                        </div>
                    </div>
                </main>
            </div>
            <FlashMessages toaster={<SoloToaster />} />
        </SoloSurface>
    );
}
