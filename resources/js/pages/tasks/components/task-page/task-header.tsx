import { Chip } from '@/components/solo/chip';
import { useLinkPrefetch } from '@/components/solo/link-prefetch';
import { Poster, SoloButton } from '@/components/solo/primitives';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { Tile } from '@/components/solo/tile';
import { dashboard } from '@/routes';
import clients from '@/routes/clients';
import day from '@/routes/day';
import projectBoard from '@/routes/project-board';
import tasks from '@/routes/tasks';
import todayRoutes from '@/routes/today';
import { Link, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { clientToken } from '../../../clients/place';
import { agentPrompt, dueText } from './format';
import { TaskMenu } from './task-menu';
import styles from './task-page.module.css';
import type { TaskData } from './types';

/** Copy for the agent: the line that sends an agent to `crm task`, then a toast. */
export async function copyAgentPrompt(
    text: string,
    clipboard: Pick<Clipboard, 'writeText'> | undefined = globalThis.navigator?.clipboard,
): Promise<boolean> {
    if (!clipboard) return false;
    try {
        await clipboard.writeText(text);

        return true;
    } catch {
        return false;
    }
}

export function useCopyForAgent(taskId: number) {
    const { t } = useTranslation();

    return async () => {
        if (await copyAgentPrompt(agentPrompt(taskId, t))) {
            toast.success(t('Copied'));
        } else {
            toast.error(t('Could not copy to the clipboard.'));
        }
    };
}

/** One click puts the task on today; a picked task says so. */
function PickToday({ task }: { task: TaskData }) {
    const { t } = useTranslation();

    if (task.is_done || task.is_archived) return null;
    if (task.picked_today) {
        return (
            <SoloButton variant="secondary" size="control" disabled>
                {t('Picked')}
            </SoloButton>
        );
    }

    return (
        <SoloButton
            variant="secondary"
            size="control"
            onClick={() => router.post(day.picks.store().url, { task_id: task.id }, { preserveScroll: true })}
        >
            {t('For today')}
        </SoloButton>
    );
}

/** The CRM tick, through the day screen's endpoint and so TaskCompletion; only a card says "w CRM", as its Trello state stays. */
function DoneAction({ task }: { task: TaskData }) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);
    const options = { preserveScroll: true, onFinish: () => setBusy(false) };

    if (task.is_done) {
        if (!task.can_unfinish) return null;

        return (
            <SoloButton
                variant="secondary"
                size="control"
                className={styles.mainAction}
                disabled={busy}
                onClick={() => {
                    setBusy(true);
                    router.delete(day.tasks.uncomplete(task.id).url, options);
                }}
            >
                {task.has_trello_card ? t('Undo done in the CRM') : t('Mark as not done')}
            </SoloButton>
        );
    }

    return (
        <SoloButton
            size="control"
            className={styles.mainAction}
            disabled={busy}
            onClick={() => {
                setBusy(true);
                router.post(day.tasks.complete(task.id).url, {}, options);
            }}
        >
            {task.has_trello_card ? t('Mark done in the CRM') : t('Mark done')}
        </SoloButton>
    );
}

/** The line under the title: client, project, due day (vermilion once overdue), the parent task, Trello for a card. */
export function TaskMeta({ task, today, locale }: { task: TaskData; today: string; locale: string }) {
    const { t } = useTranslation();
    const prefetch = useLinkPrefetch();
    const clientHref = task.client !== null ? clients.show({ client: task.client.id, tab: 'overview' }).url : null;
    const due = task.due_day !== null ? dueText(task.due_day, today, locale, t) : null;
    const parts = [
        task.client !== null && clientHref !== null ? (
            <Link key="client" href={clientHref} prefetch={prefetch(clientHref)} className={styles.metaLink} title={task.client.name}>
                {task.client.short_name}
            </Link>
        ) : null,
        task.project !== null && task.client !== null ? (
            <Link key="project" href={projectBoard.show({ client: task.client.id, project: task.project.id }).url} className={styles.metaLink}>
                {task.project.name}
            </Link>
        ) : task.project !== null ? (
            <span key="project">{task.project.name}</span>
        ) : null,
        due !== null && task.due_day !== null ? (
            <Link
                key="due"
                href={task.due_day === today ? dashboard().url : todayRoutes.day(task.due_day).url}
                className={styles.metaLink}
                data-overdue={task.is_overdue || undefined}
            >
                {task.is_overdue ? t('{{due}}, overdue', { due }) : due}
            </Link>
        ) : null,
        task.parent_task !== null ? (
            <Link key="parent" href={tasks.show(task.parent_task.id).url} className={styles.metaLink}>
                {t('Parent: {{name}}', { name: task.parent_task.name })}
            </Link>
        ) : null,
        task.has_trello_card && task.trello_url !== null ? (
            <a key="trello" href={task.trello_url} target="_blank" rel="noopener noreferrer" className={styles.metaLink}>
                Trello ↗
            </a>
        ) : null,
    ].filter((part) => part !== null);

    return (
        <p className={styles.meta}>
            {parts.map((part, index) => (
                <Fragment key={part.key}>
                    {index > 0 && <span aria-hidden="true"> · </span>}
                    {part}
                </Fragment>
            ))}
        </p>
    );
}

/** The state the whole task is in, above its title. */
function StateChip({ task }: { task: TaskData }) {
    const { t } = useTranslation();

    if (task.is_archived) return <Chip status="open">{t('Archived')}</Chip>;
    if (task.finished_at !== null) return <Chip status="done">{t('done in the CRM')}</Chip>;
    if (task.is_done && task.has_trello_card) return <Chip status="done">{t('card completed')}</Chip>;

    return null;
}

/**
 * Figma: Task, the head tile (1094:40618, phone 1094:40855; done 1094:41505). The title, the meta line and the
 * actions; on a phone the main button takes the row and Copy for the agent moves into "more".
 */
export function TaskHeader({
    task,
    today,
    locale,
    parentOptions,
    projectOptions,
}: {
    task: TaskData;
    today: string;
    locale: string;
    parentOptions?: { id: number; name: string }[];
    projectOptions?: { id: number; name: string }[];
}) {
    const { t } = useTranslation();
    const copy = useCopyForAgent(task.id);
    const chip = StateChip({ task });

    return (
        <Tile kind="plain" as="div" className={styles.head}>
            {chip}
            <div className={styles.headText}>
                <Poster size="record">{task.name}</Poster>
                <TaskMeta task={task} today={today} locale={locale} />
            </div>
            <div className={styles.headActions}>
                <DoneAction task={task} />
                <PickToday task={task} />
                <SoloButton variant="secondary" size="control" className={styles.wideOnly} onClick={copy}>
                    {t('Copy for the agent')}
                </SoloButton>
                <TaskMenu task={task} parentOptions={parentOptions} projectOptions={projectOptions} onCopy={copy} />
            </div>
        </Tile>
    );
}

/** The task's place: its client, Tasks, then the task itself. */
export const taskPlace = (({ task }, { t }) => ({
    tokens: [
        ...(task.client
            ? [clientToken(task.client, 'tasks', t), { label: t('Tasks'), href: clients.show({ client: task.client.id, tab: 'tasks' }).url }]
            : []),
        { label: task.name },
    ],
})) satisfies PlaceResolver<{ task: TaskData }>;
