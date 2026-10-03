import { listLaneLabel } from '@/lib/list-lane-label';
import type { useTranslation } from 'react-i18next';

type TFn = ReturnType<typeof useTranslation>['t'];

/**
 * The task shape shared by the project board (kanban cards + list table).
 * The <TaskRow> list component this folder was named for is gone - list
 * views render the Leads-style table (project-board/show) instead.
 */
export interface TaskRowTask {
    id: number;
    name: string;
    description?: string | null;
    list_name: string | null;
    is_completed: boolean;
    /** The owner's tick, CRM-owned; on a Trello card the list stays Trello's. */
    finished_at?: string | null;
    archived_at?: string | null;
    due_date: string | null;
    is_overdue?: boolean;
    labels?: string[] | null;
    trello_url?: string | null;
    source?: string | null;
    /** A synced Trello card: title, description, list and archive belong to Trello. */
    has_trello_card?: boolean;
    priority?: string | null;
    recurrence_period_days?: number | null;
    parent_task_id?: number | null;
    parent_task?: { id: number; name: string } | null;
    child_tasks_count?: number;
    cli?: 'claude' | 'codex' | null;
    agent_lane?: string | null;
}

/**
 * "Finished by me {date} · card: {lane}" for a Trello card the owner has
 * ticked, so it reads as done while still showing where the card sits.
 */
export function ownerFinishLabel(
    task: { finished_at?: string | null; has_trello_card?: boolean; list_name: string | null },
    t: TFn,
    locale: string,
): string | null {
    if (!task.finished_at || !task.has_trello_card) return null;
    const date = new Date(task.finished_at).toLocaleDateString(locale, { day: 'numeric', month: 'short' });

    return t('Finished by me {{date}} · card: {{lane}}', { date, lane: listLaneLabel(t, task.list_name) });
}
