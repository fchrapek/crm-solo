import { router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { fieldControl, SoloField, SoloFieldRow, SoloInput } from '@/components/solo/field';
import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import { Checkbox } from '@/components/ui/checkbox';
import { MarkdownTextarea } from '@/components/ui/markdown-textarea';
import { listLaneLabel } from '@/lib/list-lane-label';

import styles from './task-form-dialog.module.css';

const STATUSES = ['Backlog', 'To-Do', 'Doing', 'Testing', 'Done'] as const;
type Status = (typeof STATUSES)[number];
type Priority = '' | 'low' | 'medium' | 'high';
const NONE = 'none';

/**
 * Values the dialog edits. Agent-side fields (prompt, repo, base branch,
 * iterations, budget, browser verification, compile/reviewer config) live in
 * `<AgentBriefDialog>` - the dropdown on a promoted card opens that dialog;
 * this one stays a clean task-CRUD surface (name / description / due / etc).
 */
export interface TaskDialogValues {
    id?: number;
    name: string;
    description: string;
    list_name: Status;
    due_date: string;
    priority: Priority;
    parent_task_id?: number | null;
    /** Read-only here - shown so the user knows the "Promote" button context. */
    is_agent_ready?: boolean;
    source?: string | null;
    /** When true, this task (name + duration of its time entries) appears in
     * generated client reports. Default false - internal tracking stays
     * internal. The retainer baseline scope covers always-included activities
     * separately, without hours. */
    is_reportable?: boolean;
}

function valuesFrom(initial?: Partial<TaskDialogValues>): TaskDialogValues {
    return {
        id: initial?.id,
        name: initial?.name ?? '',
        description: initial?.description ?? '',
        list_name: initial?.list_name ?? 'To-Do',
        due_date: initial?.due_date ? initial.due_date.slice(0, 10) : '',
        priority: initial?.priority ?? '',
        parent_task_id: initial?.parent_task_id ?? null,
        is_agent_ready: initial?.is_agent_ready ?? false,
        source: initial?.source ?? null,
        is_reportable: initial?.is_reportable ?? false,
    };
}

export interface TaskParentOption {
    id: number;
    name: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId?: number;
    initial?: Partial<TaskDialogValues>;
    parentOptions?: TaskParentOption[];
    mode: 'create' | 'edit';
    /** A create from a page that spans projects picks one; with a single project there is nothing to pick. */
    projects?: { id: number; name: string }[];
    /** Below 768 px the same form opens in a Solo bottom sheet (Solo pages only). */
    sheet?: boolean;
}

/** Figma: Nowe zadanie, dialog 770:56871, sheet 747:18916. */

export function TaskFormDialog({ open, onOpenChange, projectId, initial, parentOptions = [], mode, projects = [], sheet = false }: Props) {
    const { t } = useTranslation();
    const [values, setValues] = useState(() => valuesFrom(initial));
    const [errors, setErrors] = useState<Partial<Record<keyof TaskDialogValues, string>>>({});
    const [pickedProjectId, setPickedProjectId] = useState<number | undefined>();
    const [processing, setProcessing] = useState(false);
    const targetProjectId = projectId ?? pickedProjectId ?? projects[0]?.id;

    // The draft resets when the dialog opens; fresh props while it is open, a validation response included, keep it.
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setValues(valuesFrom(initial));
            setErrors({});
            setPickedProjectId(undefined);
        }
    }

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        const payload = {
            name: values.name,
            description: values.description || null,
            list_name: values.list_name,
            due_date: values.due_date || null,
            priority: values.priority || null,
            parent_task_id: values.parent_task_id ?? null,
            is_reportable: values.is_reportable ?? false,
        };

        const onError = (errs: Record<string, string>) => {
            setErrors(errs);
            setProcessing(false);
        };

        const onSuccess = () => {
            setProcessing(false);
            onOpenChange(false);
        };

        if (mode === 'create' && targetProjectId !== undefined) {
            router.post(`/projects/${targetProjectId}/tasks`, payload, {
                preserveScroll: true,
                onSuccess,
                onError,
            });
        } else if (mode === 'edit' && values.id !== undefined) {
            router.put(`/tasks/${values.id}`, payload, {
                preserveScroll: true,
                onSuccess,
                onError,
            });
        }
    };

    const title = mode === 'create' ? t('New task') : t('Edit task');

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            onSubmit={handleSubmit}
            sheet={sheet}
            sheetAction="save"
            saving={processing}
            fields
            footerClassName={styles.actions}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Saving…') : t('Save')}
                    </SoloButton>
                </>
            )}
        >
            {mode === 'create' && projectId === undefined && projects.length > 1 && (
                <SoloField label={t('Project')} htmlFor="task-project">
                    <ResponsiveSelect
                        label={t('Project')}
                        value={String(targetProjectId)}
                        onValueChange={(v) => setPickedProjectId(Number(v))}
                        disabled={processing}
                        sheet={sheet}
                        control={fieldControl('task-project')}
                        options={projects.map((project) => ({ value: String(project.id), label: project.name }))}
                    />
                </SoloField>
            )}

            <SoloField label={t('Name')} htmlFor="task-name" error={errors.name}>
                <SoloInput
                    {...fieldControl('task-name', errors.name)}
                    type="text"
                    value={values.name}
                    onChange={(e) => setValues({ ...values, name: e.target.value })}
                    required
                    autoFocus
                    maxLength={500}
                    disabled={processing}
                />
            </SoloField>

            <SoloField label={t('Description')} htmlFor="task-description" error={errors.description}>
                <MarkdownTextarea
                    {...fieldControl('task-description', errors.description)}
                    look="solo"
                    value={values.description}
                    onChange={(value) => setValues({ ...values, description: value })}
                    rows={4}
                    disabled={processing}
                />
            </SoloField>

            <SoloFieldRow>
                <SoloField label={t('Parent task')} htmlFor="task-parent" error={errors.parent_task_id}>
                    <ResponsiveSelect
                        label={t('Parent task')}
                        value={values.parent_task_id ? String(values.parent_task_id) : NONE}
                        onValueChange={(v) => setValues({ ...values, parent_task_id: v === NONE ? null : Number(v) })}
                        disabled={processing}
                        sheet={sheet}
                        control={fieldControl('task-parent', errors.parent_task_id)}
                        options={[
                            { value: NONE, label: t('No parent') },
                            ...parentOptions
                                .filter((option) => option.id !== values.id)
                                .map((option) => ({ value: String(option.id), label: option.name })),
                        ]}
                    />
                </SoloField>

                <SoloField label={t('Status')} htmlFor="task-status" error={errors.list_name}>
                    <ResponsiveSelect
                        label={t('Status')}
                        value={values.list_name}
                        onValueChange={(v) => setValues({ ...values, list_name: v as Status })}
                        disabled={processing}
                        sheet={sheet}
                        control={fieldControl('task-status', errors.list_name)}
                        options={STATUSES.map((status) => ({ value: status, label: listLaneLabel(t, status) }))}
                    />
                </SoloField>
            </SoloFieldRow>

            <SoloFieldRow>
                <SoloField label={t('Priority')} htmlFor="task-priority" error={errors.priority}>
                    <ResponsiveSelect
                        label={t('Priority')}
                        value={values.priority || NONE}
                        onValueChange={(v) => setValues({ ...values, priority: v === NONE ? '' : (v as Priority) })}
                        disabled={processing}
                        sheet={sheet}
                        control={fieldControl('task-priority', errors.priority)}
                        options={[
                            { value: NONE, label: t('No priority set') },
                            { value: 'low', label: t('Low') },
                            { value: 'medium', label: t('Medium') },
                            { value: 'high', label: t('High') },
                        ]}
                    />
                </SoloField>

                <SoloField label={t('Due date')} htmlFor="task-due" error={errors.due_date}>
                    <SoloInput
                        {...fieldControl('task-due', errors.due_date)}
                        type="date"
                        value={values.due_date}
                        onChange={(e) => setValues({ ...values, due_date: e.target.value })}
                        disabled={processing}
                    />
                </SoloField>
            </SoloFieldRow>

            <label className={styles.reportableRow}>
                <Checkbox
                    className={styles.reportableCheck}
                    checked={values.is_reportable ?? false}
                    onCheckedChange={(checked) => setValues({ ...values, is_reportable: checked === true })}
                    disabled={processing}
                />
                <span className={styles.reportableLabel}>
                    <span className={styles.reportableTitle}>{t('Include in client report')}</span>
                    <span className={styles.reportableHint}>
                        {t(
                            'Off by default. Baseline retainer activities (monitoring, backups, updates) are covered by the baseline scope and stay off.',
                        )}
                    </span>
                </span>
            </label>
        </ResponsiveDialog>
    );
}
