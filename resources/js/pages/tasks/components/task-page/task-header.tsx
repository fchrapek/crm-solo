import { ActionMenuLink } from '@/components/solo/action-menu';
import { HeaderAction, RecordHeader, type TrailItem } from '@/components/solo/client-header';
import { Icon } from '@/components/ui/icon';
import clients from '@/routes/clients';
import day from '@/routes/day';
import projectBoard from '@/routes/project-board';
import tasks from '@/routes/tasks';
import { Link, router } from '@inertiajs/react';
import { Fragment, type ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { agentPrompt, dueText } from './format';
import styles from './task-page.module.css';
import type { TaskData } from './types';

/** "Kopiuj dla agenta": the line that sends an agent to `crm task`, then a toast. */
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

function CopyForAgent({ taskId }: { taskId: number }) {
    const { t } = useTranslation();

    async function copy() {
        if (await copyAgentPrompt(agentPrompt(taskId, t))) {
            toast.success(t('Copied'));
        } else {
            toast.error(t('Could not copy to the clipboard.'));
        }
    }

    return (
        <HeaderAction onClick={copy} icon={<Icon name="copy" size={16} />}>
            {t('Copy for the agent')}
        </HeaderAction>
    );
}

/** Figma: Zadanie · timer liczy (922:21795): one click puts the task on today; a picked task says so. */
function PickToday({ task }: { task: TaskData }) {
    const { t } = useTranslation();

    if (task.is_done) return null;
    if (task.picked_today) return <HeaderAction disabled>{t('Picked')}</HeaderAction>;

    return (
        <HeaderAction onClick={() => router.post(day.picks.store().url, { task_id: task.id }, { preserveScroll: true })}>{t('For today')}</HeaderAction>
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
            <HeaderAction
                disabled={busy}
                onClick={() => {
                    setBusy(true);
                    router.delete(day.tasks.uncomplete(task.id).url, options);
                }}
            >
                {task.has_trello_card ? t('Undo done in the CRM') : t('Mark as not done')}
            </HeaderAction>
        );
    }

    return (
        <HeaderAction
            disabled={busy}
            icon={<Icon name="check" size={16} />}
            onClick={() => {
                setBusy(true);
                router.post(day.tasks.complete(task.id).url, {}, options);
            }}
        >
            {task.has_trello_card ? t('Mark done in the CRM') : t('Mark done')}
        </HeaderAction>
    );
}

/** Figma: A2f / Task meta (823:59547): "<project> · do <due> · Trello ↗", the same line on phones; the actions from 768 px. */
export function TaskMeta({ task, today, locale }: { task: TaskData; today: string; locale: string }) {
    const { t } = useTranslation();
    const due = task.due_day !== null ? dueText(task.due_day, today, locale, t) : null;
    const parts = [
        task.project !== null ? <span key="project">{task.project.name}</span> : null,
        due !== null ? (
            <span key="due" data-overdue={task.is_overdue || undefined}>
                {due}
            </span>
        ) : null,
        task.finished_at !== null ? (
            <span key="done">{t('done in the CRM')}</span>
        ) : task.is_done && task.has_trello_card ? (
            <span key="done">{t('card completed')}</span>
        ) : null,
        task.parent_task !== null ? (
            <Link key="parent" href={tasks.show(task.parent_task.id).url} className={styles.parentLink}>
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

/** Figma: Zadanie header (770:56741): the trail down to this task; phones keep the back bar and the ••• menu (849:20996). */
export function TaskHeader({ task, today, locale, children }: { task: TaskData; today: string; locale: string; children?: ReactNode }) {
    const { t } = useTranslation();
    const client = task.client;
    const clientHref = client ? clients.show({ client: client.id, tab: 'zadania' }).url : null;
    const back = client && clientHref ? { href: clientHref, label: client.short_name } : { href: clients.index().url, label: t('Clients') };
    const trail: TrailItem[] = [
        { label: t('Clients'), href: clients.index().url },
        ...(client && clientHref ? [{ label: client.short_name, href: clientHref }] : []),
        ...(client && task.project !== null
            ? [{ label: task.project.name, href: projectBoard.show({ client: client.id, project: task.project.id }).url }]
            : []),
        { label: task.name, clip: true },
    ];

    return (
        <RecordHeader
            size="poster"
            back={back}
            trail={trail}
            title={task.name}
            menu={
                <>
                    <PickToday task={task} />
                    <CopyForAgent taskId={task.id} />
                    <DoneAction task={task} />
                    {task.trello_url !== null && task.has_trello_card && (
                        <ActionMenuLink external href={task.trello_url} icon={<span aria-hidden="true">↗</span>}>
                            {t('Card in Trello')}
                        </ActionMenuLink>
                    )}
                </>
            }
        >
            <div className={styles.metaRow}>
                <TaskMeta task={task} today={today} locale={locale} />
                <div className={styles.headerActions}>
                    <PickToday task={task} />
                    <CopyForAgent taskId={task.id} />
                    <DoneAction task={task} />
                </div>
            </div>
            {children}
        </RecordHeader>
    );
}
