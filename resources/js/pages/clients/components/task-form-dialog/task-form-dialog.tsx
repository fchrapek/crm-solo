import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { FormInput, FormLabel, FormMessage } from '@/components/form';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { MarkdownTextarea } from '@/components/ui/markdown-textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

import styles from './task-form-dialog.module.css';

const STATUSES = ['Backlog', 'To-Do', 'Doing', 'Testing', 'Done'] as const;
type Status = (typeof STATUSES)[number];
type Priority = '' | 'low' | 'medium' | 'high';

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
}

export function TaskFormDialog({ open, onOpenChange, projectId, initial, parentOptions = [], mode }: Props) {
    const { t } = useTranslation();
    const [values, setValues] = useState<TaskDialogValues>({
        name: '',
        description: '',
        list_name: 'To-Do',
        due_date: '',
        priority: '',
        parent_task_id: null,
        is_agent_ready: false,
        source: null,
        is_reportable: false,
    });
    const [errors, setErrors] = useState<Partial<Record<keyof TaskDialogValues, string>>>({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open) {
            setValues({
                id: initial?.id,
                name: initial?.name ?? '',
                description: initial?.description ?? '',
                list_name: (initial?.list_name as Status) ?? 'To-Do',
                due_date: initial?.due_date ? initial.due_date.slice(0, 10) : '',
                priority: (initial?.priority as Priority) ?? '',
                parent_task_id: initial?.parent_task_id ?? null,
                is_agent_ready: initial?.is_agent_ready ?? false,
                source: initial?.source ?? null,
                is_reportable: initial?.is_reportable ?? false,
            });
            setErrors({});
        }
    }, [open, initial]);

    const handleSubmit = (e: React.FormEvent) => {
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

        if (mode === 'create' && projectId !== undefined) {
            router.post(`/projects/${projectId}/tasks`, payload, {
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

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{mode === 'create' ? t('New task') : t('Edit task')}</DialogTitle>
                </DialogHeader>

                <form onSubmit={handleSubmit} className={styles.form}>
                    <div>
                        <FormLabel htmlFor="task-name" error={errors.name}>
                            {t('Name')}
                        </FormLabel>
                        <FormInput
                            id="task-name"
                            type="text"
                            value={values.name}
                            onChange={(e) => setValues({ ...values, name: e.target.value })}
                            required
                            autoFocus
                            maxLength={500}
                            disabled={processing}
                            error={errors.name}
                        />
                        <FormMessage error={errors.name} />
                    </div>

                    <div>
                        <FormLabel htmlFor="task-description">{t('Description')}</FormLabel>
                        <MarkdownTextarea
                            id="task-description"
                            value={values.description}
                            onChange={(value) => setValues({ ...values, description: value })}
                            rows={4}
                            disabled={processing}
                        />
                        <FormMessage error={errors.description} />
                    </div>

                    <div className={styles.metaGrid}>
                        <div>
                            <FormLabel htmlFor="task-parent">{t('Parent task')}</FormLabel>
                            <Select
                                value={values.parent_task_id ? String(values.parent_task_id) : 'none'}
                                onValueChange={(v) => setValues({ ...values, parent_task_id: v === 'none' ? null : Number(v) })}
                                disabled={processing}
                            >
                                <SelectTrigger id="task-parent">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">{t('No parent')}</SelectItem>
                                    {parentOptions
                                        .filter((option) => option.id !== values.id)
                                        .map((option) => (
                                            <SelectItem key={option.id} value={String(option.id)}>
                                                {option.name}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                            <FormMessage error={errors.parent_task_id} />
                        </div>

                        <div>
                            <FormLabel htmlFor="task-status">{t('Status')}</FormLabel>
                            <Select
                                value={values.list_name}
                                onValueChange={(v) => setValues({ ...values, list_name: v as Status })}
                                disabled={processing}
                            >
                                <SelectTrigger id="task-status">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {STATUSES.map((s) => (
                                        <SelectItem key={s} value={s}>
                                            {s}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <FormMessage error={errors.list_name} />
                        </div>

                        <div>
                            <FormLabel htmlFor="task-priority">{t('Priority')}</FormLabel>
                            <Select
                                value={values.priority || 'none'}
                                onValueChange={(v) => setValues({ ...values, priority: v === 'none' ? '' : (v as Priority) })}
                                disabled={processing}
                            >
                                <SelectTrigger id="task-priority">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">{t('None')}</SelectItem>
                                    <SelectItem value="low">{t('Low')}</SelectItem>
                                    <SelectItem value="medium">{t('Medium')}</SelectItem>
                                    <SelectItem value="high">{t('High')}</SelectItem>
                                </SelectContent>
                            </Select>
                            <FormMessage error={errors.priority} />
                        </div>

                        <div>
                            <FormLabel htmlFor="task-due">{t('Due date')}</FormLabel>
                            <FormInput
                                id="task-due"
                                type="date"
                                value={values.due_date}
                                onChange={(e) => setValues({ ...values, due_date: e.target.value })}
                                disabled={processing}
                                error={errors.due_date}
                            />
                            <FormMessage error={errors.due_date} />
                        </div>
                    </div>

                    <label className={styles.reportableRow}>
                        <Checkbox
                            checked={values.is_reportable ?? false}
                            onCheckedChange={(checked) => setValues({ ...values, is_reportable: checked === true })}
                            disabled={processing}
                        />
                        <span className={styles.reportableLabel}>
                            <strong>{t('Include in client report')}</strong>
                            <span className={styles.reportableHint}>
                                {t(
                                    'Off by default. Baseline retainer activities (monitoring, backups, updates) are covered by the baseline scope and stay off.',
                                )}
                            </span>
                        </span>
                    </label>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? t('Saving...') : t('Save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
