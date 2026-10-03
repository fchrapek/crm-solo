import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { FormInput, FormLabel, FormMessage } from '@/components/form';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

const NO_TASK = 'none';

export interface TaskOption {
    id: number;
    name: string;
    project_name?: string | null;
}

const readXsrfToken = (): string => {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

export interface SessionTimeEntry {
    id: number;
    start_time: string | null;
    end_time: string | null;
    title: string | null;
    description: string | null;
    task_id: number | null;
    billable: boolean;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    entry: SessionTimeEntry | null;
    /** Candidate tasks to connect this entry to. Picker hidden when omitted. */
    tasks?: TaskOption[];
    onSaved?: () => void;
}

/**
 * Edit an existing TimeEntry - typically the one linked to a session history
 * row. Server-side update() also keeps the linked TaskSession.started_at/ended_at
 * in sync, so the row above the dialog refreshes to the corrected times after
 * save.
 */
export function SessionTimeEditDialog({ open, onOpenChange, entry, tasks, onSaved }: Props) {
    const { t } = useTranslation();
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [taskId, setTaskId] = useState<number | null>(null);
    const [startTime, setStartTime] = useState('');
    const [endTime, setEndTime] = useState('');
    const [billable, setBillable] = useState(true);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (!open || entry === null) {
            return;
        }
        setTitle(entry.title ?? '');
        setDescription(entry.description ?? '');
        setTaskId(entry.task_id);
        setStartTime(entry.start_time ? isoToLocalInput(entry.start_time) : '');
        setEndTime(entry.end_time ? isoToLocalInput(entry.end_time) : '');
        setBillable(entry.billable);
        setErrors({});
    }, [open, entry]);

    if (entry === null) {
        return null;
    }

    const submit = async () => {
        setSubmitting(true);
        setErrors({});
        try {
            const res = await fetch(`/time-entries/${entry.id}`, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    title: title.trim() || null,
                    description: description || null,
                    task_id: taskId,
                    start_time: localInputToIso(startTime),
                    end_time: endTime ? localInputToIso(endTime) : null,
                    billable,
                }),
            });
            if (!res.ok) {
                const body = await res.json().catch(() => ({ message: t('Failed to save entry') }));
                if (body?.errors) {
                    const flat: Record<string, string> = {};
                    for (const [key, value] of Object.entries(body.errors as Record<string, string[]>)) {
                        flat[key] = value[0] ?? '';
                    }
                    setErrors(flat);
                } else {
                    toast.error(body.message ?? t('Failed to save entry'));
                }
                return;
            }
            toast.success(t('Time entry saved'));
            onOpenChange(false);
            onSaved?.();
        } catch {
            toast.error(t('Failed to save entry'));
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('Edit session time')}</DialogTitle>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        void submit();
                    }}
                    style={{ display: 'flex', flexDirection: 'column', gap: 'var(--spacing-4)' }}
                >
                    <div>
                        <FormLabel htmlFor="session-time-title">{t('Title')}</FormLabel>
                        <FormInput
                            id="session-time-title"
                            type="text"
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                            maxLength={255}
                            placeholder={t('Short label shown in the list')}
                            error={errors.title}
                        />
                        <FormMessage error={errors.title} />
                    </div>
                    <div>
                        <FormLabel htmlFor="session-time-description">{t('Description')}</FormLabel>
                        <Textarea
                            id="session-time-description"
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            maxLength={2000}
                            rows={4}
                            placeholder={t('Details shown when the entry is opened')}
                            aria-invalid={errors.description ? true : undefined}
                            style={{ resize: 'vertical' }}
                        />
                        <FormMessage error={errors.description} />
                    </div>
                    {tasks && tasks.length > 0 && (
                        <div>
                            <FormLabel htmlFor="session-time-task">{t('Connected task')}</FormLabel>
                            <Select
                                value={taskId === null ? NO_TASK : String(taskId)}
                                onValueChange={(v) => setTaskId(v === NO_TASK ? null : Number(v))}
                            >
                                <SelectTrigger id="session-time-task">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NO_TASK}>{t('No task')}</SelectItem>
                                    {tasks.map((task) => (
                                        <SelectItem key={task.id} value={String(task.id)}>
                                            #{task.id} · {task.name}
                                            {task.project_name ? ` (${task.project_name})` : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--spacing-3)' }}>
                        <div>
                            <FormLabel htmlFor="session-time-start">{t('Start time')}</FormLabel>
                            <FormInput
                                id="session-time-start"
                                type="datetime-local"
                                value={startTime}
                                onChange={(e) => setStartTime(e.target.value)}
                                required
                                error={errors.start_time}
                            />
                            <FormMessage error={errors.start_time} />
                        </div>
                        <div>
                            <FormLabel htmlFor="session-time-end">{t('End time')}</FormLabel>
                            <FormInput
                                id="session-time-end"
                                type="datetime-local"
                                value={endTime}
                                onChange={(e) => setEndTime(e.target.value)}
                                error={errors.end_time}
                            />
                            <FormMessage error={errors.end_time} />
                        </div>
                    </div>
                    <label style={{ display: 'flex', alignItems: 'center', gap: 'var(--spacing-2)' }}>
                        <input type="checkbox" checked={billable} onChange={(e) => setBillable(e.target.checked)} />
                        {t('Billable')}
                    </label>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? t('Saving...') : t('Save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function isoToLocalInput(iso: string): string {
    // <input type="datetime-local"> expects YYYY-MM-DDTHH:mm in *local* time.
    // Date constructor handles ISO-with-offset and converts to the user's TZ.
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function localInputToIso(value: string): string {
    if (!value) return value;
    return new Date(value).toISOString();
}
