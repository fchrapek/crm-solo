import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { fieldControl, SoloField, SoloFieldRow, SoloInput, SoloTextarea } from '@/components/solo/field';
import { Notice } from '@/components/solo/notice';
import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { durationText } from '@/lib/dates';
import { JsonRequestError, jsonRequest } from '@/lib/json-request';

import styles from './time-entry-edit-dialog.module.css';

const NO_TASK = 'none';

export interface TaskOption {
    id: number;
    name: string;
    project_name?: string | null;
}

export interface EditableTimeEntry {
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
    entry: EditableTimeEntry | null;
    /** Candidate tasks to connect this entry to. Picker hidden when omitted. */
    tasks?: TaskOption[];
    onSaved?: () => void;
}

/** Whole minutes between two datetime-local values, or null while they do not make a span. */
export function spanMinutes(start: string, end: string): number | null {
    const minutes = Math.round((new Date(end).getTime() - new Date(start).getTime()) / 60_000);

    return Number.isFinite(minutes) && minutes > 0 ? minutes : null;
}

/**
 * Figma: Edytuj wpis czasu (1095:47171). Title, description, task, times and billable of a logged entry, and Usuń
 * wpis; a settled period refuses both and the dialog shows the server's reason.
 */
export function TimeEntryEditDialog({ open, onOpenChange, entry, tasks, onSaved }: Props) {
    const { t } = useTranslation();
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [taskId, setTaskId] = useState<number | null>(null);
    const [startTime, setStartTime] = useState('');
    const [endTime, setEndTime] = useState('');
    const [billable, setBillable] = useState(true);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [refusal, setRefusal] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [deleting, setDeleting] = useState(false);

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
        setRefusal(null);
    }, [open, entry]);

    if (entry === null) {
        return null;
    }

    const failed = (error: unknown, fallback: string) => {
        if (error instanceof JsonRequestError && Object.keys(error.errors).length > 0) {
            setErrors(Object.fromEntries(Object.entries(error.errors).map(([key, value]) => [key, value[0] ?? ''])));
        } else {
            setRefusal(error instanceof JsonRequestError ? error.message : fallback);
        }
    };

    const submit = async () => {
        setSubmitting(true);
        setErrors({});
        setRefusal(null);
        try {
            await jsonRequest(`/time-entries/${entry.id}`, 'PUT', {
                title: title.trim() || null,
                description: description || null,
                task_id: taskId,
                start_time: localInputToIso(startTime),
                end_time: endTime ? localInputToIso(endTime) : null,
                billable,
            });
            toast.success(t('Time entry saved'));
            onOpenChange(false);
            onSaved?.();
        } catch (error) {
            failed(error, t('Failed to save entry'));
        } finally {
            setSubmitting(false);
        }
    };

    const destroy = async () => {
        setSubmitting(true);
        setRefusal(null);
        try {
            await jsonRequest(`/time-entries/${entry.id}`, 'DELETE');
            toast.success(t('Time entry deleted'));
            onOpenChange(false);
            onSaved?.();
        } catch (error) {
            failed(error, t('Failed to delete entry'));
        } finally {
            setSubmitting(false);
        }
    };

    const span = spanMinutes(startTime, endTime);

    return (
        <>
            <ResponsiveDialog
                open={open}
                onOpenChange={onOpenChange}
                title={t('Edit time entry')}
                onSubmit={(event) => {
                    event.preventDefault();
                    void submit();
                }}
                fields
                sheetAction="save"
                saving={submitting}
                footerClassName={styles.actions}
                actions={(size) => (
                    <>
                        <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={submitting}>
                            {t('Cancel')}
                        </SoloButton>
                        <SoloButton type="submit" variant="ink" size={size} disabled={submitting}>
                            {submitting ? t('Saving…') : t('Save')}
                        </SoloButton>
                    </>
                )}
            >
                {refusal !== null && (
                    <Notice kind="lock" role="alert">
                        {refusal}
                    </Notice>
                )}
                <SoloField label={t('Title')} htmlFor="time-entry-title" error={errors.title}>
                    <SoloInput
                        {...fieldControl('time-entry-title', errors.title)}
                        type="text"
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        maxLength={255}
                        placeholder={t('Short label shown in the list')}
                        disabled={submitting}
                    />
                </SoloField>
                <SoloField label={t('Description')} htmlFor="time-entry-description" error={errors.description}>
                    <SoloTextarea
                        {...fieldControl('time-entry-description', errors.description)}
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        maxLength={2000}
                        rows={3}
                        placeholder={t('Details shown when the entry is opened')}
                        disabled={submitting}
                    />
                </SoloField>
                {tasks && tasks.length > 0 && (
                    <SoloField label={t('Connected task')} htmlFor="time-entry-task" error={errors.task_id}>
                        <ResponsiveSelect
                            label={t('Connected task')}
                            value={taskId === null ? NO_TASK : String(taskId)}
                            onValueChange={(v) => setTaskId(v === NO_TASK ? null : Number(v))}
                            disabled={submitting}
                            control={fieldControl('time-entry-task', errors.task_id)}
                            options={[
                                { value: NO_TASK, label: t('No task') },
                                ...tasks.map((task) => ({
                                    value: String(task.id),
                                    label: task.project_name ? `${task.name} · ${task.project_name}` : task.name,
                                })),
                            ]}
                        />
                    </SoloField>
                )}
                <SoloFieldRow>
                    <SoloField label={t('Start time')} htmlFor="time-entry-start" error={errors.start_time}>
                        <SoloInput
                            {...fieldControl('time-entry-start', errors.start_time)}
                            type="datetime-local"
                            value={startTime}
                            onChange={(e) => setStartTime(e.target.value)}
                            required
                            disabled={submitting}
                        />
                    </SoloField>
                    <SoloField label={t('End time')} htmlFor="time-entry-end" error={errors.end_time}>
                        <SoloInput
                            {...fieldControl('time-entry-end', errors.end_time)}
                            type="datetime-local"
                            value={endTime}
                            onChange={(e) => setEndTime(e.target.value)}
                            disabled={submitting}
                        />
                        {span !== null && <span className={styles.span}>{durationText(span, t)}</span>}
                    </SoloField>
                </SoloFieldRow>
                <label className={styles.billable}>
                    <Checkbox checked={billable} onCheckedChange={(checked) => setBillable(checked === true)} disabled={submitting} />
                    {t('Billable')}
                </label>
                <div>
                    <SoloButton variant="quiet" size="control" className={styles.remove} onClick={() => setDeleting(true)} disabled={submitting}>
                        {t('Delete entry')}
                    </SoloButton>
                </div>
            </ResponsiveDialog>
            <ConfirmDialog
                open={deleting}
                onOpenChange={setDeleting}
                title={t('Delete this entry?')}
                description={t('The logged time goes with it.')}
                confirmLabel={t('Delete')}
                onConfirm={() => void destroy()}
            />
        </>
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
