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
    archived_at?: string | null;
    due_date: string | null;
    is_overdue?: boolean;
    labels?: string[] | null;
    trello_url?: string | null;
    source?: string | null;
    priority?: string | null;
    recurrence_period_days?: number | null;
    parent_task_id?: number | null;
    parent_task?: { id: number; name: string } | null;
    child_tasks_count?: number;
    cli?: 'claude' | 'codex' | null;
    agent_lane?: string | null;
}
