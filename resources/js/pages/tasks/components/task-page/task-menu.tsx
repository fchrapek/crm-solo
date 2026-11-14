import { ActionMenu, ActionMenuItem, ActionMenuLink } from '@/components/solo/action-menu';
import { IconButton } from '@/components/solo/icon-button';
import { TileNote } from '@/components/solo/tile';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { JsonRequestError, jsonRequest } from '@/lib/json-request';
import clients from '@/routes/clients';
import tasks from '@/routes/tasks';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { TaskFormDialog, type TaskDialogValues } from '../../../clients/components/task-form-dialog';
import styles from './task-page.module.css';
import { LIVE_PROPS, type TaskData } from './types';

export type TaskMenuItemKey = 'copy' | 'trello' | 'edit' | 'archive' | 'restore' | 'delete';

/** What "more" offers: a card is Trello's, so it is not edited, archived or deleted here. */
export function taskMenuItems(task: Pick<TaskData, 'has_trello_card' | 'trello_url' | 'is_archived'>): TaskMenuItemKey[] {
    return [
        'copy',
        ...(task.has_trello_card && task.trello_url !== null ? (['trello'] as const) : []),
        ...(task.has_trello_card ? [] : (['edit', task.is_archived ? 'restore' : 'archive', 'delete'] as const)),
    ];
}

/** The server's reason when a delete is refused because the task holds settled time; null for any other failure. */
export function deleteRefusal(error: unknown): string | null {
    return error instanceof JsonRequestError ? (error.errors.archive_instead?.[0] ?? null) : null;
}

const STATUSES = ['Backlog', 'To-Do', 'Doing', 'Testing', 'Done'] as const;

export function editValues(task: TaskData): Partial<TaskDialogValues> {
    const status = STATUSES.find((name) => name === task.list_name);

    return {
        id: task.id,
        name: task.name,
        description: task.description ?? '',
        ...(status ? { list_name: status } : {}),
        due_date: task.due_day ?? '',
        priority: task.priority ?? '',
        parent_task_id: task.parent_task_id,
        is_reportable: task.is_reportable,
    };
}

/**
 * Figma: Zadanie, "more" (1094:47089) and the refused delete (1094:47408). Edytuj opens the task form; Archiwizuj and
 * Usuń ask first, and a delete the server refuses for settled time shows its reason and offers the archive instead.
 */
export function TaskMenu({
    task,
    parentOptions,
    onCopy,
}: {
    task: TaskData;
    parentOptions?: { id: number; name: string }[];
    onCopy: () => void;
}) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState(false);
    const [asking, setAsking] = useState<'archive' | 'delete' | null>(null);
    const [refusal, setRefusal] = useState<string | null>(null);
    const items = taskMenuItems(task);
    const failed = (error: unknown) => toast.error(error instanceof Error ? error.message : t('Something went wrong.'));

    const edit = () => router.reload({ only: ['parent_options'], onSuccess: () => setEditing(true) });

    async function setArchived(archived: boolean) {
        try {
            await jsonRequest(tasks.archived(task.id).url, 'PATCH', { archived });
            toast.success(archived ? t('Task archived.') : t('Task restored.'));
        } catch (error) {
            failed(error);
        }
        router.reload({ only: LIVE_PROPS });
    }

    async function destroy() {
        try {
            await jsonRequest(tasks.destroy(task.id).url, 'DELETE');
        } catch (error) {
            const reason = deleteRefusal(error);
            if (reason !== null) setRefusal(reason);
            else failed(error);

            return;
        }
        toast.success(t('Task deleted.'));
        router.visit(task.client ? clients.show({ client: task.client.id, tab: 'zadania' }).url : clients.index().url);
    }

    return (
        <>
            <ActionMenu trigger={<IconButton icon="more" look="outline" label={t('More actions')} />}>
                <ActionMenuItem className={styles.phoneItem} onSelect={onCopy}>
                    {t('Copy for the agent')}
                </ActionMenuItem>
                {items.includes('trello') && task.trello_url !== null && (
                    <ActionMenuLink external href={task.trello_url}>
                        {t('Card in Trello')} ↗
                    </ActionMenuLink>
                )}
                {items.includes('edit') && <ActionMenuItem onSelect={edit}>{t('Edit')}</ActionMenuItem>}
                {items.includes('archive') && <ActionMenuItem onSelect={() => setAsking('archive')}>{t('Archive')}</ActionMenuItem>}
                {items.includes('restore') && <ActionMenuItem onSelect={() => void setArchived(false)}>{t('Restore from the archive')}</ActionMenuItem>}
                {items.includes('delete') && <ActionMenuItem onSelect={() => setAsking('delete')}>{t('Delete')}</ActionMenuItem>}
            </ActionMenu>
            {items.includes('edit') && (
                <TaskFormDialog open={editing} onOpenChange={setEditing} mode="edit" initial={editValues(task)} parentOptions={parentOptions} sheet />
            )}
            <ConfirmDialog
                open={asking === 'archive'}
                onOpenChange={(open) => !open && setAsking(null)}
                title={t('Archive this task?')}
                description={t('An archived task leaves the lists and its time stays in the reports.')}
                confirmLabel={t('Archive')}
                onConfirm={() => void setArchived(true)}
            />
            <ConfirmDialog
                open={asking === 'delete'}
                onOpenChange={(open) => !open && setAsking(null)}
                title={t('Delete this task?')}
                description={t('The task goes for good. Its logged time stays with the client.')}
                confirmLabel={t('Delete')}
                onConfirm={() => void destroy()}
            />
            <ConfirmDialog
                open={refusal !== null}
                onOpenChange={(open) => !open && setRefusal(null)}
                title={t('The task cannot be deleted')}
                description={refusal ?? ''}
                confirmLabel={t('Archive')}
                onConfirm={() => void setArchived(true)}
            >
                <TileNote>{t('An archived task leaves the lists and its time stays in the reports.')}</TileNote>
            </ConfirmDialog>
        </>
    );
}
